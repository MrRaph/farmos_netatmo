<?php

namespace Drupal\farm_netatmo;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\data_stream\DataStreamTypeManager;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\asset\Entity\AssetInterface;

/**
 * Service pour récupérer les données Netatmo et les stocker comme DataStreams.
 */
class NetatmoService {

  /**
   * Clients et services injectés.
   */
  protected ClientInterface $httpClient;
  protected \Drupal\keyvalue\KeyValueStoreInterface $kv;
  protected LoggerChannelFactoryInterface $logger_factory;
  protected \Drupal\Core\Config\ImmutableConfig $config;
  protected \Drupal\data_stream\Plugin\DataStream\DataStreamType\Basic $basicDataStream;

  public function __construct(
    ClientInterface $http_client,
    KeyValueExpirableFactoryInterface $kv_factory,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    DataStreamTypeManager $data_stream_type_manager
  ) {
    $this->httpClient      = $http_client;
    $this->kv              = $kv_factory->get('farm_netatmo_tokens');
    $this->logger          = $logger_factory->get('farm_netatmo');
    $this->config          = $config_factory->get('farm_netatmo.settings');
    $this->basicDataStream = $data_stream_type_manager->createInstance('basic');
  }

  /**
   * Récupère les mesures Netatmo et les enregistre.
   */
  public function fetchAndStore(AssetInterface $asset): void {
    try {
      $token = $this->getAccessToken();
      $deviceId = $asset->get('field_netatmo_device_id')->value ?? NULL;
      if (!$deviceId) {
        $this->logger->warning('L\'asset @id n\'a pas d\'ID Netatmo.', ['@id' => $asset->id()]);
        return;
      }

      $response = $this->httpClient->request('GET', 'https://api.netatmo.com/api/getstationsdata', [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
        ],
        'query' => [
          'device_id' => $deviceId,
          'get_favorites' => FALSE,
        ],
      ]);

      $payload = json_decode($response->getBody()->getContents(), TRUE);
      if (!isset($payload['body']['devices'][0]['dashboard_data'])) {
        return;
      }

      $measurements = $payload['body']['devices'][0]['dashboard_data'];
      $timestamp = $measurements['time_utc'] ?? time();
      unset($measurements['time_utc']);

      $existing = $this->getBasicStreams($asset);

      foreach ($measurements as $name => $value) {
        if (!isset($existing[$name])) {
          $existing[$name] = $this->createDataStream($asset, $name);
        }
        $this->basicDataStream->saveValue($existing[$name], (float) $value, $timestamp);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error($e->getMessage());
    }
  }

  /**
   * Renvoie un access_token Netatmo valide.
   */
  protected function getAccessToken(): string {
    if ($cached = $this->kv->get('access_token')) {
      if ($this->kv->get('expires') > time()) {
        return $cached;
      }
    }

    $response = $this->httpClient->request('POST', 'https://api.netatmo.com/oauth2/token', [
      'form_params' => [
        'grant_type'    => 'password',
        'client_id'     => $this->config->get('client_id'),
        'client_secret' => $this->config->get('client_secret'),
        'username'      => $this->config->get('username'),
        'password'      => $this->config->get('password'),
        'scope'         => 'read_station',
      ],
    ]);

    $data = json_decode($response->getBody()->getContents(), TRUE);
    $this->kv->setWithExpire('access_token', $data['access_token'], $data['expires_in']);
    $this->kv->set('expires', time() + $data['expires_in']);
    return $data['access_token'];
  }

  protected function getBasicStreams(AssetInterface $asset): array {
    $streams = [];
    foreach ($asset->get('data_stream')->referencedEntities() as $stream) {
      if ($stream->bundle() === 'basic') {
        $streams[$stream->label()] = $stream;
      }
    }
    return $streams;
  }

  protected function createDataStream(AssetInterface $asset, string $name) {
    $stream = $this->basicDataStream->create([
      'type' => 'basic',
      'name' => $name,
    ]);
    $stream->save();
    $asset->get('data_stream')->appendItem($stream);
    $asset->save();
    return $stream;
  }

}
