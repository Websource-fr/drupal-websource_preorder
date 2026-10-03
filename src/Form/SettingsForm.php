<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Form;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\websource_preorder\Service\PreorderMailer;

/**
 * Réglages du module : onglets verticaux.
 */
class SettingsForm extends ConfigFormBase {

  public function getFormId() {
    return 'websource_preorder_settings_form';
  }

  protected function getEditableConfigNames() {
    return ['websource_preorder.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $c = $this->config('websource_preorder.settings');
    $form['#attached']['library'][] = 'websource_preorder/admin';
    $form['tabs'] = ['#type' => 'vertical_tabs', '#default_tab' => 'edit-general'];

    // Tableau de bord rapide.
    $res = \Drupal::service('websource_preorder.reservation_manager')->countByStatus();
    $running = count(\Drupal::service('websource_preorder.rule_manager')->getRunningRules());
    $form['quick'] = [
      '#type' => 'container', '#weight' => -10,
      'text' => ['#markup' => '<p class="wspo-quick">' . $this->t('<strong>@r</strong> précommande(s) en cours, <strong>@a</strong> réservation(s) active(s). <a href=":rules">Règles</a> · <a href=":res">Réservations</a> · <a href=":sub">Abonnés</a>', [
        '@r' => $running, '@a' => (int) ($res['active'] ?? 0),
        ':rules' => Url::fromRoute('entity.websource_preorder_rule.collection')->toString(),
        ':res' => Url::fromRoute('websource_preorder.reservations')->toString(),
        ':sub' => Url::fromRoute('websource_preorder.subscribers')->toString(),
      ]) . '</p>'],
    ];

    // Général.
    $form['general'] = ['#type' => 'details', '#title' => $this->t('Général'), '#group' => 'tabs'];
    $form['general']['btn_label'] = ['#type' => 'textfield', '#title' => $this->t('Libellé du bouton d’ajout au panier'), '#default_value' => $c->get('btn_label')];
    $form['general']['badge_label'] = ['#type' => 'textfield', '#title' => $this->t('Libellé du badge'), '#default_value' => $c->get('badge_label')];
    $form['general']['cart_notice'] = ['#type' => 'textarea', '#rows' => 2, '#title' => $this->t('Notice du panier'), '#default_value' => $c->get('cart_notice'), '#description' => $this->t('Affichée au-dessus du panier s’il contient une précommande. Vide = aucune notice.')];
    $form['general']['forbid_mixed'] = ['#type' => 'checkbox', '#title' => $this->t('Interdire le mélange précommandes / produits en stock dans un même panier'), '#default_value' => $c->get('forbid_mixed')];
    $form['general']['show_flag'] = ['#type' => 'checkbox', '#title' => $this->t('Afficher le badge dans les listes de produits'), '#default_value' => $c->get('show_flag')];
    $form['general']['admin_email'] = ['#type' => 'email', '#title' => $this->t('E-mail de l’administrateur'), '#default_value' => $c->get('admin_email'), '#description' => $this->t('Reçoit l’alerte de nouvelle précommande. Vide = e-mail du site.')];
    $form['general']['keep_data'] = ['#type' => 'checkbox', '#title' => $this->t('Conserver les données (règles, réservations, abonnés, réglages) à la désinstallation'), '#default_value' => $c->get('keep_data')];

    // Compteur.
    $form['counter'] = ['#type' => 'details', '#title' => $this->t('Compteur'), '#group' => 'tabs'];
    $form['counter']['show_countdown'] = ['#type' => 'checkbox', '#title' => $this->t('Afficher le compte à rebours'), '#default_value' => $c->get('show_countdown')];
    $form['counter']['countdown_mode'] = ['#type' => 'select', '#title' => $this->t('Unités du compte à rebours'), '#default_value' => $c->get('countdown_mode'), '#options' => [
      'auto' => $this->t('Automatique (selon le temps restant)'), 'd' => $this->t('Jours'), 'dh' => $this->t('Jours + heures'),
      'dhm' => $this->t('Jours + heures + minutes'), 'dhms' => $this->t('Jours + heures + minutes + secondes'),
    ]];
    $form['counter']['show_progress'] = ['#type' => 'checkbox', '#title' => $this->t('Afficher la barre de progression vers la sortie'), '#default_value' => $c->get('show_progress')];
    $form['counter']['show_quota'] = ['#type' => 'checkbox', '#title' => $this->t('Afficher la quantité restante'), '#default_value' => $c->get('show_quota')];

    // Couleurs.
    $form['colors'] = ['#type' => 'details', '#title' => $this->t('Couleurs'), '#group' => 'tabs', '#tree' => TRUE];
    $labels = [
      'accent' => $this->t('Couleur principale'), 'on_accent' => $this->t('Texte sur la couleur principale'), 'box_bg' => $this->t('Fond du bloc'),
      'border' => $this->t('Bordure'), 'count_bg' => $this->t('Fond du compteur'), 'count_text' => $this->t('Texte du compteur'),
      'badge_bg' => $this->t('Fond du badge'), 'ticker_bg' => $this->t('Fond du bandeau'), 'ticker_text' => $this->t('Texte du bandeau'),
    ];
    foreach ($labels as $k => $l) {
      $form['colors'][$k] = ['#type' => 'color', '#title' => $l, '#default_value' => $c->get('colors.' . $k)];
    }

    // Accueil & bandeau.
    $form['home'] = ['#type' => 'details', '#title' => $this->t('Accueil & bandeau'), '#group' => 'tabs'];
    $form['home']['featured_enabled'] = ['#type' => 'checkbox', '#title' => $this->t('Activer le produit à la une (bloc « Précommande à la une »)'), '#default_value' => $c->get('featured_enabled'), '#description' => $this->t('Placez le bloc dans la région de votre choix via Structure → Mise en page des blocs.')];
    $form['home']['featured_title'] = ['#type' => 'textfield', '#title' => $this->t('Titre du produit à la une'), '#default_value' => $c->get('featured_title')];
    $form['home']['ticker_enabled'] = ['#type' => 'checkbox', '#title' => $this->t('Activer le bandeau défilant (bloc « Bandeau des précommandes »)'), '#default_value' => $c->get('ticker_enabled')];
    $form['home']['ticker_prefix'] = ['#type' => 'textfield', '#title' => $this->t('Préfixe du bandeau'), '#default_value' => $c->get('ticker_prefix')];
    $form['home']['ticker_speed'] = ['#type' => 'number', '#title' => $this->t('Vitesse (pixels par seconde)'), '#min' => 10, '#max' => 400, '#default_value' => $c->get('ticker_speed'), '#description' => $this->t('Le défilement est désactivé si le visiteur demande moins d’animations (prefers-reduced-motion).')];
    $form['home']['ticker_max'] = ['#type' => 'number', '#title' => $this->t('Nombre maximum de produits'), '#min' => 1, '#max' => 50, '#default_value' => $c->get('ticker_max')];

    // Blocs comptes.
    $form['accounts'] = ['#type' => 'details', '#title' => $this->t('Blocs comptes'), '#group' => 'tabs', '#tree' => TRUE];
    $pages = ['login' => $this->t('Page de connexion'), 'register' => $this->t('Page d’inscription'), 'password' => $this->t('Page mot de passe oublié')];
    foreach ($pages as $k => $l) {
      $form['accounts'][$k] = ['#type' => 'fieldset', '#title' => $l];
      $form['accounts'][$k]['products'] = ['#type' => 'checkbox', '#title' => $this->t('Afficher les prochains produits en précommande'), '#default_value' => $c->get("accounts.$k.products")];
      $form['accounts'][$k]['form'] = ['#type' => 'checkbox', '#title' => $this->t('Afficher le formulaire d’alerte e-mail'), '#default_value' => $c->get("accounts.$k.form")];
    }
    $form['accounts']['accounts_title'] = ['#type' => 'textfield', '#title' => $this->t('Titre du bloc'), '#default_value' => $c->get('accounts_title'), '#parents' => ['accounts_title']];
    $form['accounts']['accounts_text'] = ['#type' => 'textarea', '#rows' => 2, '#title' => $this->t('Texte du bloc'), '#default_value' => $c->get('accounts_text'), '#parents' => ['accounts_text']];
    $form['accounts']['accounts_max'] = ['#type' => 'number', '#min' => 1, '#max' => 20, '#title' => $this->t('Nombre de produits affichés'), '#default_value' => $c->get('accounts_max'), '#parents' => ['accounts_max']];

    // Page liste.
    $form['list'] = ['#type' => 'details', '#title' => $this->t('Page liste'), '#group' => 'tabs'];
    $form['list']['list_slug'] = ['#type' => 'textfield', '#title' => $this->t('Chemin de la page'), '#field_prefix' => '/', '#default_value' => $c->get('list_slug'), '#description' => $this->t('Lettres, chiffres, tirets et barres obliques. Exemple : precommandes')];
    $form['list']['list_title'] = ['#type' => 'textfield', '#title' => $this->t('Titre de la page'), '#default_value' => $c->get('list_title')];
    $form['list']['list_intro'] = ['#type' => 'textarea', '#rows' => 3, '#title' => $this->t('Texte d’introduction'), '#default_value' => $c->get('list_intro')];
    $form['list']['list_group_by_month'] = ['#type' => 'checkbox', '#title' => $this->t('Grouper les produits par mois de sortie'), '#default_value' => $c->get('list_group_by_month')];

    // Alertes.
    $form['alerts'] = ['#type' => 'details', '#title' => $this->t('Alertes'), '#group' => 'tabs'];
    $form['alerts']['alerts_enabled'] = ['#type' => 'checkbox', '#title' => $this->t('Activer les alertes e-mail (bloc « Alerte précommandes »)'), '#default_value' => $c->get('alerts_enabled')];
    $form['alerts']['alerts_title'] = ['#type' => 'textfield', '#title' => $this->t('Titre'), '#default_value' => $c->get('alerts_title')];
    $form['alerts']['alerts_text'] = ['#type' => 'textarea', '#rows' => 2, '#title' => $this->t('Texte'), '#default_value' => $c->get('alerts_text')];
    $form['alerts']['alerts_consent'] = ['#type' => 'textarea', '#rows' => 2, '#title' => $this->t('Texte de consentement (RGPD)'), '#default_value' => $c->get('alerts_consent')];
    $form['alerts']['alerts_double_optin'] = ['#type' => 'checkbox', '#title' => $this->t('Double opt-in (e-mail de confirmation)'), '#default_value' => $c->get('alerts_double_optin')];
    $form['alerts']['alerts_min_delay'] = ['#type' => 'number', '#min' => 0, '#max' => 60, '#title' => $this->t('Délai minimum avant validation (secondes, anti-spam)'), '#default_value' => $c->get('alerts_min_delay')];

    // Cron.
    $token = (string) $c->get('cron_token');
    $url = Url::fromRoute('websource_preorder.cron', ['token' => $token ?: 'JETON'], ['absolute' => TRUE])->toString();
    $form['cron'] = ['#type' => 'details', '#title' => $this->t('Cron & récapitulatif'), '#group' => 'tabs'];
    $form['cron']['digest_enabled'] = ['#type' => 'checkbox', '#title' => $this->t('Envoyer un récapitulatif périodique aux abonnés'), '#default_value' => $c->get('digest_enabled')];
    $form['cron']['digest_times'] = ['#type' => 'number', '#min' => 1, '#max' => 48, '#title' => $this->t('Nombre d’envois'), '#default_value' => $c->get('digest_times')];
    $form['cron']['digest_freq'] = ['#type' => 'select', '#title' => $this->t('par'), '#default_value' => $c->get('digest_freq'), '#options' => ['day' => $this->t('jour'), 'week' => $this->t('semaine'), 'month' => $this->t('mois')]];
    $form['cron']['digest_mode'] = ['#type' => 'radios', '#title' => $this->t('Contenu'), '#default_value' => $c->get('digest_mode'), '#options' => ['all' => $this->t('Toutes les précommandes ouvertes'), 'new' => $this->t('Seulement les nouveautés depuis le dernier envoi')]];
    $form['cron']['digest_max'] = ['#type' => 'number', '#min' => 1, '#max' => 100, '#title' => $this->t('Nombre maximum de produits par e-mail'), '#default_value' => $c->get('digest_max')];
    $form['cron']['digest_batch'] = ['#type' => 'number', '#min' => 1, '#max' => 500, '#title' => $this->t('Taille des lots d’envoi'), '#default_value' => $c->get('digest_batch'), '#description' => $this->t('Les envois sont mis en file (Queue API) et reprennent automatiquement s’ils sont interrompus. Aucun e-mail n’est envoyé s’il n’y a rien à annoncer.')];
    $form['cron']['cron_info'] = ['#markup' => '<p>' . $this->t('Le cron Drupal exécute ces tâches automatiquement. Vous pouvez aussi planifier cette adresse sécurisée :') . '</p><pre>*/15 * * * * curl -s "' . htmlspecialchars($url) . '" &gt; /dev/null</pre>'];
    $form['cron']['regen_token'] = ['#type' => 'checkbox', '#title' => $this->t('Régénérer le jeton du cron à l’enregistrement')];
    $form['cron']['run_now'] = ['#type' => 'submit', '#value' => $this->t('Lancer le récapitulatif maintenant'), '#submit' => ['::runDigestNow'], '#limit_validation_errors' => []];

    // E-mails.
    $form['emails'] = ['#type' => 'details', '#title' => $this->t('E-mails'), '#group' => 'tabs'];
    $form['emails']['mail_order_on'] = ['#type' => 'checkbox', '#title' => $this->t('Envoyer la confirmation de précommande au client'), '#default_value' => $c->get('mail_order_on')];
    $form['emails']['mail_available_on'] = ['#type' => 'checkbox', '#title' => $this->t('Envoyer l’e-mail « produit disponible » à la sortie'), '#default_value' => $c->get('mail_available_on')];
    $form['emails']['mail_admin_on'] = ['#type' => 'checkbox', '#title' => $this->t('Prévenir l’administrateur à chaque précommande'), '#default_value' => $c->get('mail_admin_on')];
    $mailer = \Drupal::service('websource_preorder.mailer');
    $langs = \Drupal::languageManager()->getLanguages();
    $form['emails']['templates'] = ['#tree' => TRUE, '#type' => 'container'];
    foreach ($mailer->labels() as $key => $label) {
      $vars = implode(' ', PreorderMailer::keys()[$key]);
      $form['emails']['templates'][$key] = ['#type' => 'details', '#title' => $label, '#open' => FALSE, '#description' => $this->t('Variables : @v', ['@v' => $vars])];
      foreach ($langs as $lang) {
        $tpl = $mailer->getTemplate($key, $lang->getId());
        $form['emails']['templates'][$key][$lang->getId()] = ['#type' => 'fieldset', '#title' => $lang->getName()];
        $form['emails']['templates'][$key][$lang->getId()]['subject'] = ['#type' => 'textfield', '#title' => $this->t('Objet'), '#default_value' => $tpl['subject'], '#maxlength' => 255];
        $form['emails']['templates'][$key][$lang->getId()]['body'] = ['#type' => 'textarea', '#title' => $this->t('Corps HTML'), '#default_value' => $tpl['body'], '#rows' => 8];
      }
    }
    $form['emails']['test'] = ['#type' => 'details', '#title' => $this->t('Envoyer un test'), '#open' => TRUE, '#tree' => TRUE];
    $form['emails']['test']['key'] = ['#type' => 'select', '#title' => $this->t('E-mail'), '#options' => $mailer->labels()];
    $form['emails']['test']['to'] = ['#type' => 'email', '#title' => $this->t('Destinataire'), '#default_value' => $this->currentUser()->getEmail()];
    $lopts = [];
    foreach ($langs as $l) {
      $lopts[$l->getId()] = $l->getName();
    }
    $form['emails']['test']['lang'] = ['#type' => 'select', '#title' => $this->t('Langue'), '#options' => $lopts, '#default_value' => \Drupal::languageManager()->getDefaultLanguage()->getId()];
    $form['emails']['test']['send'] = ['#type' => 'submit', '#value' => $this->t('Envoyer un test'), '#submit' => ['::sendTest'], '#limit_validation_errors' => [['test']]];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $slug = trim((string) $form_state->getValue('list_slug'), '/');
    if (!preg_match('#^[a-z0-9][a-z0-9\-_/]*$#i', $slug)) {
      $form_state->setErrorByName('list_slug', $this->t('Chemin invalide.'));
    }
    foreach ((array) $form_state->getValue('colors') as $k => $v) {
      if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v)) {
        $form_state->setErrorByName("colors][$k", $this->t('Couleur invalide.'));
      }
    }
  }

  public function sendTest(array &$form, FormStateInterface $form_state) {
    $t = $form_state->getValue('test');
    $ok = \Drupal::service('websource_preorder.mailer')->sendTest((string) $t['key'], (string) $t['to'], (string) $t['lang']);
    $ok ? $this->messenger()->addStatus($this->t('E-mail de test envoyé à @e (modèle enregistré ; pensez à enregistrer vos modifications avant de tester).', ['@e' => $t['to']]))
      : $this->messenger()->addError($this->t('Échec de l’envoi du test.'));
    $form_state->setRebuild();
  }

  public function runDigestNow(array &$form, FormStateInterface $form_state) {
    $n = \Drupal::service('websource_preorder.digest')->run(TRUE);
    $n ? $this->messenger()->addStatus($this->t('@n lot(s) d’envoi mis en file ; ils partiront au prochain cron.', ['@n' => $n]))
      : $this->messenger()->addWarning($this->t('Rien à envoyer (aucune précommande à annoncer, aucun abonné confirmé ou envoi déjà en cours).'));
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $c = $this->config('websource_preorder.settings');
    $simple = ['btn_label', 'badge_label', 'cart_notice', 'admin_email', 'show_flag', 'forbid_mixed', 'keep_data', 'show_countdown', 'countdown_mode',
      'show_progress', 'show_quota', 'featured_enabled', 'featured_title', 'ticker_enabled', 'ticker_prefix', 'ticker_speed', 'ticker_max',
      'accounts_title', 'accounts_text', 'accounts_max', 'list_title', 'list_intro', 'list_group_by_month', 'alerts_enabled', 'alerts_title',
      'alerts_text', 'alerts_consent', 'alerts_double_optin', 'alerts_min_delay', 'digest_enabled', 'digest_times', 'digest_freq', 'digest_mode',
      'digest_max', 'digest_batch', 'mail_order_on', 'mail_available_on', 'mail_admin_on'];
    $ints = ['ticker_speed', 'ticker_max', 'accounts_max', 'alerts_min_delay', 'digest_times', 'digest_max', 'digest_batch'];
    $bools = ['show_flag', 'forbid_mixed', 'keep_data', 'show_countdown', 'show_progress', 'show_quota', 'featured_enabled', 'ticker_enabled', 'list_group_by_month', 'alerts_enabled', 'alerts_double_optin', 'digest_enabled', 'mail_order_on', 'mail_available_on', 'mail_admin_on'];
    foreach ($simple as $k) {
      $v = $form_state->getValue($k);
      $c->set($k, in_array($k, $ints, TRUE) ? (int) $v : (in_array($k, $bools, TRUE) ? (bool) $v : (string) $v));
    }
    $c->set('list_slug', trim((string) $form_state->getValue('list_slug'), '/'));
    foreach ((array) $form_state->getValue('colors') as $k => $v) {
      $c->set('colors.' . $k, (string) $v);
    }
    foreach (['login', 'register', 'password'] as $p) {
      $c->set("accounts.$p.products", (bool) $form_state->getValue(['accounts', $p, 'products']));
      $c->set("accounts.$p.form", (bool) $form_state->getValue(['accounts', $p, 'form']));
    }
    if ($form_state->getValue('regen_token') || !$c->get('cron_token')) {
      $c->set('cron_token', Crypt::randomBytesBase64(24));
    }
    // Modèles : on n'enregistre que ce qui diffère du défaut.
    $emails = [];
    foreach ((array) $form_state->getValue('templates') as $key => $langs) {
      foreach ($langs as $lang => $vals) {
        $def = PreorderMailer::defaults($key, $lang);
        $subj = trim((string) $vals['subject']);
        $body = (string) $vals['body'];
        if ($subj !== $def['subject'] || $body !== $def['body']) {
          $emails[$key][$lang] = ['subject' => $subj, 'body' => $body];
        }
      }
    }
    $c->set('emails', $emails);
    $c->save();
    \Drupal::service('router.builder')->setRebuildNeeded();
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['websource_preorder_rules']);
    parent::submitForm($form, $form_state);
  }

}
