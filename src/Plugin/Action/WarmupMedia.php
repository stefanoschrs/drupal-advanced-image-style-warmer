<?php

namespace Drupal\advanced_image_style_warmer\Plugin\Action;

use Drupal\advanced_image_style_warmer\Warmer;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Warms configured image styles for image fields on media entities.
 *
 * @Action(
 *   id = "advanced_image_style_warmer_warmup_media",
 *   label = @Translation("Warm image styles for media"),
 *   type = "media",
 *   confirm = TRUE,
 * )
 */
class WarmupMedia extends ActionBase implements ContainerFactoryPluginInterface {

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
    foreach ($entity->getFieldDefinitions() as $definition) {
      if ($definition->getType() !== 'image' || $entity->get($definition->getName())->isEmpty()) {
        continue;
      }
      foreach ($entity->get($definition->getName())->referencedEntities() as $file) {
        $this->warmer->warmFile($file);
      }
    }
  }

  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('edit', $account, $return_as_object);
  }

}
