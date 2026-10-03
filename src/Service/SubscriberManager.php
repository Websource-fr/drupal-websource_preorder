<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\PrivateKey;
use Drupal\Core\Url;

/**
 * Abonnés aux alertes e-mail (double opt-in, désinscription en 1 clic).
 */
class SubscriberManager {

  const TABLE = 'websource_preorder_subscriber';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected PrivateKey $privateKey,
    protected LanguageManagerInterface $languageManager,
  ) {}

  protected function hmac(string $data): string {
    return Crypt::hmacBase64($data, $this->privateKey->get() . \Drupal::service('settings')->getHashSalt());
  }

  /** Jeton d'un abonné (confirmation / désinscription). */
  public function token(object $s, string $purpose): string {
    return $this->hmac($purpose . ':' . $s->id . ':' . $s->email);
  }

  public function validToken(object $s, string $purpose, string $token): bool {
    return hash_equals($this->token($s, $purpose), $token);
  }

  public function confirmUrl(object $s): string {
    return Url::fromRoute('websource_preorder.confirm', ['id' => $s->id, 'token' => $this->token($s, 'confirm')], ['absolute' => TRUE])->toString();
  }

  public function unsubscribeUrl(object $s): string {
    return Url::fromRoute('websource_preorder.unsubscribe', ['id' => $s->id, 'token' => $this->token($s, 'unsub')], ['absolute' => TRUE])->toString();
  }

  /** Jeton de formulaire anti-CSRF pour visiteurs anonymes (valable ~48 h). */
  public function formToken(int $offset = 0): string {
    return $this->hmac('form:' . gmdate('Y-m-d', $this->time->getRequestTime() - $offset * 86400));
  }

  public function validFormToken(string $token): bool {
    return hash_equals($this->formToken(0), $token) || hash_equals($this->formToken(1), $token);
  }

  public function findByEmail(string $email): ?object {
    $r = $this->database->select(self::TABLE, 's')->fields('s')->condition('email', mb_strtolower($email))->execute()->fetchObject();
    return $r ?: NULL;
  }

  public function load(int $id): ?object {
    $r = $this->database->select(self::TABLE, 's')->fields('s')->condition('id', $id)->execute()->fetchObject();
    return $r ?: NULL;
  }

  /**
   * Ajoute un abonné (ou renvoie l'existant).
   */
  public function add(string $email, string $langcode, bool $confirmed = FALSE): object {
    $email = mb_strtolower(trim($email));
    if ($existing = $this->findByEmail($email)) {
      return $existing;
    }
    $now = $this->time->getRequestTime();
    $id = $this->database->insert(self::TABLE)->fields([
      'email' => $email,
      'langcode' => $langcode,
      'status' => $confirmed ? 1 : 0,
      'consent' => $now,
      'created' => $now,
      'confirmed' => $confirmed ? $now : 0,
      'digest_run' => 0,
    ])->execute();
    return $this->load((int) $id);
  }

  public function confirm(int $id): void {
    $this->database->update(self::TABLE)->fields(['status' => 1, 'confirmed' => $this->time->getRequestTime()])->condition('id', $id)->execute();
  }

  public function delete(int $id): void {
    $this->database->delete(self::TABLE)->condition('id', $id)->execute();
  }

  public function query(int $limit = 0): array {
    $q = $this->database->select(self::TABLE, 's')->fields('s')->orderBy('s.id', 'DESC');
    if ($limit > 0) {
      $q = $q->extend('Drupal\Core\Database\Query\PagerSelectExtender')->limit($limit);
    }
    return $q->execute()->fetchAll();
  }

  /**
   * Identifiants des abonnés confirmés pas encore servis par l'envoi $run.
   */
  public function getConfirmedIds(int $run): array {
    return array_map('intval', $this->database->select(self::TABLE, 's')->fields('s', ['id'])
      ->condition('status', 1)->condition('digest_run', $run, '<>')->orderBy('id')->execute()->fetchCol());
  }

  public function markRun(int $id, int $run): void {
    $this->database->update(self::TABLE)->fields(['digest_run' => $run])->condition('id', $id)->execute();
  }

  public function countAll(): array {
    $q = $this->database->select(self::TABLE, 's');
    $q->addField('s', 'status');
    $q->addExpression('COUNT(*)', 'n');
    $q->groupBy('s.status');
    return $q->execute()->fetchAllKeyed();
  }

}
