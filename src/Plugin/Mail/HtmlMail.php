<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Plugin\Mail;

use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Mail\Plugin\Mail\PhpMail;

/**
 * Envoi HTML des e-mails du module.
 *
 * Délègue au système d'envoi par défaut du site (SMTP, Symfony Mailer, etc.)
 * s'il est différent de PHP mail(). Avec PHP mail(), conserve le HTML au lieu
 * de le convertir en texte brut.
 *
 * @Mail(
 *   id = "websource_preorder_html",
 *   label = @Translation("Websource Précommandes : e-mail HTML"),
 *   description = @Translation("Envoie les e-mails du module en HTML en s'appuyant sur le système d'envoi du site.")
 * )
 */
class HtmlMail extends PhpMail {

  /**
   * Retourne le système d'envoi par défaut du site, s'il y en a un autre.
   */
  protected function delegate(): ?MailInterface {
    $default = \Drupal::config('system.mail')->get('interface.default');
    if (!$default || $default === 'php_mail' || $default === 'websource_preorder_html') {
      return NULL;
    }
    try {
      $plugin = \Drupal::service('plugin.manager.mail')->createInstance($default);
      return $plugin instanceof MailInterface ? $plugin : NULL;
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    if ($delegate = $this->delegate()) {
      return $delegate->format($message);
    }
    $message['body'] = implode("\n", array_map('strval', $message['body']));
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    if ($delegate = $this->delegate()) {
      return $delegate->mail($message);
    }
    return parent::mail($message);
  }

}
