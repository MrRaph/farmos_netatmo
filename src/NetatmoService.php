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
 * Service d'intégration Netatmo pour farmOS.
 */
class NetatmoService implements DestructableInterface {

  /* -----------------------------------------------------------------------
   * Propriétés
   * --------------------------------------------------------------------- */

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
   * Gestionnaire de types de flux de données.
   *
   * @var \Drupal\data_stream\DataStreamTypeManager
   */
  /**
   * Type de flux de données basique (plugin Netatmo DataStream).
   *
   * @var object
   */
  protected $basicDataStream;

  /* -----------------------------------------------------------------------
   * Constructeur
   * --------------------------------------------------------------------- */
  public function __construct(
    ClientInterface $http_client,
    KeyValueExpirableFactoryInterface $kv_factory,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    DataStreamTypeManager $stream_manager
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
   * Échange le code OAuth contre un access_token et refresh_token.
   *
   * @param string $code
   *   Code d'autorisation fourni par Netatmo.
   *
   * @throws \Exception
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
   * Récupère un access token valide (rafraîchit si nécessaire).
   *
   * @return string
   *   Access token.
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

  /* -----------------------------------------------------------------------
   * Intégration des données au sein d’assets
   * --------------------------------------------------------------------- */

  /**
   * Récupère la liste des modules Netatmo disponibles pour l'utilisateur.
   *
   * @return array
   *   Tableau associatif de modules (id, name...).
   */
  public function getModules(): array {
    $token = $this->getAccessToken();
    $response = $this->httpClient->request('GET', 'https://api.netatmo.com/api/getstationsdata', [
      'headers' => ['Authorization' => 'Bearer ' . $token],
    ]);
    $data = json_decode($response->getBody()->getContents(), TRUE);
    $modules = [];
    foreach ($data['body']['devices'] as $device) {
      $modules[] = [
        'id' => $device['_id'],
        'name' => $device['module_name'] ?? $device['station_name'],
      ];
    }
    return $modules;
  }

  /* -----------------------------------------------------------------------
   * DestructableInterface
   * --------------------------------------------------------------------- */

  public function destruct(): void {
    // Rien à nettoyer explicitement.
  }

}
