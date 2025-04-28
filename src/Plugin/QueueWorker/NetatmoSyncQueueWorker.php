<?php

namespace Drupal\farmos_netatmo\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\farmos_netatmo\NetatmoService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @QueueWorker(
 *   id = "farmos_netatmo_sync",
 *   title = @Translation("Synchronise les mesures Netatmo"),
 *   cron = {"time" = 60}
 * )
 */
class NetatmoSyncQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected NetatmoService $netatmoService;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    NetatmoService $netatmo_service,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->netatmoService = $netatmo_service;
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('farmos_netatmo.netatmo_service'),
      $container->get('entity_type.manager')
    );
  }

  public function processItem($asset_id): void {
    if ($asset = $this->entityTypeManager->getStorage('asset')->load($asset_id)) {
      $this->netatmoService->fetchAndStore($asset);
    }
  }

}
