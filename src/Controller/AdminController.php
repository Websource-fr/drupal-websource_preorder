<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\websource_preorder\Entity\PreorderRule;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Écrans d'administration : tableau de bord, réservations, abonnés.
 */
class AdminController extends ControllerBase {

  public function __construct(
    protected $rules,
    protected $reservations,
    protected $subscribers,
    protected $mailer,
    protected $release,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('websource_preorder.rule_manager'),
      $container->get('websource_preorder.reservation_manager'),
      $container->get('websource_preorder.subscriber_manager'),
      $container->get('websource_preorder.mailer'),
      $container->get('websource_preorder.release'),
    );
  }

  /** Tableau de bord rapide. */
  public function dashboard(): array {
    $counts = ['running' => 0, 'scheduled' => 0, 'ended' => 0, 'disabled' => 0];
    foreach ($this->rules->getRules() as $rule) {
      $counts[$this->rules->getStatus($rule)]++;
    }
    $res = $this->reservations->countByStatus();
    $subs = $this->subscribers->countAll();
    $s = $this->config('websource_preorder.settings');
    $digest = \Drupal::service('websource_preorder.digest');
    $last = $digest->lastRun();
    $cron_url = Url::fromRoute('websource_preorder.cron', ['token' => $s->get('cron_token')], ['absolute' => TRUE])->toString();
    $cards = [
      [$this->t('Précommandes en cours'), $counts['running']],
      [$this->t('Programmées'), $counts['scheduled']],
      [$this->t('Terminées'), $counts['ended']],
      [$this->t('Réservations actives'), (int) ($res['active'] ?? 0)],
      [$this->t('Réservations annulées'), (int) ($res['cancelled'] ?? 0)],
      [$this->t('Abonnés confirmés'), (int) ($subs[1] ?? 0)],
      [$this->t('Abonnés en attente'), (int) ($subs[0] ?? 0)],
    ];
    $build['#attached']['library'][] = 'websource_preorder/admin';
    $build['cards'] = ['#type' => 'container', '#attributes' => ['class' => ['wspo-admin-cards']]];
    foreach ($cards as $i => $c) {
      $build['cards'][$i] = ['#markup' => '<div class="wspo-admin-card"><strong>' . (int) $c[1] . '</strong><span>' . $c[0] . '</span></div>'];
    }
    $build['links'] = [
      '#theme' => 'item_list',
      '#items' => [
        Link::createFromRoute($this->t('Règles de précommande'), 'entity.websource_preorder_rule.collection'),
        Link::createFromRoute($this->t('Réservations'), 'websource_preorder.reservations'),
        Link::createFromRoute($this->t('Abonnés aux alertes'), 'websource_preorder.subscribers'),
        Link::createFromRoute($this->t('Réglages'), 'websource_preorder.settings'),
      ],
    ];
    $build['cron'] = [
      '#markup' => '<p>' . $this->t('Dernier récapitulatif : @d. Adresse du cron sécurisé : <code>@u</code>', [
        '@d' => $last ? \Drupal::service('date.formatter')->format($last, 'short') : $this->t('jamais'),
        '@u' => $cron_url,
      ]) . '</p>',
    ];
    $build['#cache']['max-age'] = 0;
    return $build;
  }

  /** « Disponible maintenant » : sortie immédiate. */
  public function releaseNow(PreorderRule $websource_preorder_rule): RedirectResponse {
    $n = $this->release->releaseNow($websource_preorder_rule);
    $this->messenger()->addStatus($this->t('« @l » est disponible maintenant. @n e-mail(s) « disponible » envoyé(s).', ['@l' => $websource_preorder_rule->label(), '@n' => $n]));
    return $this->redirect('entity.websource_preorder_rule.collection');
  }

  /** Écran des réservations. */
  public function reservations(Request $request): array {
    $filters = [
      'rule_id' => (string) $request->query->get('rule_id', ''),
      'status' => (string) $request->query->get('status', ''),
      'q' => trim((string) $request->query->get('q', '')),
    ];
    $rows = [];
    foreach ($this->reservations->query($filters, 50) as $r) {
      $ops = [];
      $ops['resend'] = ['title' => $this->t('Renvoyer l’e-mail'), 'url' => Url::fromRoute('websource_preorder.reservation_resend', ['id' => $r->id])];
      if ($r->status === 'active') {
        $ops['cancel'] = ['title' => $this->t('Annuler'), 'url' => Url::fromRoute('websource_preorder.reservation_cancel', ['id' => $r->id])];
      }
      $rows[] = [
        $r->id,
        $r->order_id ? Link::createFromRoute('#' . $r->order_id, 'entity.commerce_order.canonical', ['commerce_order' => $r->order_id]) : '-',
        $r->product_title,
        $r->quantity,
        $r->customer_name ?: $r->email,
        $r->email,
        $r->status === 'active' ? $this->t('Active') : $this->t('Annulée'),
        \Drupal::service('date.formatter')->format((int) $r->created, 'short'),
        $r->notified ? \Drupal::service('date.formatter')->format((int) $r->notified, 'short') : '-',
        ['data' => ['#type' => 'operations', '#links' => $ops]],
      ];
    }
    $build['filter'] = $this->formBuilder()->getForm('Drupal\websource_preorder\Form\ReservationFilterForm');
    $build['export'] = ['#type' => 'link', '#title' => $this->t('Exporter en CSV'), '#url' => Url::fromRoute('websource_preorder.reservations_export', [], ['query' => array_filter($filters)]), '#attributes' => ['class' => ['button']]];
    $build['table'] = [
      '#type' => 'table',
      '#header' => ['ID', $this->t('Commande'), $this->t('Produit'), $this->t('Qté'), $this->t('Client'), $this->t('E-mail'), $this->t('Statut'), $this->t('Créée le'), $this->t('Prévenu le'), $this->t('Actions')],
      '#rows' => $rows,
      '#empty' => $this->t('Aucune réservation.'),
    ];
    $build['pager'] = ['#type' => 'pager'];
    $build['#cache']['max-age'] = 0;
    return $build;
  }

  protected function csv(string $filename, array $header, iterable $rows): StreamedResponse {
    $response = new StreamedResponse(function () use ($header, $rows) {
      $out = fopen('php://output', 'w');
      fwrite($out, "\xEF\xBB\xBF");
      $clean = fn ($v) => (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], TRUE)) ? "'" . $v : $v;
      fputcsv($out, $header, ';', '"', '\\');
      foreach ($rows as $row) {
        fputcsv($out, array_map($clean, $row), ';', '"', '\\');
      }
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    return $response;
  }

  public function exportReservations(Request $request): StreamedResponse {
    $filters = ['rule_id' => (string) $request->query->get('rule_id', ''), 'status' => (string) $request->query->get('status', ''), 'q' => (string) $request->query->get('q', '')];
    $rows = [];
    foreach ($this->reservations->query($filters) as $r) {
      $rows[] = [$r->id, $r->rule_id, $r->order_id, $r->product_title, $r->quantity, $r->customer_name, $r->email, $r->status, date('Y-m-d H:i', (int) $r->created), $r->notified ? date('Y-m-d H:i', (int) $r->notified) : ''];
    }
    return $this->csv('reservations-precommandes.csv', ['id', 'regle', 'commande', 'produit', 'quantite', 'client', 'email', 'statut', 'creee', 'prevenu'], $rows);
  }

  public function resend(int $id): RedirectResponse {
    $r = $this->reservations->load($id);
    if ($r) {
      $rule = $this->rules->getRules()[$r->rule_id] ?? NULL;
      $product = $rule ? $this->rules->getProduct($rule) : NULL;
      $ok = $this->mailer->sendAvailable($r, $rule, $product);
      $ok ? $this->messenger()->addStatus($this->t('E-mail renvoyé à @e.', ['@e' => $r->email])) : $this->messenger()->addError($this->t('Échec de l’envoi.'));
    }
    return $this->redirect('websource_preorder.reservations');
  }

  public function cancelReservation(int $id): RedirectResponse {
    $this->reservations->cancel($id);
    $this->messenger()->addStatus($this->t('Réservation annulée : le quota a été libéré.'));
    return $this->redirect('websource_preorder.reservations');
  }

  /** Écran des abonnés. */
  public function subscribers(): array {
    $rows = [];
    foreach ($this->subscribers->query(50) as $s) {
      $ops = [];
      if (!$s->status) {
        $ops['confirm'] = ['title' => $this->t('Confirmer'), 'url' => Url::fromRoute('websource_preorder.subscriber_confirm_admin', ['id' => $s->id])];
      }
      $ops['delete'] = ['title' => $this->t('Supprimer'), 'url' => Url::fromRoute('websource_preorder.subscriber_delete', ['id' => $s->id])];
      $rows[] = [
        $s->id, $s->email, $s->langcode,
        $s->status ? $this->t('Confirmé') : $this->t('En attente'),
        \Drupal::service('date.formatter')->format((int) $s->created, 'short'),
        ['data' => ['#type' => 'operations', '#links' => $ops]],
      ];
    }
    return [
      'export' => ['#type' => 'link', '#title' => $this->t('Exporter en CSV'), '#url' => Url::fromRoute('websource_preorder.subscribers_export'), '#attributes' => ['class' => ['button']]],
      'table' => [
        '#type' => 'table',
        '#header' => ['ID', $this->t('E-mail'), $this->t('Langue'), $this->t('Statut'), $this->t('Inscrit le'), $this->t('Actions')],
        '#rows' => $rows,
        '#empty' => $this->t('Aucun abonné.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function exportSubscribers(): StreamedResponse {
    $rows = [];
    foreach ($this->subscribers->query() as $s) {
      $rows[] = [$s->id, $s->email, $s->langcode, $s->status ? 'confirme' : 'en attente', date('Y-m-d H:i', (int) $s->created), $s->confirmed ? date('Y-m-d H:i', (int) $s->confirmed) : ''];
    }
    return $this->csv('abonnes-precommandes.csv', ['id', 'email', 'langue', 'statut', 'inscrit', 'confirme'], $rows);
  }

  public function confirmSubscriber(int $id): RedirectResponse {
    $this->subscribers->confirm($id);
    $this->messenger()->addStatus($this->t('Abonné confirmé.'));
    return $this->redirect('websource_preorder.subscribers');
  }

  public function deleteSubscriber(int $id): RedirectResponse {
    $this->subscribers->delete($id);
    $this->messenger()->addStatus($this->t('Abonné supprimé.'));
    return $this->redirect('websource_preorder.subscribers');
  }

}
