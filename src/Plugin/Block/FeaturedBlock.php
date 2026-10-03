<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Précommande à la une.
 *
 * @Block(
 *   id = "websource_preorder_featured",
 *   admin_label = @Translation("Précommande à la une"),
 *   category = @Translation("Websource Précommandes")
 * )
 */
class FeaturedBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected $display) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('websource_preorder.display'));
  }

  public function build() {
    return $this->display->featured();
  }

}
