<?php

namespace Drupal\advanced_image_style_warmer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\file\FileInterface;
use Drupal\file\Validation\FileValidatorInterface;
use Drupal\image\ImageStyleInterface;

/**
 * Core warming logic.
 *
 * Reads the per-style configuration once and dispatches each saved file to
 * (a) synchronous immediate warming, and (b) a bundled queue payload.
 */
class Warmer {

  public const QUEUE_NAME = 'advanced_image_style_warmer';

  /**
   * Cached configuration array: [style_name => ['immediate' => bool, 'queue' => bool]].
   */
  protected ?array $styleConfig = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
    protected Registry $registry,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ImageFactory $imageFactory,
    protected FileValidatorInterface $fileValidator,
  ) {}

  /**
   * Returns the configured style buckets.
   *
   * @return array{immediate: string[], queue: string[]}
   */
  public function getConfiguredStyles(): array {
    if ($this->styleConfig === NULL) {
      $this->styleConfig = $this->configFactory
        ->get('advanced_image_style_warmer.settings')
        ->get('styles') ?? [];
    }
    $immediate = [];
    $queue = [];
    foreach ($this->styleConfig as $name => $row) {
      if (!empty($row['immediate'])) {
        $immediate[] = $name;
      }
      elseif (!empty($row['queue'])) {
        $queue[] = $name;
      }
    }
    return ['immediate' => $immediate, 'queue' => $queue];
  }

  /**
   * Public entry for bulk actions and crop hooks.
   */
  public function warmFile(FileInterface $file): void {
    $this->onFileSave($file);
  }

  /**
   * Entry point from hook_entity_insert/update on the file entity.
   */
  public function onFileSave(FileInterface $file): void {
    if (!$this->isWarmableImage($file)) {
      return;
    }
    $buckets = $this->getConfiguredStyles();
    $fid = (int) $file->id();
    $uri = $file->getFileUri();

    if ($buckets['immediate']) {
      $pending = $this->registry->pendingStylesForFile($fid, $buckets['immediate']);
      if ($pending) {
        $this->generateDerivatives($uri, $pending, $fid, FALSE);
      }
    }

    if ($buckets['queue']) {
      $toQueue = $this->registry->stylesEligibleForQueue($fid, $buckets['queue']);
      if ($toQueue) {
        $this->enqueue($fid, $toQueue);
      }
    }
  }

  /**
   * MIME, permanence, and supported-extension filter — runs before any I/O.
   */
  public function isWarmableImage(FileInterface $file): bool {
    if (!$file->isPermanent()) {
      return FALSE;
    }
    $mime = (string) $file->getMimeType();
    if (!str_starts_with($mime, 'image/')) {
      return FALSE;
    }
    $extensions = implode(' ', $this->imageFactory->getSupportedExtensions());
    $validators = [
      'FileExtension' => [
        'extensions' => $extensions,
      ],
    ];
    $violations = $this->fileValidator->validate($file, $validators);
    return $violations->count() === 0;
  }

  /**
   * Pushes one bundled queue item; marks registry rows as queued to dedupe.
   */
  protected function enqueue(int $fid, array $styles): void {
    $styles = array_values($styles);
    $this->registry->markQueuedMany($fid, $styles);
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $queue->createItem(['fid' => $fid, 'styles' => $styles]);
  }

  /**
   * Generates derivatives for the given URI / styles, marking registry on success.
   *
   * @param bool $trustRegistry
   *   When TRUE, skips file_exists() (safe for queue/backfill after registry check).
   *
   * @return int
   *   Number of derivatives successfully generated.
   */
  public function generateDerivatives(string $uri, array $styleNames, int $fid, bool $trustRegistry = FALSE): int {
    if (!$styleNames) {
      return 0;
    }
    /** @var \Drupal\image\ImageStyleInterface[] $styles */
    $styles = $this->entityTypeManager->getStorage('image_style')->loadMultiple($styleNames);
    $warmed = [];
    foreach ($styles as $name => $style) {
      if ($this->generateOne($style, $uri, $trustRegistry)) {
        $warmed[] = $name;
      }
    }
    if ($warmed) {
      $this->registry->markWarmedMany($fid, $warmed);
    }
    return count($warmed);
  }

  /**
   * Builds one derivative.
   */
  protected function generateOne(ImageStyleInterface $style, string $uri, bool $trustRegistry): bool {
    try {
      $derivativeUri = $style->buildUri($uri);
      if (!$trustRegistry && file_exists($derivativeUri)) {
        return TRUE;
      }
      return (bool) $style->createDerivative($uri, $derivativeUri);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('advanced_image_style_warmer')->error(
        'Failed to generate derivative for @uri / @style: @msg',
        ['@uri' => $uri, '@style' => $style->id(), '@msg' => $e->getMessage()],
      );
      return FALSE;
    }
  }

}
