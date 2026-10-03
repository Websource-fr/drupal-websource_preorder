<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\websource_preorder\Entity\PreorderRule;
use Psr\Log\LoggerInterface;

/**
 * E-mails personnalisables : gabarits, variables, enveloppe HTML, envoi.
 */
class PreorderMailer {

  use StringTranslationTrait;

  public function __construct(
    protected MailManagerInterface $mailManager,
    protected ConfigFactoryInterface $configFactory,
    protected LanguageManagerInterface $languageManager,
    protected RendererInterface $renderer,
    protected UrlGeneratorInterface $urlGenerator,
    protected SubscriberManager $subscribers,
    protected LoggerInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Clés d'e-mails => variables disponibles.
   */
  public static function keys(): array {
    return [
      'order' => ['{firstname}', '{lastname}', '{order_reference}', '{products_html}', '{release_info}', '{shop_name}', '{shop_url}', '{account_url}'],
      'available' => ['{firstname}', '{lastname}', '{order_reference}', '{product_name}', '{product_url}', '{release_date}', '{shop_name}', '{shop_url}', '{account_url}'],
      'admin' => ['{customer}', '{customer_email}', '{order_reference}', '{products_html}', '{shop_name}'],
      'optin' => ['{confirm_url}', '{shop_name}', '{shop_url}'],
      'digest' => ['{products_html}', '{count}', '{unsubscribe_url}', '{shop_name}', '{shop_url}', '{list_url}'],
    ];
  }

  public function labels(): array {
    return [
      'order' => $this->t('Confirmation de précommande (client)'),
      'available' => $this->t('Produit disponible (client)'),
      'admin' => $this->t('Alerte nouvelle précommande (administrateur)'),
      'optin' => $this->t('Confirmation d’inscription aux alertes (double opt-in)'),
      'digest' => $this->t('Récapitulatif périodique des précommandes (abonnés)'),
    ];
  }

  /**
   * Gabarits par défaut (français / anglais).
   */
  public static function defaults(string $key, string $langcode): array {
    $btn = 'display:inline-block;padding:10px 18px;background:#222;color:#fff;text-decoration:none;border-radius:4px';
    $d = [
      'order' => [
        'fr' => ['Votre précommande {order_reference} est bien enregistrée', '<p>Bonjour {firstname},</p><p>Merci pour votre précommande <strong>{order_reference}</strong> sur {shop_name}.</p>{products_html}<p>{release_info}</p><p>Nous vous préviendrons par e-mail dès que vos articles seront disponibles. Suivez vos précommandes ici : <a href="{account_url}">mes précommandes</a>.</p>'],
        'en' => ['Your pre-order {order_reference} is confirmed', '<p>Hello {firstname},</p><p>Thank you for your pre-order <strong>{order_reference}</strong> at {shop_name}.</p>{products_html}<p>{release_info}</p><p>We will email you as soon as your items are available. Follow your pre-orders here: <a href="{account_url}">my pre-orders</a>.</p>'],
      ],
      'available' => [
        'fr' => ['{product_name} est disponible !', '<p>Bonjour {firstname},</p><p>Bonne nouvelle : <strong>{product_name}</strong> (commande {order_reference}) est maintenant disponible. Votre commande va être préparée.</p><p><a href="{product_url}">Voir le produit</a></p>'],
        'en' => ['{product_name} is now available!', '<p>Hello {firstname},</p><p>Good news: <strong>{product_name}</strong> (order {order_reference}) is now available. Your order is being prepared.</p><p><a href="{product_url}">View the product</a></p>'],
      ],
      'admin' => [
        'fr' => ['Nouvelle précommande {order_reference}', '<p>Nouvelle précommande <strong>{order_reference}</strong> de {customer} ({customer_email}).</p>{products_html}'],
        'en' => ['New pre-order {order_reference}', '<p>New pre-order <strong>{order_reference}</strong> from {customer} ({customer_email}).</p>{products_html}'],
      ],
      'optin' => [
        'fr' => ['Confirmez votre inscription aux alertes précommandes', '<p>Bonjour,</p><p>Pour recevoir les alertes de précommandes de {shop_name}, confirmez votre adresse e-mail :</p><p><a href="{confirm_url}" style="' . $btn . '">Confirmer mon inscription</a></p><p>Si vous n\'êtes pas à l\'origine de cette demande, ignorez simplement ce message.</p>'],
        'en' => ['Confirm your pre-order alerts subscription', '<p>Hello,</p><p>To receive pre-order alerts from {shop_name}, please confirm your email address:</p><p><a href="{confirm_url}" style="' . $btn . '">Confirm my subscription</a></p><p>If you did not request this, just ignore this message.</p>'],
      ],
      'digest' => [
        'fr' => ['{count} précommande(s) à découvrir chez {shop_name}', '<p>Bonjour,</p><p>Voici les précommandes à découvrir :</p>{products_html}<p><a href="{list_url}">Voir toutes les précommandes</a></p><p style="font-size:12px;color:#888">Vous recevez ce message car vous êtes inscrit aux alertes. <a href="{unsubscribe_url}">Se désinscrire</a></p>'],
        'en' => ['{count} pre-order(s) to discover at {shop_name}', '<p>Hello,</p><p>Here are the pre-orders to discover:</p>{products_html}<p><a href="{list_url}">See all pre-orders</a></p><p style="font-size:12px;color:#888">You receive this because you subscribed to alerts. <a href="{unsubscribe_url}">Unsubscribe</a></p>'],
      ],
    ];
    $l = $langcode === 'fr' ? 'fr' : 'en';
    return ['subject' => $d[$key][$l][0], 'body' => $d[$key][$l][1]];
  }

  /**
   * Gabarit effectif (personnalisé ou par défaut).
   */
  public function getTemplate(string $key, string $langcode): array {
    $def = self::defaults($key, $langcode);
    $conf = $this->configFactory->get('websource_preorder.settings')->get('emails');
    $custom = $conf[$key][$langcode] ?? [];
    return [
      'subject' => !empty($custom['subject']) ? $custom['subject'] : $def['subject'],
      'body' => !empty($custom['body']) ? $custom['body'] : $def['body'],
    ];
  }

  /**
   * Remplace les variables {xxx} (échappées, sauf products_html).
   */
  public function render(string $text, array $vars): string {
    $raw = ['products_html', 'release_info_html'];
    foreach ($vars as $k => $v) {
      $val = in_array($k, $raw, TRUE) ? (string) $v : Html::escape((string) $v);
      $text = str_replace('{' . $k . '}', $val, $text);
    }
    return $text;
  }

  /**
   * Variables communes à tous les e-mails.
   */
  public function commonVars(): array {
    $site = $this->configFactory->get('system.site');
    return [
      'shop_name' => (string) $site->get('name'),
      'shop_url' => Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(),
      'list_url' => $this->listUrl(),
      'account_url' => Url::fromRoute('user.page', [], ['absolute' => TRUE])->toString(),
    ];
  }

  public function listUrl(): string {
    return Url::fromRoute('websource_preorder.list', [], ['absolute' => TRUE])->toString();
  }

  /**
   * Enveloppe HTML aux couleurs du site.
   */
  public function wrap(string $content): string {
    $c = $this->configFactory->get('websource_preorder.settings')->get('colors');
    $accent = Html::escape($c['accent'] ?? '#e8590c');
    $on = Html::escape($c['on_accent'] ?? '#ffffff');
    $name = Html::escape((string) $this->configFactory->get('system.site')->get('name'));
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      . '<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#222">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 0"><tr><td align="center">'
      . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:6px;overflow:hidden">'
      . '<tr><td style="background:' . $accent . ';color:' . $on . ';padding:18px 24px;font-size:20px;font-weight:bold">' . $name . '</td></tr>'
      . '<tr><td style="padding:24px;font-size:15px;line-height:1.5">' . $content . '</td></tr>'
      . '<tr><td style="padding:14px 24px;background:#fafafa;color:#888;font-size:12px">' . $name . '</td></tr>'
      . '</table></td></tr></table></body></html>';
  }

  /**
   * Envoie un e-mail personnalisé.
   *
   * @return bool
   *   TRUE si l'envoi a été accepté.
   */
  public function send(string $key, string $to, string $langcode, array $vars): bool {
    if ($to === '') {
      return FALSE;
    }
    $tpl = $this->getTemplate($key, $langcode);
    $vars += $this->commonVars();
    $subject = html_entity_decode(strip_tags($this->render($tpl['subject'], $vars)), ENT_QUOTES, 'UTF-8');
    $html = $this->wrap($this->render($tpl['body'], $vars));
    $result = $this->mailManager->mail('websource_preorder', $key, $to, $langcode, ['subject' => $subject, 'html' => $html], NULL, TRUE);
    if (empty($result['result'])) {
      $this->logger->warning('Échec d’envoi de l’e-mail « @key » à @to.', ['@key' => $key, '@to' => $to]);
    }
    return !empty($result['result']);
  }

  /**
   * Tableau HTML des produits d'une commande.
   */
  public function productsHtml(array $lines): string {
    $rows = '';
    foreach ($lines as $l) {
      $rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee">' . Html::escape($l['title']) . '</td>'
        . '<td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:center">× ' . (int) ($l['qty'] ?? 1) . '</td>'
        . '<td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right">' . Html::escape((string) ($l['info'] ?? '')) . '</td></tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:12px 0">' . $rows . '</table>';
  }

  /**
   * Libellé de date de sortie.
   */
  public function dateLabel(?int $ts, string $langcode): string {
    if (!$ts) {
      return '';
    }
    return \Drupal::service('date.formatter')->format($ts, 'custom', 'd/m/Y', NULL, $langcode);
  }

  /**
   * E-mails de confirmation client + administrateur après une commande.
   */
  public function sendOrderMails(OrderInterface $order, RuleManager $rules): void {
    $settings = $this->configFactory->get('websource_preorder.settings');
    $lines = [];
    $dates = [];
    foreach ($order->getItems() as $item) {
      $pe = $item->getPurchasedEntity();
      $rule = $pe ? $rules->getRuleForPurchasable($pe) : NULL;
      if (!$rule) {
        continue;
      }
      $rel = $rule->getDateRelease();
      $lang = $this->languageManager->getDefaultLanguage()->getId();
      $lines[] = ['title' => $item->label(), 'qty' => (int) $item->getQuantity(), 'info' => $rel ? $this->t('Sortie le @d', ['@d' => $this->dateLabel($rel, $lang)])->render() : ''];
      if ($rel) {
        $dates[] = $rel;
      }
    }
    if (!$lines) {
      return;
    }
    $customer = $order->getCustomer();
    $langcode = ($customer && !$customer->isAnonymous()) ? $customer->getPreferredLangcode() : $this->languageManager->getCurrentLanguage()->getId();
    $first = $customer && !$customer->isAnonymous() ? (string) $customer->getDisplayName() : '';
    $billing = $order->getBillingProfile();
    $lastname = '';
    if ($billing && $billing->hasField('address') && !$billing->get('address')->isEmpty()) {
      $a = $billing->get('address')->first();
      $first = (string) $a->getGivenName() ?: $first;
      $lastname = (string) $a->getFamilyName();
    }
    $release_info = $dates ? (string) $this->t('Date de sortie prévue : @d.', ['@d' => $this->dateLabel(min($dates), $langcode)]) : '';
    $ref = (string) ($order->getOrderNumber() ?: $order->id());
    $vars = [
      'firstname' => $first,
      'lastname' => $lastname,
      'order_reference' => $ref,
      'products_html' => $this->productsHtml($lines),
      'release_info' => $release_info,
    ];
    $account = ($customer && !$customer->isAnonymous())
      ? Url::fromRoute('websource_preorder.my_preorders', ['user' => $customer->id()], ['absolute' => TRUE])->toString()
      : Url::fromRoute('user.login', [], ['absolute' => TRUE])->toString();
    if ($settings->get('mail_order_on') && $order->getEmail()) {
      $this->send('order', (string) $order->getEmail(), $langcode, $vars + ['account_url' => $account]);
    }
    if ($settings->get('mail_admin_on')) {
      $admin = $settings->get('admin_email') ?: $this->configFactory->get('system.site')->get('mail');
      if ($admin) {
        $this->send('admin', (string) $admin, $this->languageManager->getDefaultLanguage()->getId(), [
          'customer' => trim($first . ' ' . $lastname) ?: (string) $order->getEmail(),
          'customer_email' => (string) $order->getEmail(),
        ] + $vars);
      }
    }
  }

  /**
   * E-mail « produit disponible » pour une réservation.
   */
  public function sendAvailable(object $reservation, ?PreorderRule $rule, $product): bool {
    $langcode = $reservation->langcode ?: $this->languageManager->getDefaultLanguage()->getId();
    $parts = preg_split('/\s+/', trim((string) $reservation->customer_name), 2);
    $url = $product ? $product->toUrl('canonical', ['absolute' => TRUE])->toString() : $this->commonVars()['shop_url'];
    $vars = [
      'firstname' => $parts[0] ?? '',
      'lastname' => $parts[1] ?? '',
      'order_reference' => (string) $reservation->order_id,
      'product_name' => (string) $reservation->product_title,
      'product_url' => $url,
      'release_date' => $rule ? $this->dateLabel($rule->getDateRelease(), $langcode) : '',
      'account_url' => $reservation->uid
        ? Url::fromRoute('websource_preorder.my_preorders', ['user' => $reservation->uid], ['absolute' => TRUE])->toString()
        : Url::fromRoute('user.login', [], ['absolute' => TRUE])->toString(),
    ];
    return $this->send('available', (string) $reservation->email, $langcode, $vars);
  }

  /**
   * E-mail de confirmation d'inscription (double opt-in).
   */
  public function sendOptin(object $subscriber): bool {
    $url = $this->subscribers->confirmUrl($subscriber);
    return $this->send('optin', (string) $subscriber->email, $subscriber->langcode ?: $this->languageManager->getDefaultLanguage()->getId(), ['confirm_url' => $url]);
  }

  /**
   * Récapitulatif pour un abonné.
   *
   * @param array $items
   *   Lignes [title, url, info].
   */
  public function sendDigest(object $subscriber, array $items): bool {
    $rows = '';
    foreach ($items as $i) {
      $rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee"><a href="' . Html::escape($i['url']) . '">' . Html::escape($i['title']) . '</a></td>'
        . '<td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right">' . Html::escape((string) $i['info']) . '</td></tr>';
    }
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:12px 0">' . $rows . '</table>';
    return $this->send('digest', (string) $subscriber->email, $subscriber->langcode ?: $this->languageManager->getDefaultLanguage()->getId(), [
      'products_html' => $html,
      'count' => (string) count($items),
      'unsubscribe_url' => $this->subscribers->unsubscribeUrl($subscriber),
    ]);
  }

  /**
   * Envoi de test avec des valeurs d'exemple.
   */
  public function sendTest(string $key, string $to, string $langcode): bool {
    $sample = [
      'firstname' => 'Camille', 'lastname' => 'Martin', 'order_reference' => 'TEST-0001',
      'products_html' => $this->productsHtml([['title' => 'Produit exemple', 'qty' => 2, 'info' => 'Sortie le 01/01/2030']]),
      'release_info' => 'Date de sortie prévue : 01/01/2030.',
      'product_name' => 'Produit exemple', 'product_url' => $this->commonVars()['shop_url'], 'release_date' => '01/01/2030',
      'confirm_url' => $this->commonVars()['shop_url'], 'unsubscribe_url' => $this->commonVars()['shop_url'],
      'count' => '3', 'customer' => 'Camille Martin', 'customer_email' => 'camille@example.com',
    ];
    return $this->send($key, $to, $langcode, $sample);
  }

}
