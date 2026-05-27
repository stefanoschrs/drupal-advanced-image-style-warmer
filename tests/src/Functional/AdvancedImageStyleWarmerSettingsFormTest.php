<?php

namespace Drupal\Tests\advanced_image_style_warmer\Functional;

/**
 * Tests the settings form validation and persistence.
 *
 * @group advanced_image_style_warmer
 */
class AdvancedImageStyleWarmerSettingsFormTest extends AdvancedImageStyleWarmerTestBase {

  /**
   * Mutual exclusion between Immediate and Queue is enforced.
   */
  public function testMutualExclusionValidation(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/advanced-image-styles-warmer');
    $edit = [
      'styles[aisw_test_immediate][immediate]' => TRUE,
      'styles[aisw_test_immediate][queue]' => TRUE,
    ];
    $this->submitForm($edit, 'Save configuration');
    $this->assertSession()->pageTextContains('cannot be both Immediate and Queue');
  }

  /**
   * Valid configuration is saved.
   */
  public function testSaveConfiguration(): void {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/media/advanced-image-styles-warmer');
    $edit = [
      'styles[aisw_test_immediate][immediate]' => TRUE,
      'styles[aisw_test_queue][queue]' => TRUE,
    ];
    $this->submitForm($edit, 'Save configuration');
    $styles = $this->config('advanced_image_style_warmer.settings')->get('styles');
    $this->assertTrue($styles['aisw_test_immediate']['immediate']);
    $this->assertTrue($styles['aisw_test_queue']['queue']);
  }

}
