<?php

namespace Drupal\farm_netatmo;

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

  /**
   * Client HTTP Guzzle.
   *
   * @var \\GuzzleHttp\\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Stockage clé/valeur expirable pour les tokens.
   *
   * @var \\Drupal\\Core\\KeyValueStore\\KeyValueStoreExpirableInterface
   */
  protected KeyValueStoreExpirableInterface $kv;

  /**
   * Logger.
   *
   * @var \\Drupal\\Core\\Logger\\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Usine de configuration.
   *
   * @var \\Drupal\\Core\\Config\\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Configuration editable du module.
   *
   * @var \\Drupal\\Core\\Config\\Config
   */
  protected Config $config;

  /**
   * Plugin basique de flux de données (non typé pour accepter l'instance renvoyée).
   *
   * @var object
   */
  protected $basicDataStream;

  /**
   * Constructeur.
   */
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
   * Vérifie si un refresh_token est bien présent.
   *
   * @return bool
   *   TRUE si autorisé, FALSE sinon.
   */
  public function isAuthorized(): bool {
    return (bool) $this->config->get('refresh_token');
  }

  /**
   * Échange le code OAuth contre access+refresh token.
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
   * Récupère un access_token, rafraîchit si expiré.
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
   * Récupère la liste **des modules** Netatmo (pas des stations).
   *
   * @return array
   *   Tableau de modules ['id' => ..., 'name' => ...].
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
   * Intègre un point de donnée dans un asset farmOS.
   */
  public function addDataToAsset(AssetInterface $asset, string $name, $value): object {
    // … votre code existant …
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    // Rien à nettoyer.
  }

}
