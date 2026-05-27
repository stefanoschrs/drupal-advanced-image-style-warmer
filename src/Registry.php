<?php

namespace Drupal\advanced_image_style_warmer;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Stateful tracking registry for warmed image-style derivatives.
 *
 * Avoids round-trips to remote object stores by holding the warmed/pending
 * state of each (fid, style_name) tuple in a local indexed table.
 */
class Registry {

  public const TABLE = 'advanced_image_style_warmer_registry';

  /**
   * Seconds after which an in-queue flag is treated as stale (re-enqueue allowed).
   */
  public const QUEUE_STALE_SECONDS = 3600;

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Returns the set of style names already warmed for the given file.
   *
   * @return string[]
   *   Style machine names currently marked warmed.
   */
  public function warmedStylesForFile(int $fid, array $styleNames): array {
    if (!$styleNames) {
      return [];
    }
    return $this->database->select(self::TABLE, 'r')
      ->fields('r', ['style_name'])
      ->condition('fid', $fid)
      ->condition('style_name', $styleNames, 'IN')
      ->condition('warmed', 1)
      ->execute()
      ->fetchCol() ?: [];
  }

  /**
   * Returns the subset of styles still pending (not yet warmed) for a file.
   *
   * @return string[]
   */
  public function pendingStylesForFile(int $fid, array $styleNames): array {
    if (!$styleNames) {
      return [];
    }
    $warmed = $this->warmedStylesForFile($fid, $styleNames);
    return array_values(array_diff($styleNames, $warmed));
  }

  /**
   * Returns styles that are not warmed and not already waiting in the queue.
   *
   * @return string[]
   */
  public function stylesEligibleForQueue(int $fid, array $styleNames): array {
    $pending = $this->pendingStylesForFile($fid, $styleNames);
    if (!$pending) {
      return [];
    }
    return $this->filterNotActivelyQueued($fid, $pending);
  }

  /**
   * Marks one (fid, style) pair as warmed and clears queue state.
   */
  public function markWarmed(int $fid, string $styleName): void {
    $this->database->merge(self::TABLE)
      ->keys(['fid' => $fid, 'style_name' => $styleName])
      ->fields([
        'warmed' => 1,
        'in_queue' => 0,
        'timestamp' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Bulk-marks many style names as warmed for a single file.
   */
  public function markWarmedMany(int $fid, array $styleNames): void {
    if (!$styleNames) {
      return;
    }
    $ts = $this->time->getRequestTime();
    $tx = $this->database->startTransaction();
    try {
      foreach ($styleNames as $name) {
        $this->database->merge(self::TABLE)
          ->keys(['fid' => $fid, 'style_name' => $name])
          ->fields(['warmed' => 1, 'in_queue' => 0, 'timestamp' => $ts])
          ->execute();
      }
    }
    catch (\Throwable $e) {
      $tx->rollBack();
      throw $e;
    }
  }

  /**
   * Marks styles as queued so duplicate queue items are not created.
   */
  public function markQueuedMany(int $fid, array $styleNames): void {
    if (!$styleNames) {
      return;
    }
    $ts = $this->time->getRequestTime();
    foreach ($styleNames as $name) {
      $this->database->merge(self::TABLE)
        ->keys(['fid' => $fid, 'style_name' => $name])
        ->fields([
          'warmed' => 0,
          'in_queue' => 1,
          'timestamp' => $ts,
        ])
        ->execute();
    }
  }

  /**
   * Clears in-queue flags before processing a queue payload.
   */
  public function clearQueuedMany(int $fid, array $styleNames): void {
    if (!$styleNames) {
      return;
    }
    $this->database->update(self::TABLE)
      ->fields(['in_queue' => 0])
      ->condition('fid', $fid)
      ->condition('style_name', $styleNames, 'IN')
      ->execute();
  }

  /**
   * Drops all registry rows for a given style (e.g. on full flush/delete).
   */
  public function invalidateStyle(string $styleName): void {
    $this->database->delete(self::TABLE)
      ->condition('style_name', $styleName)
      ->execute();
  }

  /**
   * Drops registry rows for one style and one source file URI.
   *
   * Used when hook_image_style_flush() passes original file path(s).
   */
  public function invalidateStyleForSourceUri(string $styleName, string $sourceUri): void {
    $fid = $this->fidForUri($sourceUri);
    if ($fid === NULL) {
      return;
    }
    $this->database->delete(self::TABLE)
      ->condition('fid', $fid)
      ->condition('style_name', $styleName)
      ->execute();
  }

  /**
   * Drops all registry rows for a given file (e.g. on file delete).
   */
  public function invalidateFile(int $fid): void {
    $this->database->delete(self::TABLE)
      ->condition('fid', $fid)
      ->execute();
  }

  /**
   * Invalidates registry when the underlying file source changed.
   */
  public function invalidateFileIfSourceChanged(int $fid, string $newUri, int $newSize, string $oldUri, int $oldSize): void {
    if ($newUri !== $oldUri || $newSize !== $oldSize) {
      $this->invalidateFile($fid);
    }
  }

  /**
   * @return int[]
   */
  protected function filterNotActivelyQueued(int $fid, array $styleNames): array {
    $staleBefore = $this->time->getRequestTime() - self::QUEUE_STALE_SECONDS;
    $queued = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['style_name'])
      ->condition('fid', $fid)
      ->condition('style_name', $styleNames, 'IN')
      ->condition('in_queue', 1)
      ->condition('warmed', 0)
      ->condition('timestamp', $staleBefore, '>=')
      ->execute()
      ->fetchCol() ?: [];
    return array_values(array_diff($styleNames, $queued));
  }

  /**
   * Resolves a file ID from a managed file URI.
   */
  protected function fidForUri(string $uri): ?int {
    $fid = $this->database->select('file_managed', 'f')
      ->fields('f', ['fid'])
      ->condition('uri', $uri)
      ->condition('status', 1)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $fid !== FALSE ? (int) $fid : NULL;
  }

}
