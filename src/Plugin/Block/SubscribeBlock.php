<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Formulaire d'alerte e-mail précommandes.
 *
 * @Block(
 *   id = "websource_preorder_subscribe",
 *   admin_label = @Translation("Alerte précommandes (formulaire d'inscription)"),
 *   category = @Translation("Websource Précommandes")
 * )
 */
class SubscribeBlock extends BlockBase {

  public function build() {
    if (!\Drupal::config('websource_preorder.settings')->get('alerts_enabled')) {
      return [];
    }
    return \Drupal::formBuilder()->getForm('Drupal\websource_preorder\Form\SubscribeForm');
  }

  public function getCacheTags() {
    return ['config:websource_preorder.settings'];
  }

}
