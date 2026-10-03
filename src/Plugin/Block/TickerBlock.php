<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bandeau défilant des précommandes.
 *
 * @Block(
 *   id = "websource_preorder_ticker",
 *   admin_label = @Translation("Bandeau défilant des précommandes"),
 *   category = @Translation("Websource Précommandes")
 * )
 */
class TickerBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected $display) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('websource_preorder.display'));
  }

  public function build() {
    return $this->display->ticker();
  }

}
