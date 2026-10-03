<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Availability;

use Drupal\commerce\Context;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\AvailabilityCheckerInterface;
use Drupal\commerce_order\AvailabilityResult;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\websource_preorder\Service\RuleManager;
use Drupal\websource_preorder\Service\ReservationManager;

/**
 * Applique quota, maximum par commande/client et interdiction du mélange.
 *
 * Les signatures de applies()/check() sont volontairement souples afin de
 * rester compatibles avec Commerce 3.x (article de commande) et 2.x (entité
 * achetable + quantité).
 */
class PreorderAvailabilityChecker implements AvailabilityCheckerInterface {

  use StringTranslationTrait;

  public function __construct(
    protected RuleManager $ruleManager,
    protected ReservationManager $reservations,
    protected ConfigFactoryInterface $configFactory,
    protected CartProviderInterface $cartProvider,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($item) {
    $entity = $this->entityOf($item);
    return $entity && $this->ruleManager->getRunningRule($entity) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function check($item, $second = NULL, $third = NULL) {
    $entity = $this->entityOf($item);
    if (!$entity) {
      return AvailabilityResult::neutral();
    }
    if ($item instanceof OrderItemInterface) {
      $quantity = (float) $item->getQuantity();
      $context = $second;
      $order_item = $item;
    }
    else {
      $quantity = (float) $second;
      $context = $third;
      $order_item = NULL;
    }
    return $this->evaluate($entity, (int) ceil($quantity), $context, $order_item);
  }

  protected function entityOf($item): ?PurchasableEntityInterface {
    if ($item instanceof OrderItemInterface) {
      $e = $item->getPurchasedEntity();
      return $e instanceof PurchasableEntityInterface ? $e : NULL;
    }
    return $item instanceof PurchasableEntityInterface ? $item : NULL;
  }

  /**
   * Évalue la disponibilité d'un achat en précommande.
   */
  public function evaluate(PurchasableEntityInterface $entity, int $quantity, $context, ?OrderItemInterface $order_item): AvailabilityResult {
    $rule = $this->ruleManager->getRunningRule($entity);
    if (!$rule) {
      return AvailabilityResult::neutral();
    }
    $order = $order_item ? $order_item->getOrder() : NULL;
    if ($order && $order->getState()->getId() !== 'draft') {
      return AvailabilityResult::neutral();
    }
    $is_new = !$order_item || !$order_item->getOrderId();
    $customer = $context instanceof Context ? $context->getCustomer() : \Drupal::currentUser();
    $label = $entity->label();

    // Quantité déjà présente dans le panier pour la même référence.
    $total = $quantity;
    $carts = [];
    if ($is_new) {
      $carts = $this->cartProvider->getCarts($customer);
      foreach ($carts as $cart) {
        foreach ($cart->getItems() as $other) {
          if ($other->getPurchasedEntityId() == $entity->id() && $other->getPurchasedEntity()?->getEntityTypeId() === $entity->getEntityTypeId()) {
            $total += (int) ceil((float) $other->getQuantity());
          }
        }
      }
    }

    // Quota total.
    if ($rule->getQuota() > 0) {
      $reserved = $this->reservations->getReserved((string) $rule->id());
      $remaining = max(0, $rule->getQuota() - $reserved);
      if ($remaining <= 0) {
        return AvailabilityResult::unavailable($this->t('Les précommandes pour « @label » sont complètes : le quota est atteint.', ['@label' => $label]));
      }
      if ($total > $remaining) {
        return AvailabilityResult::unavailable($this->formatPlural($remaining,
          'Il ne reste qu’1 exemplaire de « @label » en précommande.',
          'Il ne reste que @count exemplaires de « @label » en précommande.', ['@label' => $label]));
      }
    }

    // Maximum par commande.
    if ($rule->getMaxPerOrder() > 0 && $total > $rule->getMaxPerOrder()) {
      return AvailabilityResult::unavailable($this->t('Vous ne pouvez commander que @max exemplaire(s) de « @label » par commande.', ['@max' => $rule->getMaxPerOrder(), '@label' => $label]));
    }

    // Maximum par client.
    if ($rule->getMaxPerCustomer() > 0) {
      $uid = (int) $customer->id();
      $email = $order ? (string) $order->getEmail() : '';
      if ($uid > 0 || $email !== '') {
        $already = $this->reservations->getReservedByCustomer((string) $rule->id(), $uid, $email);
        if ($already + $total > $rule->getMaxPerCustomer()) {
          return AvailabilityResult::unavailable($this->t('Limite de @max exemplaire(s) de « @label » par client atteinte (déjà réservés : @done).', [
            '@max' => $rule->getMaxPerCustomer(), '@label' => $label, '@done' => $already,
          ]));
        }
      }
    }

    // Mélange précommandes / produits en stock.
    if ($is_new && $this->configFactory->get('websource_preorder.settings')->get('forbid_mixed')) {
      foreach ($carts as $cart) {
        foreach ($cart->getItems() as $other) {
          $pe = $other->getPurchasedEntity();
          if ($pe && !$this->ruleManager->getRunningRule($pe)) {
            return AvailabilityResult::unavailable($this->t('Les précommandes ne peuvent pas être mélangées avec des produits en stock : finalisez ou videz votre panier d’abord.'));
          }
        }
      }
    }

    return AvailabilityResult::neutral();
  }

  /**
   * Vérifie, pour un produit hors précommande, qu'on ne mélange pas.
   */
  public function checkMixedForRegular(PurchasableEntityInterface $entity, $customer): ?AvailabilityResult {
    if (!$this->configFactory->get('websource_preorder.settings')->get('forbid_mixed')) {
      return NULL;
    }
    foreach ($this->cartProvider->getCarts($customer) as $cart) {
      foreach ($cart->getItems() as $other) {
        $pe = $other->getPurchasedEntity();
        if ($pe && $this->ruleManager->getRunningRule($pe)) {
          return AvailabilityResult::unavailable($this->t('Votre panier contient des précommandes : elles ne peuvent pas être mélangées avec des produits en stock.'));
        }
      }
    }
    return NULL;
  }

}
