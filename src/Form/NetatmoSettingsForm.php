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

  /**
   * Le service Netatmo.
   *
   * @var \Drupal\farm_netatmo\NetatmoService
   */
  protected NetatmoService $netatmoService;

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
    ConfigFactoryInterface $config_factory,
    NetatmoService $netatmoService,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct($config_factory);
    $this->netatmoService    = $netatmoService;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('farm_netatmo.netatmo_service'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'farm_netatmo_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['farm_netatmo.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('farm_netatmo.settings');

    // --- Identifiants API ---
    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $config->get('client_id'),
      '#required' => TRUE,
    ];
    $form['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret'),
      '#default_value' => $config->get('client_secret'),
      '#required' => TRUE,
    ];

    // --- Statut d’autorisation ---
    $refresh_token = $config->get('refresh_token');
    $form['refresh_token'] = [
      '#type' => 'item',
      '#title' => $this->t('Authorization status'),
      '#markup' => $refresh_token
        ? $this->t('Authorized ✅')
        : $this->t('Not yet authorized ❌'),
    ];

    // --- Bouton “Autoriser” si les IDs sont renseignés ---
    if ($config->get('client_id') && $config->get('client_secret')) {
      $form['authorize'] = [
        '#type' => 'link',
        '#title' => $this->t('Authorize application with Netatmo'),
        '#url' => Url::fromRoute('farm_netatmo.authorize'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    // --- Bouton Ajax “Récupérer les modules” uniquement si autorisé ---
    if ($refresh_token) {
      $form['actions']['fetch_modules'] = [
        '#type' => 'submit',
        '#value' => $this->t('Récupérer les modules Netatmo'),
        '#submit' => ['::fetchModules'],
        '#ajax' => [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'netatmo-modules-wrapper',
          'effect' => 'fade',
        ],
      ];
    }

    // --- Conteneur AJAX pour la table de mapping ---
    $form['modules_section'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'netatmo-modules-wrapper'],
    ];

    // Chargement des modules :
    // 1) session Ajax
    $modules = $form_state->get('netatmo_modules');
    // 2) sinon persisté en config
    if (empty($modules)) {
      $modules = $config->get('fetched_modules') ?: [];
    }

    if (!empty($modules)) {
      // Charger les assets Land et Property.
      $lands = $this->entityTypeManager->getStorage('asset')->loadByProperties(['type' => 'land']);
      $props = $this->entityTypeManager->getStorage('asset')->loadByProperties(['type' => 'property']);
      $options = [];
      foreach ($lands as $entity) {
        $options[$entity->id()] = $this->t('Land : @label', ['@label' => $entity->label()]);
      }
      foreach ($props as $entity) {
        $options[$entity->id()] = $this->t('Property : @label', ['@label' => $entity->label()]);
      }

      // En-têtes et mapping sauvegardé.
      $header = [
        'module' => $this->t('Module Netatmo'),
        'asset'  => $this->t('Affecter à'),
      ];
      $saved_map = $config->get('asset_mapping') ?: [];

      // Construction de la table.
      $form['modules_section']['asset_mapping'] = [
        '#type' => 'table',
        '#header' => $header,
      ];
      foreach ($modules as $m) {
        $mid = $m['id'];
        $form['modules_section']['asset_mapping'][$mid]['module'] = [
          '#markup' => $m['name'],
        ];
        $form['modules_section']['asset_mapping'][$mid]['asset'] = [
          '#type'         => 'select',
          '#options'      => $options,
          '#empty_option' => $this->t('- Aucun -'),
          '#default_value'=> $saved_map[$mid] ?? NULL,
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Ajax submit handler : récupère et stocke les modules.
   */
  public function fetchModules(array &$form, FormStateInterface $form_state): void {
    try {
      $modules = $this->netatmoService->getModules();
      // 1) session
      $form_state->set('netatmo_modules', $modules);
      $form_state->setRebuild(TRUE);
      // 2) config persistée
      $this->config('farm_netatmo.settings')
        ->set('fetched_modules', $modules)
        ->save();
    }
    catch (\Exception $e) {
      $this->messenger()->addError(
        $this->t('Impossible de joindre Netatmo : @msg', ['@msg' => $e->getMessage()])
      );
    }
  }

  /**
   * Ajax callback : renvoie le conteneur modules_section.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state) {
    return $form['modules_section'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    // Sauvegarde des identifiants et du mapping.
    $mapping = $form_state->getValue('asset_mapping') ?: [];
    $this->config('farm_netatmo.settings')
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('client_secret', $form_state->getValue('client_secret'))
      ->set('asset_mapping', $mapping)
      ->save();

    $this->messenger()->addStatus($this->t('Paramètres Netatmo enregistrés.'));
  }

}
