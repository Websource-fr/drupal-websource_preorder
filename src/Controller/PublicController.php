<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages publiques : liste, Mes précommandes, confirmation, désinscription, cron.
 */
class PublicController extends ControllerBase {

  public function __construct(protected $display, protected $reservations, protected $subscribers, protected $rules) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('websource_preorder.display'),
      $container->get('websource_preorder.reservation_manager'),
      $container->get('websource_preorder.subscriber_manager'),
      $container->get('websource_preorder.rule_manager'),
    );
  }

  public function listTitle(): string {
    return (string) ($this->config('websource_preorder.settings')->get('list_title') ?: $this->t('Toutes les précommandes'));
  }

  public function listPage(): array {
    $build = $this->display->listPage();
    if (!$build['#groups'] && $this->config('websource_preorder.settings')->get('alerts_enabled')) {
      $build['#intro'] = ($build['#intro'] ? $build['#intro'] . ' ' : '') . $this->t('Aucune précommande n’est ouverte pour le moment.');
    }
    if ($this->config('websource_preorder.settings')->get('alerts_enabled')) {
      $build['alert'] = $this->formBuilder()->getForm('Drupal\websource_preorder\Form\SubscribeForm');
    }
    return $build;
  }

  public function accessMine(AccountInterface $account, UserInterface $user): AccessResult {
    $own = $account->isAuthenticated() && $account->id() === $user->id() && $account->hasPermission('view own websource_preorder');
    return AccessResult::allowedIf($own || $account->hasPermission('view websource_preorder reservations'))->cachePerPermissions()->cachePerUser();
  }

  public function myPreorders(UserInterface $user): array {
    $rows = [];
    $rulesAll = $this->rules->getRules();
    foreach ($this->reservations->getByUser((int) $user->id()) as $r) {
      $rule = $rulesAll[$r->rule_id] ?? NULL;
      $rel = $rule ? $rule->getDateRelease() : NULL;
      $released = $r->notified > 0 || ($rel && $rel <= \Drupal::time()->getRequestTime());
      $rows[] = [
        'order' => '#' . $r->order_id,
        'title' => $r->product_title,
        'qty' => (int) $r->quantity,
        'release' => $rel ? \Drupal::service('date.formatter')->format($rel, 'custom', 'd/m/Y') : '-',
        'status' => $r->status === 'cancelled' ? $this->t('Annulée') : ($released ? $this->t('Disponible') : $this->t('En attente de sortie')),
      ];
    }
    return [
      '#theme' => 'websource_preorder_mine',
      '#rows' => $rows,
      '#attached' => ['library' => ['websource_preorder/front']],
      '#cache' => ['tags' => ['websource_preorder_reservations', 'websource_preorder_rules'], 'contexts' => ['user'], 'max-age' => 300],
    ];
  }

  protected function message(string $text, bool $ok = TRUE): array {
    return ['#markup' => '<p class="wspo-message ' . ($ok ? 'is-ok' : 'is-error') . '">' . $text . '</p>', '#cache' => ['max-age' => 0]];
  }

  public function confirm(int $id, string $token): array {
    $s = $this->subscribers->load($id);
    if (!$s || !$this->subscribers->validToken($s, 'confirm', $token)) {
      return $this->message((string) $this->t('Lien de confirmation invalide ou expiré.'), FALSE);
    }
    $this->subscribers->confirm($id);
    return $this->message((string) $this->t('Merci, votre inscription aux alertes précommandes est confirmée.'));
  }

  public function unsubscribe(int $id, string $token): array {
    $s = $this->subscribers->load($id);
    if (!$s || !$this->subscribers->validToken($s, 'unsub', $token)) {
      return $this->message((string) $this->t('Lien de désinscription invalide.'), FALSE);
    }
    $this->subscribers->delete($id);
    return $this->message((string) $this->t('Vous êtes désinscrit des alertes précommandes. Vos données ont été supprimées.'));
  }

  /** Cron sécurisé par jeton : tâches du module + traitement de la file. */
  public function cron(string $token): Response {
    $expected = (string) $this->config('websource_preorder.settings')->get('cron_token');
    if ($expected === '' || !hash_equals($expected, $token)) {
      return new Response('Forbidden', 403);
    }
    $result = websource_preorder_run_tasks();
    // Traite la file des lots (temps limité).
    $queue = \Drupal::queue('websource_preorder_digest');
    $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('websource_preorder_digest');
    $end = time() + 25;
    $processed = 0;
    while (time() < $end && ($item = $queue->claimItem(60))) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (\Throwable $e) {
        $queue->releaseItem($item);
        break;
      }
    }
    $result['queue_processed'] = $processed;
    $result['queue_remaining'] = $queue->numberOfItems();
    $response = new JsonResponse($result);
    $response->setMaxAge(0);
    return $response;
  }

}
