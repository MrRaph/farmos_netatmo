<?php

namespace Drupal\farm_netatmo\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\farm_netatmo\NetatmoService;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
    $this->netatmoService = $netatmoService;
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

    // Champs de base.
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

    // Lien ou bouton d'authentification Netatmo (existant dans votre form).
    // Par exemple, un lien via AuthorizationController.
    if (! $this->netatmoService->isAuthorized()) {
      $form['authorize'] = [
        '#type' => 'link',
        '#title' => $this->t('Autoriser l’accès à Netatmo'),
        '#url' => \Drupal\Core\Url::fromRoute('farm_netatmo.authorize'),
        '#attributes' => ['class' => ['button']],
      ];
    }

    // N’afficher le bouton de récupération des modules QUE si on est authentifié.
    if ($this->netatmoService->isAuthorized()) {
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

    // Conteneur à rafraîchir.
    $form['modules_section'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'netatmo-modules-wrapper'],
    ];

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

      // Table de mapping.
      $header = [
        'module' => $this->t('Module Netatmo'),
        'asset' => $this->t('Affecter à'),
      ];
      $saved = $config->get('asset_mapping') ?: [];
      $rows = [];
      foreach ($modules as $m) {
        $rows[$m['id']] = [
          'module' => $m['name'],
          'asset' => [
            '#type' => 'select',
            '#options' => $options,
            '#empty_option' => $this->t('- Aucun -'),
            '#default_value' => $saved[$m['id']] ?? NULL,
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
   * Ajax submit handler : récupère les modules Netatmo.
   */
  public function fetchModules(array &$form, FormStateInterface $form_state): void {
    try {
      $modules = $this->netatmoService->getModules();
      $form_state->set('netatmo_modules', $modules);
      $form_state->setRebuild(TRUE);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Impossible de joindre Netatmo : @msg', ['@msg' => $e->getMessage()]));
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

    $mapping = $form_state->getValue('asset_mapping') ?: [];
    $this->config('farm_netatmo.settings')
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('client_secret', $form_state->getValue('client_secret'))
      ->set('asset_mapping', $mapping)
      ->save();

    $this->messenger()->addStatus($this->t('Paramètres Netatmo enregistrés.'));
  }

}
