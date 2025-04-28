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

/**
 * Service d’intégration Netatmo : gestion OAuth + import des mesures.
 */
class NetatmoService implements DestructableInterface {

  /* -----------------------------------------------------------------------
   * Propriétés
   * --------------------------------------------------------------------- */

  protected ClientInterface $httpClient;
  protected KeyValueStoreExpirableInterface $kv;
  protected LoggerChannelInterface $logger;
  protected ConfigFactoryInterface $configFactory;
  protected Config $config;
  protected \Drupal\data_stream\Plugin\DataStream\DataStreamType\Basic $basicDataStream;

  /* -----------------------------------------------------------------------
   * Constructeur
   * --------------------------------------------------------------------- */
  public function __construct(
    ClientInterface $http_client,
    KeyValueExpirableFactoryInterface $kv_factory,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    DataStreamTypeManager $stream_manager,
  ) {
    $this->httpClient      = $http_client;
    $this->kv              = $kv_factory->get('farm_netatmo_tokens');
    $this->logger          = $logger_factory->get('farm_netatmo');
    $this->configFactory   = $config_factory;
    $this->config          = $config_factory->getEditable('farm_netatmo.settings');
    $this->basicDataStream = $stream_manager->createInstance('basic');
  }

  /**
   * Checks if the service is authorized (i.e., a refresh token exists).
   *
   * @return bool
   *   TRUE if authorized, FALSE otherwise.
   */
  public function isAuthorized(): bool {
    return (bool) $this->config->get('refresh_token');
  }

  /* -----------------------------------------------------------------------
   * OAuth : helpers d’autorisation
   * --------------------------------------------------------------------- */

  /**
   * URL d’autorisation Netatmo avec state CSRF.
   */
  public function buildAuthorizeUrl(string $state): string {
    $qs = http_build_query([
      'client_id'     => $this->config->get('client_id'),
      'redirect_uri'  => $this->getRedirectUri(),
      'response_type' => 'code',
      'scope'         => 'read_station',
      'state'         => $state,
    ]);
    return 'https://api.netatmo.com/oauth2/authorize?' . $qs;
  }

  /**
   * Stocke un state temporaire (5 min) pour la sécurité OAuth.
   */
  public function storeState(string $state, int $uid): void {
    $this->kv->setWithExpire("state_$state", $uid, 300);
  }

  /**
   * Vérifie la validité d’un state reçu du callback.
   */
  public function isStateValid(?string $state): bool {
    return $state && $this->kv->get("state_$state") !== NULL;
  }

  /**
   * Échange le code d’autorisation contre access + refresh tokens.
   */
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

  /**
   * Retourne un access_token prêt à l’emploi (refresh si expiré).
   */
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
   * URL absolue du callback OAuth.
   */
  protected function getRedirectUri(): string {
    $base = \Drupal::request()->getSchemeAndHttpHost();
    return $base . '/farm/netatmo/oauth/callback';
  }

  /* -----------------------------------------------------------------------
   * API Netatmo : liste des modules
   * --------------------------------------------------------------------- */

  /**
   * Renvoie le tableau brut des appareils/modules Netatmo.
   */
  public function listDevices(): array {
    $token = $this->getAccessToken();
    $resp  = $this->httpClient->request('GET', 'https://api.netatmo.com/api/getstationsdata', [
      'headers' => ['Authorization' => 'Bearer ' . $token],
      // 'query'   => ['get_favorites' => 'false'],
    ]);
    $json = json_decode($resp->getBody()->getContents(), TRUE);
    return $json['body']['devices'] ?? [];
  }

  /* -----------------------------------------------------------------------
   * Import des mesures pour un asset
   * --------------------------------------------------------------------- */

  public function fetchAndStore(AssetInterface $asset): void {
    try {
      $token = $this->getAccessToken();
      $device_id = $asset->get('field_netatmo_device_id')->value ?? NULL;
      if (!$device_id) {
        $this->logger->warning('Asset @id sans device_id Netatmo.', ['@id' => $asset->id()]);
        return;
      }

      $resp = $this->httpClient->request('GET', 'https://api.netatmo.com/api/getstationsdata', [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        // 'query'   => ['device_id' => $device_id, 'get_favorites' => 'false'],
        'query'   => ['device_id' => $device_id],
      ]);

      $data = json_decode($resp->getBody()->getContents(), TRUE);
      if (!isset($data['body']['devices'][0]['dashboard_data'])) {
        return;
      }

      $dash     = $data['body']['devices'][0]['dashboard_data'];
      $ts       = $dash['time_utc'] ?? time();
      unset($dash['time_utc']);

      $streams = $this->getBasicStreams($asset);
      foreach ($dash as $name => $value) {
        if (!isset($streams[$name])) {
          $streams[$name] = $this->createDataStream($asset, $name);
        }
        $this->basicDataStream->saveValue($streams[$name], (float) $value, $ts);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error($e->getMessage());
    }
  }

  /* -----------------------------------------------------------------------
   * Helpers DataStream
   * --------------------------------------------------------------------- */

  protected function getBasicStreams(AssetInterface $asset): array {
    $out = [];
    foreach ($asset->get('data_stream')->referencedEntities() as $stream) {
      if ($stream->bundle() === 'basic') {
        $out[$stream->label()] = $stream;
      }
    }
    return $out;
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

  /* -----------------------------------------------------------------------
   * DestructableInterface
   * --------------------------------------------------------------------- */

  public function destruct(): void {
    // Rien à nettoyer explicitement.
  }

}
