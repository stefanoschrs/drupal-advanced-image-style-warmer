<?php

namespace Drupal\advanced_image_style_warmer\Plugin\QueueWorker;

use Drupal\advanced_image_style_warmer\Registry;
use Drupal\advanced_image_style_warmer\Warmer;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes bundled queue payloads created by the Warmer service.
 *
 * Each item is shaped: { "fid": int, "styles": ["thumbnail_medium", ...] }.
 *
 * @QueueWorker(
 *   id = "advanced_image_style_warmer",
 *   title = @Translation("Advanced Image Style Warmer"),
 *   cron = {"time" = 60}
 * )
 */
class AdvancedImageStyleWarmerWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected Warmer $warmer,
    protected Registry $registry,
    protected Connection $database,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('advanced_image_style_warmer.warmer'),
      $container->get('advanced_image_style_warmer.registry'),
      $container->get('database'),
    );
  }

  public function processItem($data) {
    if (!is_array($data) || empty($data['fid']) || empty($data['styles'])) {
      return;
    }
    $fid = (int) $data['fid'];
    $styles = array_values((array) $data['styles']);

    $this->registry->clearQueuedMany($fid, $styles);

    $pending = $this->registry->pendingStylesForFile($fid, $styles);
    if (!$pending) {
      return;
    }

    $uri = $this->database->select('file_managed', 'f')
      ->fields('f', ['uri'])
      ->condition('fid', $fid)
      ->condition('status', 1)
      ->execute()
      ->fetchField();
    if (!$uri) {
      return;
    }

    $this->warmer->generateDerivatives($uri, $pending, $fid, TRUE);
  }

}
