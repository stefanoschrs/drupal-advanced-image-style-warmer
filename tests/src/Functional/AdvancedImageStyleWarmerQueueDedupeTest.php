<?php

namespace Drupal\Tests\advanced_image_style_warmer\Functional;

use Drupal\Core\Database\Database;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\advanced_image_style_warmer\Warmer;

/**
 * Ensures duplicate queue items are not created while a payload is pending.
 *
 * @group advanced_image_style_warmer
 */
class AdvancedImageStyleWarmerQueueDedupeTest extends AdvancedImageStyleWarmerTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->applyWarmerConfiguration();
  }

  /**
   * Second warm on the same file does not enqueue again before cron runs.
   */
  public function testDuplicateEnqueueSuppressed(): void {
    $queue = new DatabaseQueue(Warmer::QUEUE_NAME, Database::getConnection());
    $file = $this->createPermanentImageFile();
    $this->assertSame(1, $queue->numberOfItems());
    $this->container->get('advanced_image_style_warmer.warmer')->warmFile($file);
    $this->assertSame(1, $queue->numberOfItems());
  }

}
