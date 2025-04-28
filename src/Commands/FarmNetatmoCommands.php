<?php

namespace Drupal\farmos_netatmo\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Drupal\farmos_netatmo\NetatmoService;

class FarmNetatmoCommands extends DrushCommands {

  public function __construct(
    protected NetatmoService $netatmoService,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Crée les assets sensors pour chaque module Netatmo.
   *
   * @command farmos_netatmo:create-assets
   */
  public function createAssets() {
    $devices = $this->netatmoService->listDevices();
    $asset_storage = $this->entityTypeManager->getStorage('asset');

    $created = 0;

    foreach ($devices as $device) {
      $station_id = $device['_id'];
      $station_name = $device['station_name'] ?? $device['module_name'] ?? 'Unknown station';

      // Crée l'asset pour la station de base si inexistant.
      $created += $this->createSensorAsset($station_id, $station_name);

      // Crée un asset pour chaque module enfant.
      foreach ($device['modules'] ?? [] as $module) {
        $module_id = $module['_id'];
        $module_name = $module['module_name'] ?? 'Unknown module';
        $name = "$station_name - $module_name";
        $created += $this->createSensorAsset($module_id, $name);
      }
    }

    $this->io()->success("$created asset(s) sensor créés.");
  }

  protected function createSensorAsset(string $device_id, string $name): int {
    $assets = $this->entityTypeManager
      ->getStorage('asset')
      ->loadByProperties([
        'type' => 'sensor',
        'field_netatmo_device_id' => $device_id,
      ]);

    if (!empty($assets)) {
      $this->logger()->notice("Asset déjà existant pour $device_id ($name)");
      return 0;
    }

    $asset = $this->entityTypeManager
      ->getStorage('asset')
      ->create([
        'type' => 'sensor',
        'name' => $name,
        'status' => 1,
        'field_netatmo_device_id' => $device_id,
      ]);
    $asset->save();

    $this->logger()->notice("Asset créé : $name ($device_id)");
    return 1;
  }
}
