<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Service;

use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\websource_preorder\Entity\PreorderRule;

/**
 * Logique métier des règles : état, prix, résolution produit/variation.
 */
class RuleManager {

  const STATUS_DISABLED = 'disabled';
  const STATUS_SCHEDULED = 'scheduled';
  const STATUS_RUNNING = 'running';
  const STATUS_ENDED = 'ended';

  /** @var \Drupal\websource_preorder\Entity\PreorderRule[]|null */
  protected ?array $rules = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected Connection $database,
    protected CacheTagsInvalidatorInterface $tagsInvalidator,
  ) {}

  /**
   * Vide le cache statique des règles.
   */
  public function reset(): void {
    $this->rules = NULL;
    $this->tagsInvalidator->invalidateTags(['websource_preorder_rules']);
  }

  /**
   * Toutes les règles, indexées par identifiant.
   *
   * @return \Drupal\websource_preorder\Entity\PreorderRule[]
   */
  public function getRules(): array {
    if ($this->rules === NULL) {
      $this->rules = $this->entityTypeManager->getStorage('websource_preorder_rule')->loadMultiple();
    }
    return $this->rules;
  }

  /**
   * Fin effective des ventes (timestamp) ou NULL = illimitée.
   */
  public function getEffectiveEnd(PreorderRule $rule): ?int {
    $ends = [];
    if ($rule->getDateEnd()) {
      $ends[] = $rule->getDateEnd();
    }
    if ($rule->endsOnRelease() && $rule->getDateRelease()) {
      $ends[] = $rule->getDateRelease();
    }
    return $ends ? min($ends) : NULL;
  }

  /**
   * Statut d'une règle à un instant donné.
   */
  public function getStatus(PreorderRule $rule, ?int $now = NULL): string {
    $now = $now ?? $this->time->getRequestTime();
    $end = $this->getEffectiveEnd($rule);
    if ($end !== NULL && $now >= $end) {
      return self::STATUS_ENDED;
    }
    if (!$rule->isActive()) {
      return self::STATUS_DISABLED;
    }
    $start = $rule->getDateStart();
    if ($start !== NULL && $now < $start) {
      return self::STATUS_SCHEDULED;
    }
    return self::STATUS_RUNNING;
  }

  public function isRunning(PreorderRule $rule): bool {
    return $this->getStatus($rule) === self::STATUS_RUNNING;
  }

  /**
   * Règle applicable à une entité achetable (variation, sinon produit).
   */
  public function getRuleForPurchasable(PurchasableEntityInterface $entity): ?PreorderRule {
    $found = NULL;
    foreach ($this->getRules() as $rule) {
      if ($rule->getTargetType() === 'variation' && $rule->getTargetId() === (int) $entity->id()) {
        return $rule;
      }
      if ($rule->getTargetType() === 'product' && method_exists($entity, 'getProductId')
        && $rule->getTargetId() === (int) $entity->getProductId()) {
        $found = $found ?: $rule;
      }
    }
    return $found;
  }

  /**
   * Règle EN COURS pour une entité achetable, sinon NULL.
   */
  public function getRunningRule(PurchasableEntityInterface $entity): ?PreorderRule {
    $rule = $this->getRuleForPurchasable($entity);
    return ($rule && $this->isRunning($rule)) ? $rule : NULL;
  }

  /**
   * Règle la plus pertinente pour un produit (en cours > programmée).
   */
  public function getRuleForProduct(int $product_id, array $variation_ids = []): ?PreorderRule {
    $best = NULL;
    $rank = [self::STATUS_RUNNING => 3, self::STATUS_SCHEDULED => 2, self::STATUS_ENDED => 1, self::STATUS_DISABLED => 0];
    foreach ($this->getRules() as $rule) {
      $match = ($rule->getTargetType() === 'product' && $rule->getTargetId() === $product_id)
        || ($rule->getTargetType() === 'variation' && in_array($rule->getTargetId(), $variation_ids, TRUE));
      if (!$match) {
        continue;
      }
      if (!$best || $rank[$this->getStatus($rule)] > $rank[$this->getStatus($best)]) {
        $best = $rule;
      }
    }
    return $best;
  }

  /**
   * Règles en cours.
   *
   * @return \Drupal\websource_preorder\Entity\PreorderRule[]
   */
  public function getRunningRules(): array {
    $out = array_filter($this->getRules(), fn (PreorderRule $r) => $this->isRunning($r));
    uasort($out, fn ($a, $b) => ($a->getDateRelease() ?: PHP_INT_MAX) <=> ($b->getDateRelease() ?: PHP_INT_MAX));
    return $out;
  }

  /**
   * Charge le produit (entité) ciblé par une règle, ou NULL.
   */
  public function getProduct(PreorderRule $rule) {
    if ($rule->getTargetType() === 'product') {
      return $this->entityTypeManager->getStorage('commerce_product')->load($rule->getTargetId());
    }
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($rule->getTargetId());
    return $variation ? $variation->getProduct() : NULL;
  }

  /**
   * Variations ciblées par la règle.
   */
  public function getVariations(PreorderRule $rule): array {
    if ($rule->getTargetType() === 'variation') {
      $v = $this->entityTypeManager->getStorage('commerce_product_variation')->load($rule->getTargetId());
      return $v ? [$v] : [];
    }
    $product = $this->entityTypeManager->getStorage('commerce_product')->load($rule->getTargetId());
    return $product ? $product->getVariations() : [];
  }

  /**
   * Calcule le prix de précommande à partir du prix de base.
   */
  public function computePrice(PreorderRule $rule, Price $base): ?Price {
    $mode = $rule->getPriceMode();
    $value = $rule->getPriceValue();
    if ($mode === 'none' || $value === '' || !is_numeric($value)) {
      return NULL;
    }
    switch ($mode) {
      case 'fixed':
        $new = new Price((string) $value, $base->getCurrencyCode());
        break;

      case 'percent':
        $pct = max(0, min(100, (float) $value));
        $new = $base->multiply((string) ((100 - $pct) / 100));
        break;

      case 'amount':
        $new = $base->subtract(new Price((string) $value, $base->getCurrencyCode()));
        break;

      default:
        return NULL;
    }
    if ($new->isNegative()) {
      $new = new Price('0', $base->getCurrencyCode());
    }
    return $new;
  }

}
