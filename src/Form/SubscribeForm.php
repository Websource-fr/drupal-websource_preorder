<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulaire d'alerte e-mail : double opt-in, RGPD, honeypot, délai, jeton.
 */
class SubscribeForm extends FormBase {

  public function __construct(protected $subscribers, protected $mailer) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('websource_preorder.subscriber_manager'), $container->get('websource_preorder.mailer'));
  }

  public function getFormId() {
    return 'websource_preorder_subscribe_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $c = $this->config('websource_preorder.settings');
    $form['#attributes']['class'][] = 'wspo-subscribe';
    $form['#attached']['library'][] = 'websource_preorder/front';
    $form['#cache']['tags'][] = 'config:websource_preorder.settings';
    $form['#cache']['max-age'] = 3600;
    $form['title'] = ['#markup' => '<h2 class="wspo-subscribe__title">' . htmlspecialchars((string) $c->get('alerts_title'), ENT_QUOTES) . '</h2>'];
    if ($c->get('alerts_text')) {
      $form['text'] = ['#markup' => '<p class="wspo-subscribe__text">' . htmlspecialchars((string) $c->get('alerts_text'), ENT_QUOTES) . '</p>'];
    }
    $form['email'] = ['#type' => 'email', '#title' => $this->t('Votre adresse e-mail'), '#required' => TRUE, '#maxlength' => 254];
    $form['consent'] = ['#type' => 'checkbox', '#title' => (string) $c->get('alerts_consent'), '#required' => TRUE];
    // Anti-spam : champ piège masqué, horodatage (rempli en JS) et jeton.
    $form['website_url'] = [
      '#type' => 'textfield', '#title' => $this->t('Ne pas remplir'), '#required' => FALSE, '#default_value' => '',
      '#attributes' => ['autocomplete' => 'off', 'tabindex' => '-1'],
      '#wrapper_attributes' => ['class' => ['wspo-hp'], 'aria-hidden' => 'true'],
    ];
    $form['wspo_t'] = ['#type' => 'hidden', '#default_value' => '', '#attributes' => ['class' => ['wspo-t']]];
    $form['wspo_token'] = ['#type' => 'hidden', '#value' => $this->subscribers->formToken()];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Je m’abonne')];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $c = $this->config('websource_preorder.settings');
    if ($form_state->getValue('website_url') !== '' || !$this->subscribers->validFormToken((string) ($form_state->getUserInput()['wspo_token'] ?? ''))) {
      $form_state->setErrorByName('email', $this->t('Votre demande n’a pas pu être validée. Rechargez la page et réessayez.'));
      return;
    }
    $t = (int) $form_state->getValue('wspo_t');
    $min = (int) $c->get('alerts_min_delay');
    // L'horodatage est posé par JavaScript au chargement ; absent sans JS.
    if ($t > 0 && $min > 0 && (time() * 1000 - $t) < $min * 1000 && $t < time() * 1000 + 60000) {
      $form_state->setErrorByName('email', $this->t('Merci de patienter quelques secondes avant de valider.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $c = $this->config('websource_preorder.settings');
    $email = (string) $form_state->getValue('email');
    $lang = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $existing = $this->subscribers->findByEmail($email);
    if ($existing && (int) $existing->status === 1) {
      // Même message : ne révèle pas si l'adresse est déjà inscrite.
      $this->messenger()->addStatus($this->t('Merci ! Votre inscription aux alertes est prise en compte.'));
      return;
    }
    $double = (bool) $c->get('alerts_double_optin');
    $sub = $existing ?: $this->subscribers->add($email, $lang, !$double);
    if ($double) {
      $this->mailer->sendOptin($sub);
      $this->messenger()->addStatus($this->t('Merci ! Un e-mail de confirmation vient de vous être envoyé : cliquez sur le lien pour valider votre inscription.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Merci ! Votre inscription aux alertes est prise en compte.'));
    }
  }

}
