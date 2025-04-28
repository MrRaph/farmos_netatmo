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

    foreach ($mapping as $module_id => $parent_id) {
      $label = $nameMap[$module_id] ?? $module_id;
      $sensorName = 'Netatmo ' . $label;

      // Réutilise ou crée le sensor.
      $existing = $asset_storage->loadByProperties(['type' => 'sensor', 'name' => $sensorName]);
      $sensor = $existing ? reset($existing) : $asset_storage->create(['type' => 'sensor', 'name' => $sensorName]);

      // Associer le parent via le base-field 'parent'.
      if ($sensor->hasField('parent')) {
        $current = array_column($sensor->get('parent')->getValue(), 'target_id');
        if (!in_array($parent_id, $current)) {
          $sensor->get('parent')->appendItem(['target_id' => $parent_id]);
        }
      }

      // Réutilise ou crée le DataStream.
      $existing_stream = $stream_storage->loadByProperties(['name' => $sensorName]);
      $stream = $existing_stream ? reset($existing_stream) : $stream_storage->create(['type' => 'basic', 'name' => $sensorName]);
      if (!$existing_stream) {
        $stream->save();
      }

      // Attache le flux au capteur.
      $attached = array_column($sensor->get('data_stream')->getValue(), 'target_id');
      if (!in_array($stream->id(), $attached)) {
        $sensor->get('data_stream')->appendItem(['target_id' => $stream->id()]);
      }

      $sensor->save();
    }
  }

  /**
   * Ajoute un point de donnée à un asset.
   */
  public function addDataToAsset(AssetInterface $asset, string $name, $value): object {
    // Votre logique existante.
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    // Rien à nettoyer.
  }
}
