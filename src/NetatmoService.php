<?php

namespace Drupal\farm_netatmo;

use Drupal\asset\Entity\AssetInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\DestructableInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\data_stream\DataStreamTypeManager;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service d'intégration Netatmo pour farmOS.
 */
class NetatmoService implements DestructableInterface {

  /** @var \GuzzleHttp\ClientInterface */
  protected ClientInterface $httpClient;

  /** @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface */
  protected KeyValueStoreExpirableInterface $kv;

  /** @var \Drupal\Core\Logger\LoggerChannelInterface */
  protected LoggerChannelInterface $logger;

  /** @var \Drupal\Core\Config\ConfigFactoryInterface */
  protected ConfigFactoryInterface $configFactory;

  /** @var \Drupal\Core\Config\Config */
  protected Config $config;

  /** @var object */
  protected $basicDataStream;

  /** @var \Drupal\Core\Entity\EntityTypeManagerInterface */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructeur.
   */
  public function __construct(
    ClientInterface $http_client,
    KeyValueExpirableFactoryInterface $kv_factory,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    DataStreamTypeManager $stream_manager,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->httpClient        = $http_client;
    $this->kv                = $kv_factory->get('farm_netatmo_tokens');
    $this->logger            = $logger_factory->get('farm_netatmo');
    $this->configFactory     = $config_factory;
    $this->config            = $config_factory->getEditable('farm_netatmo.settings');
    $this->basicDataStream   = $stream_manager->createInstance('basic');
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Vérifie si un refresh_token est présent.
   */
  public function isAuthorized(): bool {
    return (bool) $this->config->get('refresh_token');
  }

  /* -----------------------------------------------------------------------
   * OAuth : helpers
   * --------------------------------------------------------------------- */

  public function exchangeAuthorizationCode(string $code): void {
    $response = $this->httpClient->request('POST', 'https://api.netatmo.com/oauth2/token', [
      'form_params' => [
        'grant_type'    => 'authorization_code',
        'client_id'     => $this->config->get('client_id'),
        'client_secret' => $this->config->get('client_secret'),
        'redirect_uri'  => $this->getRedirectUri(),
        'code'          => $code,
      ],
    ]);
    $data = json_decode($response->getBody()->getContents(), TRUE);
    $this->kv->setWithExpire('access_token', $data['access_token'], $data['expires_in']);
    $this->kv->set('expires', time() + $data['expires_in']);
    $this->config
      ->set('refresh_token', $data['refresh_token'])
      ->save();
  }

  public function getAccessToken(): string {
    if (($tok = $this->kv->get('access_token')) && $this->kv->get('expires') > time()) {
      return $tok;
    }
    $response = $this->httpClient->request('POST', 'https://api.netatmo.com/oauth2/token', [
      'form_params' => [
        'grant_type'    => 'refresh_token',
        'client_id'     => $this->config->get('client_id'),
        'client_secret' => $this->config->get('client_secret'),
        'refresh_token' => $this->config->get('refresh_token'),
      ],
    ]);
    $data = json_decode($response->getBody()->getContents(), TRUE);
    $this->kv->setWithExpire('access_token', $data['access_token'], $data['expires_in']);
    $this->kv->set('expires', time() + $data['expires_in']);
    $this->config
      ->set('refresh_token', $data['refresh_token'])
      ->save();
    return $data['access_token'];
  }

  /**
   * Récupère la liste des modules Netatmo (pas les stations).
   */
  public function getModules(): array {
    $token = $this->getAccessToken();
    $response = $this->httpClient->request('GET', 'https://api.netatmo.com/api/getstationsdata', [
      'headers' => ['Authorization' => 'Bearer ' . $token],
    ]);
    $data = json_decode($response->getBody()->getContents(), TRUE);
    $modules = [];
    foreach ($data['body']['devices'] as $station) {
      if (empty($station['modules'])) {
        continue;
      }
      foreach ($station['modules'] as $module) {
        $modules[] = [
          'id'   => $module['_id'],
          'name' => $module['module_name'] ?? $module['type'],
        ];
      }
    }
    return $modules;
  }

  /**
   * Provisionne un asset Sensor + DataStream pour chaque module mappé.
   */
  public function provisionSensors(array $mapping): void {
    // Map module_id to name.
    $fetched = $this->config->get('fetched_modules') ?: [];
    $nameMap = [];
    foreach ($fetched as $m) {
      $nameMap[$m['id']] = $m['name'];
    }

    $asset_storage  = $this->entityTypeManager->getStorage('asset');
    $stream_storage = $this->entityTypeManager->getStorage('data_stream');

    foreach ($mapping as $module_id => $parent_id) {
      $label      = $nameMap[$module_id] ?? $module_id;
      $sensorName = 'Netatmo ' . $label;

      // Créer l’asset sensor.
      $sensor = $asset_storage->create([
        'type' => 'sensor',
        'name' => $sensorName,
      ]);
      $sensor->save();

                  // Associer le parent (référence multiple sur "field_parents")..
                  $sensor->get('field_parents')->appendItem(['target_id' => $parent_id]);
      $sensor->save();

      // Créer le DataStream.
      $stream = $stream_storage->create([
        'type' => 'basic',
        'name' => $sensorName,
      ]);
      $stream->save();

      // Attacher le flux au capteur.
      $sensor->get('data_stream')->appendItem($stream);
      $sensor->save();
    }
  }

  /**
   * Ajouter un point de donnée à un asset.
   */
  public function addDataToAsset(AssetInterface $asset, string $name, $value): object {
    // … votre logique existante …
  }

  public function destruct(): void {}
}
