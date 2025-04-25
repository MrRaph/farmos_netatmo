<?php

namespace Drupal\farm_netatmo;

use Drupal\asset\Entity\AssetInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\data_stream\DataStreamTypeManager;
use GuzzleHttp\ClientInterface;

/**
* Service chargé de récupérer les mesures Netatmo et de les enregistrer
* dans farmOS sous forme de DataStreams basiques.
*/

class NetatmoService implements DestructableInterface {
    {

        /** @var \GuzzleHttp\ClientInterface */
        protected ClientInterface $httpClient;

        /** @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface */
        protected KeyValueStoreExpirableInterface $kv;

        /** @var \Drupal\Core\Logger\LoggerChannelInterface */
        protected LoggerChannelInterface $logger;

        /** @var \Drupal\Core\Config\ImmutableConfig */
        protected $config;

        /** @var \Drupal\data_stream\Plugin\DataStream\DataStreamType\Basic */
        protected $basicDataStream;

        /**
        * Désalloue les ressources quand Drupal ferme son kernel.
        *
        * Cette méthode est appelée automatiquement parce que le service
        * est tagué needs_destruction dans farm_netatmo.services.yml.
        */

        public function destruct(): void {
            // Exemple : vider le client HTTP ou fermer un handle curl.
            // Dans notre cas, rien d’obligatoire :
            $this->httpClient = NULL;
        }

        /**
        * NetatmoService constructor.
        */

        public function __construct(
            ClientInterface $http_client,
            KeyValueExpirableFactoryInterface $kv_factory,
            LoggerChannelFactoryInterface $logger_factory,
            ConfigFactoryInterface $config_factory,
            DataStreamTypeManager $data_stream_type_manager,
        ) {
            $this->httpClient      = $http_client;
            $this->kv              = $kv_factory->get( 'farm_netatmo_tokens' );
            $this->logger          = $logger_factory->get( 'farm_netatmo' );
            $this->config          = $config_factory->get( 'farm_netatmo.settings' );
            $this->basicDataStream = $data_stream_type_manager->createInstance( 'basic' );
        }

        /**
        * Récupère les mesures Netatmo pour un asset et les enregistre.
        */

        public function fetchAndStore( AssetInterface $asset ): void {
            try {
                $token = $this->getAccessToken();
                $device_id = $asset->get( 'field_netatmo_device_id' )->value ?? NULL;
                if ( !$device_id ) {
                    $this->logger->warning(
                        'L’asset @id n’a pas de device_id Netatmo.',
                        [ '@id' => $asset->id() ],
                    );
                    return;
                }

                $response = $this->httpClient->request( 'GET', 'https://api.netatmo.com/api/getstationsdata', [
                    'headers' => [ 'Authorization' => 'Bearer ' . $token ],
                    'query'   => [
                        'device_id'     => $device_id,
                        'get_favorites' => FALSE,
                    ],
                ] );

                $payload = json_decode( $response->getBody()->getContents(), TRUE );
                if ( !isset( $payload[ 'body' ][ 'devices' ][ 0 ][ 'dashboard_data' ] ) ) {
                    return;
                }

                $data      = $payload[ 'body' ][ 'devices' ][ 0 ][ 'dashboard_data' ];
                $timestamp = $data[ 'time_utc' ] ?? time();
                unset( $data[ 'time_utc' ] );

                // S’assure que chaque métrique possède son DataStream.
                $streams = $this->getBasicStreams( $asset );
                foreach ( $data as $name => $value ) {
                    if ( !isset( $streams[ $name ] ) ) {
                        $streams[ $name ] = $this->createDataStream( $asset, $name );
                    }
                    $this->basicDataStream->saveValue( $streams[ $name ], ( float ) $value, $timestamp );
                }
            } catch ( \Throwable $e ) {
                $this->logger->error( $e->getMessage() );
            }
        }

        /**
        * Renvoie un access_token Netatmo valide ( mis en cache en KV expirable ).
        */
        protected function getAccessToken(): string {
            if ( $cached = $this->kv->get( 'access_token' ) ) {
                if ( $this->kv->get( 'expires' ) > time() ) {
                    return $cached;
                }
            }

            $response = $this->httpClient->request( 'POST', 'https://api.netatmo.com/oauth2/token', [
                'form_params' => [
                    'grant_type'    => 'password',
                    'client_id'     => $this->config->get( 'client_id' ),
                    'client_secret' => $this->config->get( 'client_secret' ),
                    'username'      => $this->config->get( 'username' ),
                    'password'      => $this->config->get( 'password' ),
                    'scope'         => 'read_station',
                ],
            ] );

            $data = json_decode( $response->getBody()->getContents(), TRUE );
            $this->kv->setWithExpire( 'access_token', $data[ 'access_token' ], $data[ 'expires_in' ] );
            $this->kv->set( 'expires', time() + $data[ 'expires_in' ] );
            return $data[ 'access_token' ];
        }

        /**
        * Renvoie les DataStreams « basic » existants, indexés par leur nom.
        */
        protected function getBasicStreams( AssetInterface $asset ): array {
            $streams = [];
            foreach ( $asset->get( 'data_stream' )->referencedEntities() as $stream ) {
                if ( $stream->bundle() === 'basic' ) {
                    $streams[ $stream->label() ] = $stream;
                }
            }
            return $streams;
        }

        /**
        * Crée un nouveau DataStream basique sur l’asset pour une métrique donnée.
        */
        protected function createDataStream( AssetInterface $asset, string $name ) {
            $stream = $this->basicDataStream->create( [
                'type' => 'basic',
                'name' => $name,
            ] );
            $stream->save();
            $asset->get( 'data_stream' )->appendItem( $stream );
            $asset->save();
            return $stream;
        }

    }
