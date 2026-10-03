<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\websource_preorder\Entity\PreorderRule;

/**
 * Stockage et requêtes des réservations de précommande.
 */
class ReservationManager {

  const TABLE = 'websource_preorder_reservation';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected CacheTagsInvalidatorInterface $tagsInvalidator,
    protected RuleManager $ruleManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Enregistre les réservations d'une commande placée. Idempotent.
   *
   * @return int
   *   Nombre de réservations créées.
   */
  public function createForOrder(OrderInterface $order): int {
    $count = 0;
    foreach ($order->getItems() as $item) {
      $entity = $item->getPurchasedEntity();
      if (!$entity) {
        continue;
      }
      $rule = $this->ruleManager->getRuleForPurchasable($entity);
      if (!$rule || !$this->ruleManager->isRunning($rule)) {
        continue;
      }
      $exists = $this->database->select(self::TABLE, 'r')->fields('r', ['id'])
        ->condition('order_item_id', (int) $item->id())->range(0, 1)->execute()->fetchField();
      if ($exists) {
        continue;
      }
      $customer = $order->getCustomer();
      $this->database->insert(self::TABLE)->fields([
        'rule_id' => $rule->id(),
        'variation_id' => (int) $entity->id(),
        'order_id' => (int) $order->id(),
        'order_item_id' => (int) $item->id(),
        'uid' => $customer ? (int) $customer->id() : 0,
        'email' => (string) $order->getEmail(),
        'customer_name' => $customer && !$customer->isAnonymous() ? (string) $customer->getDisplayName() : '',
        'product_title' => mb_substr((string) $item->label(), 0, 255),
        'quantity' => max(1, (int) ceil((float) $item->getQuantity())),
        'status' => 'active',
        'langcode' => $customer && !$customer->isAnonymous() ? $customer->getPreferredLangcode() : \Drupal::languageManager()->getCurrentLanguage()->getId(),
        'created' => $this->time->getRequestTime(),
        'notified' => 0,
      ])->execute();
      $count++;
    }
    if ($count) {
      $this->tagsInvalidator->invalidateTags(['websource_preorder_reservations']);
    }
    return $count;
  }

  /**
   * Quantité réservée (réservations actives) pour une règle.
   */
  public function getReserved(string $rule_id): int {
    $q = $this->database->select(self::TABLE, 'r');
    $q->addExpression('SUM(quantity)', 'total');
    $q->condition('rule_id', $rule_id)->condition('status', 'active');
    return (int) $q->execute()->fetchField();
  }

  /**
   * Quantité réservée par un client pour une règle.
   */
  public function getReservedByCustomer(string $rule_id, int $uid, string $email = ''): int {
    $q = $this->database->select(self::TABLE, 'r');
    $q->addExpression('SUM(quantity)', 'total');
    $q->condition('rule_id', $rule_id)->condition('status', 'active');
    if ($uid > 0) {
      $q->condition('uid', $uid);
    }
    elseif ($email !== '') {
      $q->condition('email', $email);
    }
    else {
      return 0;
    }
    return (int) $q->execute()->fetchField();
  }

  /**
   * Annule (libère le quota de) toutes les réservations d'une commande.
   */
  public function cancelByOrder(int $order_id): int {
    $n = (int) $this->database->update(self::TABLE)->fields(['status' => 'cancelled'])
      ->condition('order_id', $order_id)->condition('status', 'active')->execute();
    if ($n) {
      $this->tagsInvalidator->invalidateTags(['websource_preorder_reservations']);
    }
    return $n;
  }

  public function cancel(int $id): void {
    $this->database->update(self::TABLE)->fields(['status' => 'cancelled'])->condition('id', $id)->execute();
    $this->tagsInvalidator->invalidateTags(['websource_preorder_reservations']);
  }

  public function deleteByOrder(int $order_id): void {
    $this->database->delete(self::TABLE)->condition('order_id', $order_id)->execute();
    $this->tagsInvalidator->invalidateTags(['websource_preorder_reservations']);
  }

  public function load(int $id): ?object {
    $row = $this->database->select(self::TABLE, 'r')->fields('r')->condition('id', $id)->execute()->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Liste filtrée (paginée si $limit > 0).
   *
   * @return object[]
   */
  public function query(array $filters = [], int $limit = 0): array {
    $q = $this->database->select(self::TABLE, 'r')->fields('r');
    $this->applyFilters($q, $filters);
    $q->orderBy('r.id', 'DESC');
    if ($limit > 0) {
      $q = $q->extend('Drupal\Core\Database\Query\PagerSelectExtender')->limit($limit);
    }
    return $q->execute()->fetchAll();
  }

  protected function applyFilters($q, array $filters): void {
    if (!empty($filters['rule_id'])) {
      $q->condition('r.rule_id', $filters['rule_id']);
    }
    if (!empty($filters['status'])) {
      $q->condition('r.status', $filters['status']);
    }
    if (!empty($filters['q'])) {
      $like = '%' . $this->database->escapeLike($filters['q']) . '%';
      $or = $q->orConditionGroup()->condition('r.email', $like, 'LIKE')
        ->condition('r.product_title', $like, 'LIKE')->condition('r.customer_name', $like, 'LIKE');
      if (ctype_digit((string) $filters['q'])) {
        $or->condition('r.order_id', (int) $filters['q']);
      }
      $q->condition($or);
    }
  }

  /**
   * Réservations d'un utilisateur.
   */
  public function getByUser(int $uid): array {
    return $this->database->select(self::TABLE, 'r')->fields('r')->condition('uid', $uid)
      ->orderBy('id', 'DESC')->execute()->fetchAll();
  }

  /**
   * Réservations actives non notifiées dont la règle est sortie.
   *
   * @return object[]
   */
  public function getPendingNotifications(string $rule_id, int $limit): array {
    return $this->database->select(self::TABLE, 'r')->fields('r')
      ->condition('rule_id', $rule_id)->condition('status', 'active')->condition('notified', 0)
      ->orderBy('id')->range(0, $limit)->execute()->fetchAll();
  }

  public function markNotified(int $id): void {
    $this->database->update(self::TABLE)->fields(['notified' => $this->time->getRequestTime()])->condition('id', $id)->execute();
  }

  public function countByStatus(): array {
    $q = $this->database->select(self::TABLE, 'r');
    $q->addField('r', 'status');
    $q->addExpression('COUNT(*)', 'n');
    $q->groupBy('r.status');
    return $q->execute()->fetchAllKeyed();
  }

  /**
   * Quantité réservée et quota restant : [reserved, quota, remaining|NULL].
   */
  public function getStock(PreorderRule $rule): array {
    $reserved = $this->getReserved((string) $rule->id());
    $quota = $rule->getQuota();
    return [$reserved, $quota, $quota > 0 ? max(0, $quota - $reserved) : NULL];
  }

}
