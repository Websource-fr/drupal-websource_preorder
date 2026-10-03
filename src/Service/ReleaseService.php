<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\websource_preorder\Entity\PreorderRule;

/**
 * Sortie des produits : e-mails « disponible » et action « Disponible maintenant ».
 */
class ReleaseService {

  public function __construct(
    protected RuleManager $rules,
    protected ReservationManager $reservations,
    protected PreorderMailer $mailer,
    protected TimeInterface $time,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Envoie les e-mails « disponible » des règles sorties. Retourne le nombre envoyé.
   */
  public function process(int $limit = 100): int {
    $sent = 0;
    $now = $this->time->getRequestTime();
    $on = (bool) $this->configFactory->get('websource_preorder.settings')->get('mail_available_on');
    foreach ($this->rules->getRules() as $rule) {
      $rel = $rule->getDateRelease();
      if (!$rel || $now < $rel || !$rule->isActive()) {
        continue;
      }
      $pending = $this->reservations->getPendingNotifications((string) $rule->id(), $limit - $sent);
      $product = $pending ? $this->rules->getProduct($rule) : NULL;
      foreach ($pending as $res) {
        if ($on) {
          $this->mailer->sendAvailable($res, $rule, $product);
        }
        $this->reservations->markNotified((int) $res->id);
        $sent++;
      }
      if ($sent >= $limit) {
        break;
      }
    }
    return $sent;
  }

  /**
   * « Disponible maintenant » : sortie immédiate et clôture.
   */
  public function releaseNow(PreorderRule $rule): int {
    $rule->set('date_release', $this->time->getRequestTime());
    $rule->set('end_on_release', TRUE);
    $rule->save();
    return $this->process(200);
  }

}
