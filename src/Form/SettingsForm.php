<?php

namespace Drupal\advanced_image_style_warmer\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form: per-image-style Immediate / Queue grid.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    $config_factory,
    protected EntityTypeManagerInterface $entityTypeManager,
    $typed_config_manager = NULL,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->has('config.typed') ? $container->get('config.typed') : NULL,
    );
  }

  public function getFormId() {
    return 'advanced_image_style_warmer_settings_form';
  }

  protected function getEditableConfigNames() {
    return ['advanced_image_style_warmer.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('advanced_image_style_warmer.settings');
    $existing = $config->get('styles') ?? [];

    /** @var \Drupal\image\ImageStyleInterface[] $styles */
    $styles = $this->entityTypeManager->getStorage('image_style')->loadMultiple();
    ksort($styles);

    $form['help'] = [
      '#markup' => '<p>' . $this->t('For each image style choose <strong>Immediate</strong> (warm synchronously on file save) or <strong>Queue</strong> (warm in the background via cron). A style cannot be both.') . '</p>',
    ];

    $form['styles'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Image style'),
        $this->t('Machine name'),
        $this->t('Immediate'),
        $this->t('Queue'),
      ],
      '#empty' => $this->t('No image styles are defined on this site.'),
      '#tree' => TRUE,
    ];

    foreach ($styles as $name => $style) {
      $row = $existing[$name] ?? ['immediate' => FALSE, 'queue' => FALSE];
      $form['styles'][$name]['label'] = ['#markup' => $style->label()];
      $form['styles'][$name]['name'] = ['#markup' => '<code>' . $name . '</code>'];
      $form['styles'][$name]['immediate'] = [
        '#type' => 'checkbox',
        '#default_value' => !empty($row['immediate']),
        '#title' => $this->t('Immediate'),
        '#title_display' => 'invisible',
      ];
      $form['styles'][$name]['queue'] = [
        '#type' => 'checkbox',
        '#default_value' => !empty($row['queue']),
        '#title' => $this->t('Queue'),
        '#title_display' => 'invisible',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    foreach ((array) $form_state->getValue('styles') as $name => $row) {
      if (!empty($row['immediate']) && !empty($row['queue'])) {
        $form_state->setError(
          $form['styles'][$name]['queue'],
          $this->t('Style %name cannot be both Immediate and Queue — pick one.', ['%name' => $name]),
        );
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $clean = [];
    foreach ((array) $form_state->getValue('styles') as $name => $row) {
      $immediate = !empty($row['immediate']);
      $queue = !empty($row['queue']);
      if ($immediate || $queue) {
        $clean[$name] = ['immediate' => $immediate, 'queue' => $queue];
      }
    }
    $this->config('advanced_image_style_warmer.settings')
      ->set('styles', $clean)
      ->save();
    parent::submitForm($form, $form_state);
  }

}
