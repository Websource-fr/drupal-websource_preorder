<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Ajout manuel d'un abonné (déjà confirmé).
 */
class SubscriberAddForm extends FormBase {

  public function __construct(protected $subscribers) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('websource_preorder.subscriber_manager'));
  }

  public function getFormId() {
    return 'websource_preorder_subscriber_add_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['email'] = ['#type' => 'email', '#title' => $this->t('Adresse e-mail'), '#required' => TRUE];
    $langs = [];
    foreach (\Drupal::languageManager()->getLanguages() as $l) {
      $langs[$l->getId()] = $l->getName();
    }
    $form['langcode'] = ['#type' => 'select', '#title' => $this->t('Langue'), '#options' => $langs, '#default_value' => \Drupal::languageManager()->getDefaultLanguage()->getId()];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Ajouter')];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $sub = $this->subscribers->add((string) $form_state->getValue('email'), (string) $form_state->getValue('langcode'), TRUE);
    if ((int) $sub->status !== 1) {
      $this->subscribers->confirm((int) $sub->id);
    }
    $this->messenger()->addStatus($this->t('Abonné ajouté et confirmé.'));
    $form_state->setRedirect('websource_preorder.subscribers');
  }

}
