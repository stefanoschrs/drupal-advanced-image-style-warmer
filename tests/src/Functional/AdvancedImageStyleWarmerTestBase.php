<?php

namespace Drupal\Tests\advanced_image_style_warmer\Functional;

use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\TestFileCreationTrait;

/**
 * Base class for Advanced Image Style Warmer functional tests.
 *
 * @group advanced_image_style_warmer
 */
abstract class AdvancedImageStyleWarmerTestBase extends BrowserTestBase {

  use TestFileCreationTrait {
    getTestFiles as drupalGetTestFiles;
  }

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file', 'image', 'advanced_image_style_warmer'];

  protected $adminUser;

  protected $testImmediateStyle;

  protected $testQueueStyle;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser(['administer image styles']);
    $this->testImmediateStyle = ImageStyle::create([
      'name' => 'aisw_test_immediate',
      'label' => 'AISW test immediate',
    ]);
    $this->testImmediateStyle->save();
    $this->testQueueStyle = ImageStyle::create([
      'name' => 'aisw_test_queue',
      'label' => 'AISW test queue',
    ]);
    $this->testQueueStyle->save();
  }

  /**
   * Applies standard module configuration for tests.
   */
  protected function applyWarmerConfiguration(): void {
    $this->config('advanced_image_style_warmer.settings')
      ->set('styles', [
        'aisw_test_immediate' => ['immediate' => TRUE, 'queue' => FALSE],
        'aisw_test_queue' => ['immediate' => FALSE, 'queue' => TRUE],
      ])
      ->save();
  }

  /**
   * Creates a permanent image file entity from a core test image.
   */
  protected function createPermanentImageFile(): File {
    $file = current($this->drupalGetTestFiles('image'));
    $file->filesize = filesize($file->uri);
    $entity = File::create((array) $file);
    $entity->setPermanent();
    $entity->save();
    return $entity;
  }

}
