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
   * The Netatmo API service.
   *
   * @var \Drupal\farm_netatmo\NetatmoService
   */
  protected NetatmoService $netatmoService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the Netatmo settings form.
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

    // --- Vos champs de base (client_id, client_secret) ---
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

    // --- Affichage du statut d’autorisation (refresh_token caché) ---
    $refresh_token = $config->get('refresh_token');
    $form['refresh_token'] = [
      '#type' => 'item',
      '#title' => $this->t('Authorization status'),
      '#markup' => $refresh_token
        ? $this->t('Authorized ✅')
        : $this->t('Not yet authorized ❌'),
    ];

    // --- Bouton “Autoriser” uniquement si client_id + client_secret sont remplis ---
    if ($config->get('client_id') && $config->get('client_secret')) {
      $form['authorize'] = [
        '#type' => 'link',
        '#title' => $this->t('Authorize application with Netatmo'),
        '#url' => Url::fromRoute('farm_netatmo.authorize'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    // --- Bouton Ajax “Récupérer les modules” UNIQUEMENT si autorisé ---
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

    // --- Conteneur Ajax à rafraîchir pour la table de mapping ---
    $form['modules_section'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'netatmo-modules-wrapper'],
    ];

    // Si la liste des modules a déjà été chargée en Ajax, on construit la table
    $modules = $form_state->get('netatmo_modules');
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

      // En-têtes et valeurs déjà enregistrées en config.
      $header = [
        'module' => $this->t('Module Netatmo'),
        'asset' => $this->t('Affecter à'),
      ];
      $saved_map = $config->get('asset_mapping') ?: [];

      $rows = [];
      foreach ($modules as $m) {
        $rows[$m['id']] = [
          'module' => $m['name'],
          'asset' => [
            '#type' => 'select',
            '#options' => $options,
            '#empty_option' => $this->t('- Aucun -'),
            '#default_value' => $saved_map[$m['id']] ?? NULL,
          ],
        ];
      }

      $form['modules_section']['asset_mapping'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Ajax submit handler : récupère les modules Netatmo et reconstruit le form.
   */
  public function fetchModules(array &$form, FormStateInterface $form_state): void {
    try {
      $modules = $this->netatmoService->getModules();
      $form_state->set('netatmo_modules', $modules);
      $form_state->setRebuild(TRUE);
    }
    catch (\Exception $e) {
      $this->messenger()->addError(
        $this->t('Impossible de joindre Netatmo : @msg', ['@msg' => $e->getMessage()])
      );
    }
  }

  /**
   * Ajax callback : renvoie uniquement le conteneur modules_section.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state) {
    return $form['modules_section'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    // On enregistre aussi le mapping si présent.
    $mapping = $form_state->getValue('asset_mapping') ?: [];
    $this->config('farm_netatmo.settings')
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('client_secret', $form_state->getValue('client_secret'))
      ->set('asset_mapping', $mapping)
      ->save();

    $this->messenger()->addStatus($this->t('Paramètres Netatmo enregistrés.'));
  }

}
