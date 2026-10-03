<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Drupal\websource_preorder\Service\PreorderMailer;
use Drupal\websource_preorder\Service\ReservationManager;
use Drupal\websource_preorder\Service\RuleManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Réservations à la commande ; annulation libère le quota.
 */
class OrderSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected RuleManager $rules,
    protected ReservationManager $reservations,
    protected PreorderMailer $mailer,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => 'onPlace',
      'commerce_order.cancel.post_transition' => 'onCancel',
    ];
  }

  public function onPlace(WorkflowTransitionEvent $event): void {
    $order = $event->getEntity();
    if ($this->reservations->createForOrder($order) > 0) {
      $this->mailer->sendOrderMails($order, $this->rules);
    }
  }

  public function onCancel(WorkflowTransitionEvent $event): void {
    $this->reservations->cancelByOrder((int) $event->getEntity()->id());
  }

}
