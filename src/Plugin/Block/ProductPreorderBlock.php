<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bloc précommande (fiche produit).
 *
 * @Block(
 *   id = "websource_preorder_product",
 *   admin_label = @Translation("Bloc précommande (fiche produit)"),
 *   category = @Translation("Websource Précommandes")
 * )
 */
class ProductPreorderBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected $display) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('websource_preorder.display'));
  }

  public function build() {
    $product = \Drupal::routeMatch()->getParameter('commerce_product');
    if (!$product instanceof \Drupal\commerce_product\Entity\ProductInterface) {
      return [];
    }
    $rule = \Drupal::service('websource_preorder.rule_manager')->getRuleForProduct((int) $product->id(), array_map(fn ($v) => (int) $v->id(), $product->getVariations()));
    if (!$rule) {
      return [];
    }
    $box = $this->display->box($rule);
    if ($box) {
      $box['#cache']['contexts'][] = 'route';
    }
    return $box;
  }

}
