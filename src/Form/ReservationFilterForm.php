<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Filtre de l'écran Réservations (recherche, règle, statut).
 */
class ReservationFilterForm extends FormBase {

  public function getFormId() {
    return 'websource_preorder_reservation_filter_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $req = $this->getRequest()->query;
    $rules = [];
    foreach (\Drupal::service('websource_preorder.rule_manager')->getRules() as $r) {
      $rules[$r->id()] = $r->label();
    }
    $form['#attributes']['class'][] = 'form--inline';
    $form['q'] = ['#type' => 'textfield', '#title' => $this->t('Recherche'), '#size' => 25, '#default_value' => (string) $req->get('q', '')];
    $form['rule_id'] = ['#type' => 'select', '#title' => $this->t('Règle'), '#options' => $rules, '#empty_option' => $this->t('- Toutes -'), '#default_value' => (string) $req->get('rule_id', '')];
    $form['status'] = ['#type' => 'select', '#title' => $this->t('Statut'), '#options' => ['active' => $this->t('Active'), 'cancelled' => $this->t('Annulée')], '#empty_option' => $this->t('- Tous -'), '#default_value' => (string) $req->get('status', '')];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Filtrer')];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $query = array_filter([
      'q' => $form_state->getValue('q'), 'rule_id' => $form_state->getValue('rule_id'), 'status' => $form_state->getValue('status'),
    ]);
    $form_state->setRedirect('websource_preorder.reservations', [], ['query' => $query]);
  }

}
