<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Prochaines précommandes.
 *
 * @Block(
 *   id = "websource_preorder_upcoming",
 *   admin_label = @Translation("Prochaines précommandes"),
 *   category = @Translation("Websource Précommandes")
 * )
 */
class UpcomingBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected $display) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('websource_preorder.display'));
  }

  public function build() {
    $s = \Drupal::config('websource_preorder.settings');
    $items = $this->display->runningItems(max(1, (int) $s->get('accounts_max')));
    if (!$items) {
      return [];
    }
    return [
      '#theme' => 'websource_preorder_upcoming',
      '#title' => $s->get('accounts_title'),
      '#text' => $s->get('accounts_text'),
      '#items' => $items,
      '#attached' => ['library' => ['websource_preorder/front']],
      '#cache' => ['tags' => ['config:websource_preorder.settings', 'websource_preorder_rules'], 'max-age' => 300],
    ];
  }

}
