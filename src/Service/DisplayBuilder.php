<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\websource_preorder\Entity\PreorderRule;

/**
 * Construit les tableaux de rendu (badge, bloc produit, compte à rebours...).
 */
class DisplayBuilder {

  use StringTranslationTrait;

  public function __construct(
    protected RuleManager $rules,
    protected ReservationManager $reservations,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected DateFormatterInterface $dateFormatter,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  protected function settings() {
    return $this->configFactory->get('websource_preorder.settings');
  }

  public function statusLabel(string $status) {
    return match ($status) {
      RuleManager::STATUS_RUNNING => $this->t('En cours'),
      RuleManager::STATUS_SCHEDULED => $this->t('Programmée'),
      RuleManager::STATUS_ENDED => $this->t('Terminée'),
      default => $this->t('Désactivée'),
    };
  }

  /** Cache : tags et durée de vie jusqu'à la prochaine bascule. */
  public function cacheFor(?PreorderRule $rule = NULL): array {
    $cache = [
      'tags' => ['config:websource_preorder.settings', 'websource_preorder_rules', 'websource_preorder_reservations'],
      'contexts' => ['languages:language_interface'],
      'max-age' => 300,
    ];
    if ($rule) {
      $now = $this->time->getRequestTime();
      $next = array_filter([$rule->getDateStart(), $this->rules->getEffectiveEnd($rule), $rule->getDateRelease()], fn ($t) => $t && $t > $now);
      if ($next) {
        $cache['max-age'] = max(1, min(300, min($next) - $now));
      }
    }
    return $cache;
  }

  public function badge(PreorderRule $rule): array {
    $label = $rule->getButtonLabel() ?: $this->settings()->get('badge_label');
    return [
      '#theme' => 'websource_preorder_badge',
      '#label' => $this->settings()->get('badge_label') ?: $this->t('Précommande'),
      '#attached' => ['library' => ['websource_preorder/front']],
      '#cache' => $this->cacheFor($rule),
    ];
  }

  /** Compte à rebours vers un instant donné. */
  public function countdown(int $target, ?int $start = NULL): array {
    return [
      '#theme' => 'websource_preorder_countdown',
      '#target' => $target,
      '#start' => $start,
      '#mode' => $this->settings()->get('countdown_mode') ?: 'auto',
      '#remaining' => max(0, $target - $this->time->getRequestTime()),
      '#labels' => [
        'd' => $this->t('jours'), 'h' => $this->t('heures'), 'm' => $this->t('minutes'), 's' => $this->t('secondes'),
      ],
      '#done_text' => $this->t('Disponible maintenant'),
    ];
  }

  /**
   * Données d'affichage d'une règle.
   */
  public function data(PreorderRule $rule): array {
    $s = $this->settings();
    $now = $this->time->getRequestTime();
    $status = $this->rules->getStatus($rule);
    $rel = $rule->getDateRelease();
    [$reserved, $quota, $remaining] = $this->reservations->getStock($rule);
    $start = $rule->getDateStart() ?: $rule->getCreated();
    $progress = NULL;
    if ($rel && $start && $rel > $start) {
      $progress = (int) max(0, min(100, round(($now - $start) / ($rel - $start) * 100)));
    }
    $product = $this->rules->getProduct($rule);
    return [
      'rule' => $rule,
      'status' => $status,
      'status_label' => $this->statusLabel($status),
      'title' => $product ? $product->label() : $rule->label(),
      'url' => $product ? $product->toUrl()->toString() : '',
      'image' => $product ? $this->imageUrl($product) : NULL,
      'release' => $rel ? $this->dateFormatter->format($rel, 'custom', 'd/m/Y') : NULL,
      'release_ts' => $rel,
      'countdown' => ($s->get('show_countdown') && $rel && $rel > $now) ? $this->countdown($rel, $start) : NULL,
      'progress' => $s->get('show_progress') ? $progress : NULL,
      'remaining' => ($s->get('show_quota') && $quota > 0) ? $remaining : NULL,
      'quota' => $quota,
      'message' => $rule->getMessage(),
      'badge' => $this->badge($rule),
    ];
  }

  /** Bloc complet de la fiche produit. */
  public function box(PreorderRule $rule): array {
    $d = $this->data($rule);
    if ($d['status'] !== RuleManager::STATUS_RUNNING && $d['status'] !== RuleManager::STATUS_SCHEDULED) {
      return [];
    }
    return [
      '#theme' => 'websource_preorder_box',
      '#data' => $d,
      '#attached' => ['library' => ['websource_preorder/front']],
      '#cache' => $this->cacheFor($rule),
      '#weight' => -5,
    ];
  }

  /** Première image trouvée sur un produit : URL du style « medium ». */
  protected function imageUrl($product): ?string {
    foreach ($product->getFieldDefinitions() as $name => $def) {
      if ($def->getType() === 'image' && !$product->get($name)->isEmpty()) {
        $file = $product->get($name)->entity;
        if ($file) {
          $style = $this->entityTypeManager->getStorage('image_style')->load('medium');
          return $style ? $style->buildUrl($file->getFileUri()) : \Drupal::service('file_url_generator')->generateString($file->getFileUri());
        }
      }
    }
    foreach ($product->getVariations() as $v) {
      foreach ($v->getFieldDefinitions() as $name => $def) {
        if ($def->getType() === 'image' && !$v->get($name)->isEmpty() && ($file = $v->get($name)->entity)) {
          $style = $this->entityTypeManager->getStorage('image_style')->load('medium');
          return $style ? $style->buildUrl($file->getFileUri()) : \Drupal::service('file_url_generator')->generateString($file->getFileUri());
        }
      }
    }
    return NULL;
  }

  /** Liste de données pour les règles en cours (limite optionnelle). */
  public function runningItems(int $limit = 0): array {
    $out = [];
    foreach ($this->rules->getRunningRules() as $rule) {
      if (!$this->rules->getProduct($rule)) {
        continue;
      }
      $out[] = $this->data($rule);
      if ($limit && count($out) >= $limit) {
        break;
      }
    }
    return $out;
  }

  public function featured(): array {
    $s = $this->settings();
    if (!$s->get('featured_enabled')) {
      return [];
    }
    $items = $this->runningItems(1);
    if (!$items) {
      return [];
    }
    return [
      '#theme' => 'websource_preorder_featured',
      '#title' => $s->get('featured_title'),
      '#item' => $items[0],
      '#attached' => ['library' => ['websource_preorder/front']],
      '#cache' => $this->cacheFor($items[0]['rule']),
    ];
  }

  public function ticker(): array {
    $s = $this->settings();
    if (!$s->get('ticker_enabled')) {
      return [];
    }
    $items = $this->runningItems(max(1, (int) $s->get('ticker_max')));
    if (!$items) {
      return [];
    }
    return [
      '#theme' => 'websource_preorder_ticker',
      '#items' => $items,
      '#prefix_text' => $s->get('ticker_prefix'),
      '#speed' => max(10, (int) $s->get('ticker_speed')),
      '#attached' => ['library' => ['websource_preorder/front']],
      '#cache' => ['tags' => ['config:websource_preorder.settings', 'websource_preorder_rules'], 'max-age' => 300, 'contexts' => ['languages:language_interface']],
    ];
  }

  /** Page liste : groupée par mois de sortie. */
  public function listPage(): array {
    $s = $this->settings();
    $items = $this->runningItems();
    $groups = [];
    foreach ($items as $it) {
      $key = $it['release_ts'] && $s->get('list_group_by_month')
        ? $this->dateFormatter->format($it['release_ts'], 'custom', 'F Y')
        : ($it['release_ts'] ? '' : (string) $this->t('Date de sortie à venir'));
      $groups[$key][] = $it;
    }
    return [
      '#theme' => 'websource_preorder_list',
      '#intro' => $s->get('list_intro'),
      '#groups' => $groups,
      '#attached' => ['library' => ['websource_preorder/front']],
      '#cache' => ['tags' => ['config:websource_preorder.settings', 'websource_preorder_rules', 'websource_preorder_reservations'], 'max-age' => 300, 'contexts' => ['languages:language_interface']],
    ];
  }


  /**
   * Blocs affichés sous les formulaires connexion / inscription / mot de passe.
   */
  public function accountBlocks(array $opts): array {
    $s = $this->settings();
    $build = ['#type' => 'container', '#attributes' => ['class' => ['wspo-account']]];
    if (!empty($opts['products'])) {
      $items = $this->runningItems(max(1, (int) $s->get('accounts_max')));
      if ($items) {
        $build['products'] = [
          '#theme' => 'websource_preorder_upcoming',
          '#title' => $s->get('accounts_title'),
          '#text' => $s->get('accounts_text'),
          '#items' => $items,
        ];
      }
    }
    if (!empty($opts['form']) && $s->get('alerts_enabled')) {
      $build['form'] = \Drupal::formBuilder()->getForm('Drupal\websource_preorder\Form\SubscribeForm');
    }
    return count($build) > 2 ? $build : [];
  }

}
