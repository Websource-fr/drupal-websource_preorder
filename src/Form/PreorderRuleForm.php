<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Formulaire de règle de précommande.
 */
class PreorderRuleForm extends EntityForm {

  protected function dt(?int $ts): ?DrupalDateTime {
    return $ts ? DrupalDateTime::createFromTimestamp($ts) : NULL;
  }

  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $rule = $this->entity;
    $form['label'] = ['#type' => 'textfield', '#title' => $this->t('Libellé'), '#default_value' => $rule->label(), '#required' => TRUE, '#maxlength' => 255];
    $form['id'] = [
      '#type' => 'machine_name', '#default_value' => $rule->id(), '#disabled' => !$rule->isNew(),
      '#machine_name' => ['exists' => '\Drupal\websource_preorder\Form\PreorderRuleForm::exists', 'source' => ['label']],
    ];
    $form['active'] = ['#type' => 'checkbox', '#title' => $this->t('Précommande activée'), '#default_value' => $rule->isActive()];

    $form['target'] = ['#type' => 'fieldset', '#title' => $this->t('Produit concerné')];
    $form['target']['target_type'] = [
      '#type' => 'radios', '#title' => $this->t('La règle s’applique à'),
      '#options' => ['variation' => $this->t('Une variation précise'), 'product' => $this->t('Toutes les variations d’un produit')],
      '#default_value' => $rule->getTargetType(),
    ];
    $default_var = $rule->getTargetType() === 'variation' && $rule->getTargetId() ? $this->entityTypeManager->getStorage('commerce_product_variation')->load($rule->getTargetId()) : NULL;
    $default_prod = $rule->getTargetType() === 'product' && $rule->getTargetId() ? $this->entityTypeManager->getStorage('commerce_product')->load($rule->getTargetId()) : NULL;
    $form['target']['variation'] = [
      '#type' => 'entity_autocomplete', '#target_type' => 'commerce_product_variation', '#title' => $this->t('Variation'),
      '#default_value' => $default_var, '#states' => ['visible' => [':input[name="target_type"]' => ['value' => 'variation']]],
    ];
    $form['target']['product'] = [
      '#type' => 'entity_autocomplete', '#target_type' => 'commerce_product', '#title' => $this->t('Produit'),
      '#default_value' => $default_prod, '#states' => ['visible' => [':input[name="target_type"]' => ['value' => 'product']]],
    ];

    $form['dates'] = ['#type' => 'fieldset', '#title' => $this->t('Calendrier')];
    $form['dates']['date_start'] = ['#type' => 'datetime', '#title' => $this->t('Début de la précommande'), '#default_value' => $this->dt($rule->getDateStart()), '#description' => $this->t('Vide = dès l’activation.')];
    $form['dates']['date_release'] = ['#type' => 'datetime', '#title' => $this->t('Date de sortie'), '#default_value' => $this->dt($rule->getDateRelease())];
    $form['dates']['end_on_release'] = ['#type' => 'checkbox', '#title' => $this->t('Clôturer automatiquement à la date de sortie'), '#default_value' => $rule->endsOnRelease()];
    $form['dates']['date_end'] = ['#type' => 'datetime', '#title' => $this->t('Fin anticipée (optionnelle)'), '#default_value' => $this->dt($rule->getDateEnd()), '#description' => $this->t('Ferme les précommandes avant la sortie.')];

    $form['price'] = ['#type' => 'fieldset', '#title' => $this->t('Prix de précommande')];
    $form['price']['price_mode'] = [
      '#type' => 'select', '#title' => $this->t('Mode'), '#default_value' => $rule->getPriceMode(),
      '#options' => ['none' => $this->t('Prix normal du produit'), 'fixed' => $this->t('Prix fixe'), 'percent' => $this->t('Remise en pourcentage'), 'amount' => $this->t('Remise en montant')],
    ];
    $form['price']['price_value'] = [
      '#type' => 'textfield', '#title' => $this->t('Valeur'), '#default_value' => $rule->getPriceValue(), '#size' => 12,
      '#description' => $this->t('Prix fixe ou montant dans la devise du produit ; pourcentage entre 0 et 100. Le produit n’est jamais modifié : le prix est calculé à la volée.'),
      '#states' => ['invisible' => [':input[name="price_mode"]' => ['value' => 'none']]],
    ];

    $form['limits'] = ['#type' => 'fieldset', '#title' => $this->t('Limites (0 = illimité)')];
    $form['limits']['quota'] = ['#type' => 'number', '#title' => $this->t('Quota total'), '#min' => 0, '#default_value' => $rule->getQuota()];
    $form['limits']['max_per_order'] = ['#type' => 'number', '#title' => $this->t('Maximum par commande'), '#min' => 0, '#default_value' => $rule->getMaxPerOrder()];
    $form['limits']['max_per_customer'] = ['#type' => 'number', '#title' => $this->t('Maximum par client'), '#min' => 0, '#default_value' => $rule->getMaxPerCustomer(), '#description' => $this->t('S’applique aux clients identifiés (ou à l’e-mail de la commande).')];

    $form['texts'] = ['#type' => 'fieldset', '#title' => $this->t('Textes (optionnels)')];
    $form['texts']['button_label'] = ['#type' => 'textfield', '#title' => $this->t('Libellé du bouton d’ajout au panier'), '#default_value' => $rule->getButtonLabel(), '#description' => $this->t('Vide = libellé global.')];
    $form['texts']['message'] = ['#type' => 'textarea', '#title' => $this->t('Message affiché sur la fiche produit'), '#default_value' => $rule->getMessage(), '#rows' => 3];
    return $form;
  }

  public static function exists($id): bool {
    return (bool) \Drupal::entityTypeManager()->getStorage('websource_preorder_rule')->load($id);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $type = $form_state->getValue('target_type');
    if (!$form_state->getValue($type)) {
      $form_state->setErrorByName($type, $this->t('Choisissez le produit ou la variation concerné(e).'));
    }
    $mode = $form_state->getValue('price_mode');
    $val = trim((string) $form_state->getValue('price_value'));
    if ($mode !== 'none') {
      if ($val === '' || !is_numeric($val) || (float) $val < 0) {
        $form_state->setErrorByName('price_value', $this->t('Saisissez une valeur numérique positive.'));
      }
      elseif ($mode === 'percent' && (float) $val > 100) {
        $form_state->setErrorByName('price_value', $this->t('Le pourcentage doit être compris entre 0 et 100.'));
      }
    }
    $start = $form_state->getValue('date_start');
    $rel = $form_state->getValue('date_release');
    if ($start instanceof DrupalDateTime && $rel instanceof DrupalDateTime && $start->getTimestamp() >= $rel->getTimestamp()) {
      $form_state->setErrorByName('date_release', $this->t('La date de sortie doit être postérieure au début.'));
    }
  }

  public function save(array $form, FormStateInterface $form_state) {
    $rule = $this->entity;
    $type = $form_state->getValue('target_type');
    $ts = fn ($v) => $v instanceof DrupalDateTime ? $v->getTimestamp() : NULL;
    $rule->set('target_type', $type);
    $rule->set('target_id', (int) $form_state->getValue($type));
    $rule->set('active', (bool) $form_state->getValue('active'));
    $rule->set('date_start', $ts($form_state->getValue('date_start')));
    $rule->set('date_release', $ts($form_state->getValue('date_release')));
    $rule->set('end_on_release', (bool) $form_state->getValue('end_on_release'));
    $rule->set('date_end', $ts($form_state->getValue('date_end')));
    $rule->set('price_mode', $form_state->getValue('price_mode'));
    $rule->set('price_value', $form_state->getValue('price_mode') === 'none' ? '' : trim((string) $form_state->getValue('price_value')));
    foreach (['quota', 'max_per_order', 'max_per_customer'] as $k) {
      $rule->set($k, max(0, (int) $form_state->getValue($k)));
    }
    $rule->set('button_label', trim((string) $form_state->getValue('button_label')));
    $rule->set('message', (string) $form_state->getValue('message'));
    $status = $rule->save();
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['websource_preorder_rules']);
    $this->messenger()->addStatus($this->t('Règle « @l » enregistrée.', ['@l' => $rule->label()]));
    $form_state->setRedirect('entity.websource_preorder_rule.collection');
    return $status;
  }

}
