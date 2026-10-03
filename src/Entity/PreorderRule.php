<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Règle de précommande associée à un produit ou à une variation Commerce.
 *
 * @ConfigEntityType(
 *   id = "websource_preorder_rule",
 *   label = @Translation("Règle de précommande"),
 *   label_collection = @Translation("Règles de précommande"),
 *   label_singular = @Translation("règle de précommande"),
 *   label_plural = @Translation("règles de précommande"),
 *   handlers = {
 *     "list_builder" = "Drupal\websource_preorder\PreorderRuleListBuilder",
 *     "form" = {
 *       "add" = "Drupal\websource_preorder\Form\PreorderRuleForm",
 *       "edit" = "Drupal\websource_preorder\Form\PreorderRuleForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     }
 *   },
 *   config_prefix = "rule",
 *   admin_permission = "manage websource_preorder rules",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "target_type",
 *     "target_id",
 *     "active",
 *     "date_start",
 *     "date_release",
 *     "end_on_release",
 *     "date_end",
 *     "price_mode",
 *     "price_value",
 *     "quota",
 *     "max_per_order",
 *     "max_per_customer",
 *     "message",
 *     "button_label",
 *     "created"
 *   },
 *   links = {
 *     "collection" = "/admin/commerce/preorder/rules",
 *     "add-form" = "/admin/commerce/preorder/rules/add",
 *     "edit-form" = "/admin/commerce/preorder/rules/{websource_preorder_rule}/edit",
 *     "delete-form" = "/admin/commerce/preorder/rules/{websource_preorder_rule}/delete"
 *   }
 * )
 */
class PreorderRule extends ConfigEntityBase {

  /** Identifiant machine. */
  protected $id;

  /** Libellé. */
  protected $label;

  /** « variation » ou « product ». */
  protected $target_type = 'variation';

  /** Identifiant de la variation ou du produit ciblé. */
  protected $target_id = 0;

  /** Règle activée. */
  protected $active = TRUE;

  /** Début (timestamp) ou NULL. */
  protected $date_start = NULL;

  /** Date de sortie (timestamp) ou NULL. */
  protected $date_release = NULL;

  /** Clôture automatique à la date de sortie. */
  protected $end_on_release = TRUE;

  /** Fin anticipée (timestamp) ou NULL. */
  protected $date_end = NULL;

  /** none | fixed | percent | amount. */
  protected $price_mode = 'none';

  /** Valeur du prix (décimal sous forme de chaîne). */
  protected $price_value = '';

  /** Quota total (0 = illimité). */
  protected $quota = 0;

  /** Maximum par commande (0 = illimité). */
  protected $max_per_order = 0;

  /** Maximum par client (0 = illimité). */
  protected $max_per_customer = 0;

  /** Message personnalisé. */
  protected $message = '';

  /** Libellé du bouton propre à la règle. */
  protected $button_label = '';

  /** Date de création. */
  protected $created = 0;

  /**
   * {@inheritdoc}
   */
  public function status() {
    return (bool) $this->active;
  }

  public function isActive(): bool {
    return (bool) $this->active;
  }

  public function getTargetType(): string {
    return (string) $this->target_type;
  }

  public function getTargetId(): int {
    return (int) $this->target_id;
  }

  public function getDateStart(): ?int {
    return $this->date_start ? (int) $this->date_start : NULL;
  }

  public function getDateRelease(): ?int {
    return $this->date_release ? (int) $this->date_release : NULL;
  }

  public function getDateEnd(): ?int {
    return $this->date_end ? (int) $this->date_end : NULL;
  }

  public function endsOnRelease(): bool {
    return (bool) $this->end_on_release;
  }

  public function getPriceMode(): string {
    return (string) $this->price_mode;
  }

  public function getPriceValue(): string {
    return (string) $this->price_value;
  }

  public function getQuota(): int {
    return (int) $this->quota;
  }

  public function getMaxPerOrder(): int {
    return (int) $this->max_per_order;
  }

  public function getMaxPerCustomer(): int {
    return (int) $this->max_per_customer;
  }

  public function getMessage(): string {
    return (string) $this->message;
  }

  public function getButtonLabel(): string {
    return (string) $this->button_label;
  }

  public function getCreated(): int {
    return (int) $this->created;
  }

  /**
   * Instant d'ouverture de la règle (pour le mode « nouveautés » du récap).
   */
  public function getOpenedAt(): int {
    return $this->getDateStart() ?: $this->getCreated();
  }

  /**
   * {@inheritdoc}
   */
  public function preSave($storage) {
    parent::preSave($storage);
    if (!$this->created) {
      $this->created = \Drupal::time()->getRequestTime();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave($storage, $update = TRUE) {
    parent::postSave($storage, $update);
    \Drupal::service('websource_preorder.rule_manager')->reset();
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete($storage, array $entities) {
    parent::postDelete($storage, $entities);
    \Drupal::service('websource_preorder.rule_manager')->reset();
  }

}
