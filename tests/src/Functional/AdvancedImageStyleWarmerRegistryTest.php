<?php

namespace Drupal\Tests\advanced_image_style_warmer\Functional;

use Drupal\advanced_image_style_warmer\Registry;

/**
 * Tests registry invalidation on file source change and style flush.
 *
 * @group advanced_image_style_warmer
 */
class AdvancedImageStyleWarmerRegistryTest extends AdvancedImageStyleWarmerTestBase {

  /**
   * Changing URI/size clears registry so derivatives can be re-warmed.
   */
  public function testRegistryInvalidatesOnSourceChange(): void {
    $registry = $this->container->get('advanced_image_style_warmer.registry');
    $fid = 42;
    $registry->markWarmed($fid, 'aisw_test_immediate');
    $this->assertSame(['aisw_test_immediate'], $registry->warmedStylesForFile($fid, ['aisw_test_immediate']));
    $registry->invalidateFileIfSourceChanged($fid, 'public://new.jpg', 200, 'public://old.jpg', 100);
    $this->assertSame([], $registry->warmedStylesForFile($fid, ['aisw_test_immediate']));
  }

  /**
   * Partial image style flush clears one style for a source URI.
   */
  public function testPartialStyleFlushInvalidatesOneStyle(): void {
    $registry = $this->container->get('advanced_image_style_warmer.registry');
    $file = $this->createPermanentImageFile();
    $fid = (int) $file->id();
    $uri = $file->getFileUri();
    $registry->markWarmed($fid, 'aisw_test_immediate');
    $registry->markWarmed($fid, 'aisw_test_queue');
    $registry->invalidateStyleForSourceUri('aisw_test_immediate', $uri);
    $this->assertSame([], $registry->warmedStylesForFile($fid, ['aisw_test_immediate']));
    $this->assertSame(['aisw_test_queue'], $registry->warmedStylesForFile($fid, ['aisw_test_queue']));
  }

}
