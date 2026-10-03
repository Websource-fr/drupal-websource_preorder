<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\PriceResolver;

use Drupal\commerce\Context;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_price\Resolver\PriceResolverInterface;
use Drupal\websource_preorder\Service\RuleManager;

/**
 * Applique le prix de précommande sans jamais modifier le produit.
 */
class PreorderPriceResolver implements PriceResolverInterface {

  public function __construct(protected RuleManager $ruleManager) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(PurchasableEntityInterface $entity, $quantity, Context $context) {
    $rule = $this->ruleManager->getRunningRule($entity);
    if (!$rule || $rule->getPriceMode() === 'none') {
      return NULL;
    }
    $base = $entity->getPrice();
    if (!$base) {
      return NULL;
    }
    return $this->ruleManager->computePrice($rule, $base);
  }

}
