<?php

namespace Drupal\advanced_image_style_warmer\Plugin\Action;

use Drupal\advanced_image_style_warmer\Warmer;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Warms configured image styles for selected files.
 *
 * @Action(
 *   id = "advanced_image_style_warmer_warmup_file",
 *   label = @Translation("Warm image styles for files"),
 *   type = "file",
 *   confirm = TRUE,
 * )
 */
class WarmupFile extends ActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected Warmer $warmer,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('advanced_image_style_warmer.warmer'),
    );
  }

  public function execute($entity = NULL) {
    if ($entity instanceof FileInterface) {
      $this->warmer->warmFile($entity);
    }
  }

  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('view', $account, $return_as_object);
  }

}
