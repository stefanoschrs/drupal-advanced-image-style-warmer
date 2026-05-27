<?php

namespace Drupal\Tests\advanced_image_style_warmer\Functional;

use Drupal\Core\Database\Database;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\advanced_image_style_warmer\Warmer;
use Drupal\Tests\Traits\Core\CronRunTrait;

/**
 * Tests immediate warming, queue processing, and registry behaviour.
 *
 * @group advanced_image_style_warmer
 */
class AdvancedImageStyleWarmerGeneralTest extends AdvancedImageStyleWarmerTestBase {

  use CronRunTrait;

  /**
   * Queue under test.
   *
   * @var \Drupal\Core\Queue\DatabaseQueue
   */
  protected $queue;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->applyWarmerConfiguration();
    $this->queue = new DatabaseQueue(Warmer::QUEUE_NAME, Database::getConnection());
  }

  /**
   * Permanent image save warms immediate style and enqueues the queue style.
   */
  public function testPermanentFileSaveWarmsImmediateAndEnqueuesQueue(): void {
    $file = $this->createPermanentImageFile();
    $this->assertTrue(file_exists($this->testImmediateStyle->buildUri($file->getFileUri())));
    $this->assertFalse(file_exists($this->testQueueStyle->buildUri($file->getFileUri())));
    $this->assertSame(1, $this->queue->numberOfItems());
    $this->cronRun();
    $this->assertSame(0, $this->queue->numberOfItems());
    $this->assertTrue(file_exists($this->testQueueStyle->buildUri($file->getFileUri())));
  }

  /**
   * Temporary files are not warmed or queued.
   */
  public function testTemporaryFileIsIgnored(): void {
    $file = current($this->drupalGetTestFiles('image'));
    $file->filesize = filesize($file->uri);
    $entity = File::create((array) $file);
    $entity->setTemporary();
    $entity->save();
    $this->assertSame(0, $this->queue->numberOfItems());
    $this->assertFalse(file_exists($this->testImmediateStyle->buildUri($entity->getFileUri())));
  }

}
