<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Récapitulatif périodique : planification, lots, reprise via Queue API.
 */
class DigestService {

  const QUEUE = 'websource_preorder_digest';
  const PERIODS = ['day' => 86400, 'week' => 604800, 'month' => 2592000];

  public function __construct(
    protected RuleManager $rules,
    protected SubscriberManager $subscribers,
    protected PreorderMailer $mailer,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected QueueFactory $queueFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /** Intervalle entre deux envois (secondes). */
  public function interval(): int {
    $c = $this->configFactory->get('websource_preorder.settings');
    $period = self::PERIODS[$c->get('digest_freq')] ?? self::PERIODS['week'];
    return max(60, (int) floor($period / max(1, (int) $c->get('digest_times'))));
  }

  public function lastRun(): int {
    return (int) $this->state->get('websource_preorder.digest_last', 0);
  }

  public function isDue(): bool {
    $c = $this->configFactory->get('websource_preorder.settings');
    return $c->get('digest_enabled') && ($this->time->getRequestTime() - $this->lastRun()) >= $this->interval();
  }

  /**
   * Produits à envoyer selon le mode (toutes les ouvertes / nouveautés).
   */
  public function buildItems(): array {
    $c = $this->configFactory->get('websource_preorder.settings');
    $since = $this->lastRun();
    $items = [];
    foreach ($this->rules->getRunningRules() as $rule) {
      if ($c->get('digest_mode') === 'new' && $since && $rule->getOpenedAt() <= $since) {
        continue;
      }
      $product = $this->rules->getProduct($rule);
      if (!$product) {
        continue;
      }
      $rel = $rule->getDateRelease();
      $items[] = [
        'title' => (string) $product->label(),
        'url' => $product->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'info' => $rel ? \Drupal::service('date.formatter')->format($rel, 'custom', 'd/m/Y') : '',
      ];
      if (count($items) >= max(1, (int) $c->get('digest_max'))) {
        break;
      }
    }
    return $items;
  }

  /**
   * Lance un envoi s'il est dû et s'il y a quelque chose à envoyer.
   *
   * @return int
   *   Nombre de lots mis en file (0 = rien).
   */
  public function run(bool $force = FALSE): int {
    if (!$force && !$this->isDue()) {
      return 0;
    }
    $queue = $this->queueFactory->get(self::QUEUE);
    if ($queue->numberOfItems() > 0) {
      return 0;
    }
    $items = $this->buildItems();
    if (!$items) {
      $this->logger->info('Récapitulatif : rien à envoyer.');
      return 0;
    }
    $run = $this->time->getRequestTime();
    $ids = $this->subscribers->getConfirmedIds($run);
    if (!$ids) {
      return 0;
    }
    $this->state->set('websource_preorder.digest_last', $run);
    $this->state->set('websource_preorder.digest_items', $items);
    $this->state->set('websource_preorder.digest_run', $run);
    $batches = array_chunk($ids, max(1, (int) $this->configFactory->get('websource_preorder.settings')->get('digest_batch')));
    foreach ($batches as $b) {
      $queue->createItem(['run' => $run, 'ids' => $b]);
    }
    $this->logger->info('Récapitulatif : @n lot(s) en file (@s abonnés).', ['@n' => count($batches), '@s' => count($ids)]);
    return count($batches);
  }

  /**
   * Traite un lot : envoie à chaque abonné pas encore servi pour ce run.
   */
  public function processBatch(array $data): int {
    $items = $this->state->get('websource_preorder.digest_items', []);
    $sent = 0;
    foreach ($data['ids'] as $id) {
      $sub = $this->subscribers->load((int) $id);
      if (!$sub || (int) $sub->status !== 1 || (int) $sub->digest_run === (int) $data['run']) {
        continue;
      }
      if ($items && $this->mailer->sendDigest($sub, $items)) {
        $sent++;
      }
      $this->subscribers->markRun((int) $id, (int) $data['run']);
    }
    return $sent;
  }

}
