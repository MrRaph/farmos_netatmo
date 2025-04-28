<?php

namespace Drupal\farm_netatmo\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\farm_netatmo\NetatmoService;

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
   * Constructs the form.
   */
  public function __construct($config_factory, NetatmoService $netatmoService, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($config_factory);
    $this->netatmoService = $netatmoService;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
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
  public function buildForm(array $form, FormStateInterface $formState): array {
    $config = $this->config('farm_netatmo.settings');

    // Vos champs de base (client_id, client_secret).
    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $config->get('client_id'),
      '#required' => TRUE,
    ];
    $form['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client secret'),
      '#default_value' => $config->get('client_secret'),
      '#required' => TRUE,
    ];

    // 1) Bouton Ajax pour récupérer la liste des modules Netatmo.
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

    // 2) Conteneur Ajaxable.
    $form['modules_section'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'netatmo-modules-wrapper'],
    ];

    // Si la liste a déjà été chargée en Ajax, on affiche la table de mapping.
    $modules = $formState->get('netatmo_modules');
    if (!empty($modules)) {
      // Charger les assets Land et Property.
      $land = $this->entityTypeManager->getStorage('asset')->loadByProperties(['type' => 'land']);
      $props = $this->entityTypeManager->getStorage('asset')->loadByProperties(['type' => 'property']);
      $options = [];
      foreach ($land as $entity) {
        $options[$entity->id()] = $this->t('Land : @label', ['@label' => $entity->label()]);
      }
      foreach ($props as $entity) {
        $options[$entity->id()] = $this->t('Property : @label', ['@label' => $entity->label()]);
      }

      // En-têtes de la table.
      $header = [
        'module' => $this->t('Module Netatmo'),
        'asset' => $this->t('Affecter à'),
      ];

      // Valeurs déjà enregistrées en config.
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

    return parent::buildForm($form, $formState);
  }

  /**
   * Ajax submit handler : récupère la liste des modules et reconstruit le form.
   */
  public function fetchModules(array &$form, FormStateInterface $formState): void {
    try {
      $modules = $this->netatmoService->getModules();
      $formState->set('netatmo_modules', $modules);
      $formState->setRebuild(TRUE);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Impossible de joindre Netatmo : @msg', ['@msg' => $e->getMessage()]));
    }
  }

  /**
   * Ajax callback : renvoie uniquement le conteneur modules_section.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $formState) {
    return $form['modules_section'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $formState): void {
    parent::submitForm($form, $formState);

    // Sauvegarde du mapping sélectionné.
    $mapping = $formState->getValue('asset_mapping') ?: [];
    $this->config('farm_netatmo.settings')
      ->set('client_id', $formState->getValue('client_id'))
      ->set('client_secret', $formState->getValue('client_secret'))
      ->set('asset_mapping', $mapping)
      ->save();

    $this->messenger()->addStatus($this->t('Paramètres Netatmo enregistrés.'));
  }

}
