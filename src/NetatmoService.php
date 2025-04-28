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
use Drupal\data_stream\Entity\DataPoint;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service d'intégration Netatmo pour farmOS.
 */
class NetatmoService implements DestructableInterface {

  /** @var ClientInterface */
  protected ClientInterface $httpClient;

  /** @var KeyValueStoreExpirableInterface */
  protected KeyValueStoreExpirableInterface $kv;

  /** @var LoggerChannelInterface */
  protected LoggerChannelInterface $logger;

  /** @var ConfigFactoryInterface */
  protected ConfigFactoryInterface $configFactory;

  /** @var Config */
  protected Config $config;

  /** @var object */
  protected $basicDataStream;

  /** @var EntityTypeManagerInterface */
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
    $this->httpClient = $http_client;
    $this->kv = $kv_factory->get('farm_netatmo_tokens');
    $this->logger = $logger_factory->get('farm_netatmo');
    $this->configFactory = $config_factory;
    $this->config = $config_factory->getEditable('farm_netatmo.settings');
    $this->basicDataStream = $stream_manager->createInstance('basic');
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Vérifie si un refresh token est présent.
   */
  public function isAuthorized(): bool {
    return (bool) $this->config->get('refresh_token');
  }

  /**
   * Échange le code OAuth contre un access et refresh token.
   */
  public function exchangeAuthorizationCode(string $code): void {
    $response = $this->httpClient->request('POST', 'https://api.netatmo.com/oauth2/token', [
      'form_params' => [
        'grant_type' => 'authorization_code',
        'client_id' => $this->config->get('client_id'),
        'client_secret' => $this->config->get('client_secret'),
        'redirect_uri' => $this->getRedirectUri(),
        'code' => $code,
      ],
    ]);
    $data = json_decode($response->getBody()->getContents(), TRUE);
    $this->kv->setWithExpire('access_token', $data['access_token'], $data['expires_in']);
    $this->kv->set('expires', time() + $data['expires_in']);
    $this->config->set('refresh_token', $data['refresh_token'])->save();
  }

  /**
   * Récupère un access token valide.
   */
  public function getAccessToken(): string {
    $tok = $this->kv->get('access_token');
    if ($tok && $this->kv->get('expires') > time()) {
      return $tok;
    }
    $response = $this->httpClient->request('POST', 'https://api.netatmo.com/oauth2/token', [
      'form_params' => [
        'grant_type' => 'refresh_token',
        'client_id' => $this->config->get('client_id'),
        'client_secret' => $this->config->get('client_secret'),
        'refresh_token' => $this->config->get('refresh_token'),
      ],
    ]);
    $data = json_decode($response->getBody()->getContents(), TRUE);
    $this->kv->setWithExpire('access_token', $data['access_token'], $data['expires_in']);
    $this->kv->set('expires', time() + $data['expires_in']);
    $this->config->set('refresh_token', $data['refresh_token'])->save();
    return $data['access_token'];
  }

  /**
   * Récupère la liste des modules Netatmo.
   *
   * @return array
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
          'id' => $module['_id'],
          'name' => $module['module_name'] ?? $module['type'],
        ];
      }
    }
    return $modules;
  }

  /**
   * Récupère et stocke les données des modules Netatmo.
   */
  public function fetchModuleData(): void {
    $token = $this->getAccessToken();
    $response = $this->httpClient->request('GET', 'https://api.netatmo.com/api/getstationsdata', [
      'headers' => ['Authorization' => 'Bearer ' . $token],
    ]);
    $data = json_decode($response->getBody()->getContents(), TRUE);
    foreach ($data['body']['devices'] as $station) {
      foreach ($station['modules'] as $module) {
        if (empty($module['dashboard_data'])) {
          continue;
        }
        $module_id = $module['_id'];
        $module_name = $module['module_name'] ?? $module['type'];
        $sensorName = 'Netatmo ' . $module_name;
        $sensors = $this->entityTypeManager
          ->getStorage('asset')
          ->loadByProperties(['type' => 'sensor', 'name' => $sensorName]);
        $sensor = $sensors ? reset($sensors) : NULL;
        if (!$sensor) {
          continue;
        }
        foreach ($module['dashboard_data'] as $key => $value) {
          $this->addDataToAsset($sensor, $key, $value);
        }
      }
    }
  }

  /**
   * Provisionne un asset Sensor + DataStream pour chaque module mappé.
   */
  public function provisionSensors(array $mapping): void {
    $fetched = $this->config->get('fetched_modules') ?: [];
    $nameMap = [];
    foreach ($fetched as $m) {
      $nameMap[$m['id']] = $m['name'];
    }
    $asset_storage = $this->entityTypeManager->getStorage('asset');
    $stream_storage = $this->entityTypeManager->getStorage('data_stream');
    foreach ($mapping as $module_id => $value) {
      $parent_id = is_array($value) ? ($value['asset'] ?? NULL) : $value;
      if (empty($parent_id)) {
        continue;
      }
      $label = $nameMap[$module_id] ?? $module_id;
      $sensorName = 'Netatmo ' . $label;
      $existing = $asset_storage->loadByProperties(['type' => 'sensor', 'name' => $sensorName]);
      $sensor = $existing ? reset($existing) : $asset_storage->create(['type' => 'sensor', 'name' => $sensorName]);
      if ($sensor->hasField('parent')) {
        $parent_entity = $asset_storage->load($parent_id);
        if ($parent_entity) {
          $current_ids = array_map(fn($ent) => $ent->id(), $sensor->get('parent')->referencedEntities());
          if (!in_array($parent_entity->id(), $current_ids)) {
            $sensor->get('parent')->appendItem(['target_id' => $parent_entity->id()]);
          }
        }
      }
      $existing_stream = $stream_storage->loadByProperties(['name' => $sensorName]);
      $stream = $existing_stream ? reset($existing_stream) : $stream_storage->create(['type' => 'basic', 'name' => $sensorName]);
      if (!$existing_stream) {
        $stream->save();
      }
      $attached_ids = array_map(fn($ent) => $ent->id(), $sensor->get('data_stream')->referencedEntities());
      if (!in_array($stream->id(), $attached_ids)) {
        $sensor->get('data_stream')->appendItem($stream);
      }
      $sensor->save();
    }
  }

  /**
   * Ajoute un point de donnée à un asset.
   */
  public function addDataToAsset(AssetInterface $asset, string $name, $value): DataPoint {
    $streamName = $asset->label() . ' ' . ucfirst($name);
    $stream_storage = $this->entityTypeManager->getStorage('data_stream');
    $streams = $stream_storage->loadByProperties(['name' => $streamName]);
    if ($streams) {
      $stream = reset($streams);
    }
    else {
      $stream = $stream_storage->create(['type' => 'basic', 'name' => $streamName]);
      $stream->save();
      $asset->get('data_stream')->appendItem(['target_id' => $stream->id()]);
      $asset->save();
    }
    $timestamp = \Drupal::time()->getRequestTime();
    $datapoint = DataPoint::create(['stream' => $stream->id(), 'timestamp' => $timestamp, 'value' => $value]);
    $datapoint->save();
    return $datapoint;
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    // Rien à nettoyer.
  }
}
