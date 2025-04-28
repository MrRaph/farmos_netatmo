<?php

namespace Drupal\farm_netatmo\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\farm_netatmo\NetatmoService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
* Formulaire d’administration Netatmo.
*/

class NetatmoSettingsForm extends ConfigFormBase {

    protected NetatmoService $netatmoService;
    protected EntityTypeManagerInterface $entityTypeManager;

    public function __construct(
        ConfigFactoryInterface $config_factory,
        NetatmoService $netatmoService,
        EntityTypeManagerInterface $entityTypeManager
    ) {
        parent::__construct( $config_factory );
        $this->netatmoService    = $netatmoService;
        $this->entityTypeManager = $entityTypeManager;
    }

    public static function create( ContainerInterface $container ): static {
        return new static(
            $container->get( 'config.factory' ),
            $container->get( 'farm_netatmo.netatmo_service' ),
            $container->get( 'entity_type.manager' )
        );
    }

    public function getFormId(): string {
        return 'farm_netatmo_settings';
    }

    protected function getEditableConfigNames(): array {
        return [ 'farm_netatmo.settings' ];
    }

    /**
    * {
        @inheritdoc}
        */

        public function buildForm( array $form, FormStateInterface $form_state ) {
            // Charger la config.
            $config = $this->configFactory->getEditable( 'farm_netatmo.settings' );
            $refresh_token = $config->get( 'refresh_token' );

            // Champs client_id / client_secret.
            $form[ 'client_id' ] = [
                '#type' => 'textfield',
                '#title' => $this->t( 'Client ID' ),
                '#default_value' => $config->get( 'client_id' ),
                '#required' => TRUE,
            ];
            $form[ 'client_secret' ] = [
                '#type' => 'textfield',
                '#title' => $this->t( 'Client secret' ),
                '#default_value' => $config->get( 'client_secret' ),
                '#required' => TRUE,
            ];

            // Statut d'autorisation (item + bouton “Authorize”).
  $form['refresh_token'] = [
    '#type' => 'item',
    '#title' => $this->t('Authorization status'),
    '#markup' => $refresh_token
      ? $this->t('Authorized ✅')
      : $this->t('Not yet authorized ❌'),
  ];
  if ($config->get('client_id') && $config->get('client_secret')) {
    $form['authorize'] = [
      '#type' => 'link',
      '#title' => $this->t('Authorize application with Netatmo'),
      '#url' => Url::fromRoute('farm_netatmo.authorize'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];
  }

  // Conteneur AJAX pour la section modules + bouton de récupération de données.
  $form['modules_section'] = [
    '#type' => 'container',
    '#attributes' => ['id' => 'netatmo-modules-wrapper'],
  ];

  // Si on a déjà récupéré des modules, on peut afficher un tableau de mapping.
  $fetched = $config->get('fetched_modules') ?: [];
  $header = [
    'module' => $this->t('Module'),
    'asset'  => $this->t('Assign to asset'),
  ];
  $rows = [];
  foreach ($fetched as $module) {
    // S’il n’y a pas encore d’asset assigné, on prend NULL.
    $assigned_asset_id = isset($module['assigned_asset']) ? $module['assigned_asset'] : NULL;
    $rows[] = [
      'module' => $module['name'],
      'asset'  => [
        'data' => [
          '#type' => 'entity_autocomplete',
          '#target_type' => 'asset',
          '#selection_settings' => ['target_bundles' => ['land', 'property']],
          // Utilisation d'isset() pour éviter l’erreur.
          '#default_value' => $assigned_asset_id
            ? \Drupal::entityTypeManager()->getStorage('asset')->load($assigned_asset_id)
            : NULL,
          '#name' => "mapping[{$module['id']}]",
        ],
      ],
    ];
  }
  $form['modules_section']['mapping_table'] = [
    '#type' => 'table',
    '#header' => $header,
    '#rows' => $rows,
  ];

  // Bouton AJAX “Récupérer les données Netatmo” uniquement si autorisé.
  if ($refresh_token) {
    $form['modules_section']['fetch_data'] = [
      '#type' => 'button',
      '#value' => $this->t('Récupérer les données Netatmo'),
      '#ajax' => [
        'callback' => '::ajaxFetchData',
        'wrapper' => 'netatmo-modules-wrapper',
        'effect'  => 'fade',
        'event'   => 'click',
      ],
    ];
  }

  return parent::buildForm($form, $form_state);
}


    public function fetchModules( array &$form, FormStateInterface $form_state ): void {
        try {
            $modules = $this->netatmoService->getModules();
            $form_state->set( 'netatmo_modules', $modules );
            $form_state->setRebuild( TRUE );
            $this->config( 'farm_netatmo.settings' )
            ->set( 'fetched_modules', $modules )
            ->save();
        } catch ( \Exception $e ) {
            $this->messenger()->addError( $this->t( 'Impossible de joindre Netatmo : @msg', [ '@msg' => $e->getMessage() ] ) );
        }
    }

    /**
    * Submission handler pour récupérer et stocker les données.
    */

    public function fetchData( array &$form, FormStateInterface $form_state ): void {
        try {
            $this->netatmoService->fetchModuleData();
            $this->messenger()->addStatus( $this->t( 'Données Netatmo récupérées et stockées.' ) );
        } catch ( \Exception $e ) {
            $this->messenger()->addError( $this->t( 'Erreur lors de la récupération des données : @msg', [ '@msg' => $e->getMessage() ] ) );
        }
        // On reconstruit le form pour rafraîchir la zone Ajax.
        $form_state->setRebuild( TRUE );
    }

    /**
    * Ajax callback : renvoie le conteneur modules_section ( idem fetchModules ).
    */

    public function ajaxRefresh( array &$form, FormStateInterface $form_state ) {
        return $form[ 'modules_section' ];
    }

    public function submitForm( array &$form, FormStateInterface $form_state ): void {
        parent::submitForm( $form, $form_state );

        $mapping = $form_state->getValue( 'asset_mapping' ) ?: [];
        $this->config( 'farm_netatmo.settings' )
        ->set( 'client_id', $form_state->getValue( 'client_id' ) )
        ->set( 'client_secret', $form_state->getValue( 'client_secret' ) )
        ->set( 'asset_mapping', $mapping )
        ->save();

        // Provision des sensors + DataStreams.
        if ( !empty( $mapping ) ) {
            $this->netatmoService->provisionSensors( $mapping );
            $this->messenger()->addStatus( $this->t( 'Assets Sensor et DataStreams créés pour chaque module Netatmo.' ) );
        }

        $this->messenger()->addStatus( $this->t( 'Paramètres Netatmo enregistrés.' ) );
    }

    /**
    * AJAX callback: récupère les données puis renvoie la section modules_section.
    */

    public function ajaxFetchData( array &$form, FormStateInterface $form_state ) {
        try {
            // Appelle directement votre service pour fetch et stocker les données.
            $this->netatmoService->fetchModuleData();
            $this->messenger()->addStatus( $this->t( 'Données Netatmo récupérées et stockées.' ) );
        } catch ( \Exception $e ) {
            $this->messenger()->addError( $this->t( 'Erreur lors de la récupération des données : @msg', [ '@msg' => $e->getMessage() ] ) );
        }
        // On renvoie la portion du formulaire enveloppée par netatmo-modules-wrapper.
        return $form[ 'modules_section' ];
        }

    }
