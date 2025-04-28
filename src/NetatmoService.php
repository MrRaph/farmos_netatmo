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

  /**
   * Client HTTP Guzzle.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Stockage clé/valeur expirable pour les tokens.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected KeyValueStoreExpirableInterface $kv;

  /**
   * Logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Usine de configuration.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Configuration editable du module.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $config;

  /**
   * Plugin basique de flux de données (non typé pour accepter l'instance renvoyée).
   *
   * @var object
   */
  protected $basicDataStream;

  /**
   * Le gestionnaire d’entités.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
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
   *
   * @return bool
   *   TRUE si autorisé, FALSE sinon.
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
   *
   * @return array
   *   Tableau de modules ['id'=>…,'name'=>…].
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
   *
   * @param array $mapping
   *   module_id => parent_asset_id.
   */
  public function provisionSensors(array $mapping): void {
    $asset_storage = $this->entityTypeManager->getStorage('asset');
    $stream_storage = $this->entityTypeManager->getStorage('data_stream');

    foreach ($mapping as $module_id => $parent_id) {
      // Créer l’asset sensor.
      /** @var \Drupal\asset\Entity\AssetInterface $sensor */
      $sensor = $asset_storage->create([
        'type'         => 'sensor',
        'name'         => 'Netatmo: ' . $module_id,
        'field_parent' => $parent_id,
      ]);
      $sensor->save();

      // Créer le DataStream en tant qu’entité.
      /** @var \Drupal\data_stream\Entity\DataStream $stream */
      $stream = $stream_storage->create([
        'type' => 'basic',
        'name' => 'Netatmo: ' . $module_id,
      ]);
      $stream->save();

      // Lier le stream à l’asset sensor.
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

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    // Rien à nettoyer.
  }
}
