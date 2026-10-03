<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Envoie un lot du récapitulatif périodique.
 *
 * @QueueWorker(
 *   id = "websource_preorder_digest",
 *   title = @Translation("Récapitulatif précommandes"),
 *   cron = {"time" = 60}
 * )
 */
class DigestWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected $digest) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('websource_preorder.digest'));
  }

  public function processItem($data) {
    $this->digest->processBatch($data);
  }

}
