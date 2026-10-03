<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Availability;

use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_order\AvailabilityCheckerInterface;
use Drupal\commerce_order\AvailabilityManagerInterface;
use Drupal\commerce_order\AvailabilityResult;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\websource_preorder\Service\RuleManager;

/**
 * Décore le gestionnaire de disponibilité de Commerce.
 *
 * Tant qu'une précommande est EN COURS pour un article, les résultats
 * « indisponible » des autres vérificateurs (stock, etc.) sont ignorés : seule
 * la logique de précommande (quota, limites) décide. Rien n'est modifié sur le
 * produit ni sur le stock : à la clôture, le comportement d'origine revient
 * donc exactement tel quel.
 *
 * Signatures souples pour rester compatible Commerce 2.x et 3.x.
 */
class AvailabilityManagerDecorator implements AvailabilityManagerInterface {

  public function __construct(
    protected $inner,
    protected PreorderAvailabilityChecker $checker,
    protected RuleManager $ruleManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function addChecker(AvailabilityCheckerInterface $checker) {
    $this->inner->addChecker($checker);
  }

  /**
   * {@inheritdoc}
   */
  public function check($item, $second = NULL, $third = NULL): AvailabilityResult {
    $entity = $item instanceof OrderItemInterface ? $item->getPurchasedEntity() : $item;
    if ($entity instanceof PurchasableEntityInterface) {
      if ($this->ruleManager->getRunningRule($entity)) {
        return $this->checker->check($item, $second, $third);
      }
      // Produit hors précommande : interdiction éventuelle du mélange.
      $is_new = !($item instanceof OrderItemInterface) || !$item->getOrderId();
      if ($is_new) {
        $context = $item instanceof OrderItemInterface ? $second : $third;
        $customer = is_object($context) && method_exists($context, 'getCustomer') ? $context->getCustomer() : \Drupal::currentUser();
        $mixed = $this->checker->checkMixedForRegular($entity, $customer);
        if ($mixed && $mixed->isUnavailable()) {
          return $mixed;
        }
      }
    }
    return $this->inner->check($item, $second, $third);
  }

}
