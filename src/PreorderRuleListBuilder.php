<?php

declare(strict_types=1);

namespace Drupal\websource_preorder;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Liste des règles de précommande, avec statut et action « Disponible maintenant ».
 */
class PreorderRuleListBuilder extends ConfigEntityListBuilder {

  public function buildHeader() {
    return [
      'label' => $this->t('Règle'),
      'target' => $this->t('Cible'),
      'status' => $this->t('Statut'),
      'release' => $this->t('Sortie'),
      'stock' => $this->t('Réservé / quota'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity) {
    $rules = \Drupal::service('websource_preorder.rule_manager');
    $display = \Drupal::service('websource_preorder.display');
    $res = \Drupal::service('websource_preorder.reservation_manager');
    $product = $rules->getProduct($entity);
    [$reserved, $quota] = $res->getStock($entity);
    $row = [
      'label' => $entity->label(),
      'target' => $product ? $product->label() . ($entity->getTargetType() === 'variation' ? ' (' . $this->t('variation') . ')' : '') : $this->t('(introuvable)'),
      'status' => $display->statusLabel($rules->getStatus($entity)),
      'release' => $entity->getDateRelease() ? \Drupal::service('date.formatter')->format($entity->getDateRelease(), 'short') : '-',
      'stock' => $reserved . ' / ' . ($quota > 0 ? $quota : '∞'),
    ];
    return $row + parent::buildRow($entity);
  }

  public function getDefaultOperations(EntityInterface $entity) {
    $ops = parent::getDefaultOperations($entity);
    $rules = \Drupal::service('websource_preorder.rule_manager');
    if (in_array($rules->getStatus($entity), ['running', 'scheduled', 'disabled'], TRUE)) {
      $ops['release'] = [
        'title' => $this->t('Disponible maintenant'),
        'weight' => 5,
        'url' => Url::fromRoute('websource_preorder.rule_release', ['websource_preorder_rule' => $entity->id()]),
      ];
    }
    return $ops;
  }

}
