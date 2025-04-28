<?php

namespace Drupal\farmos_netatmo\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\farmos_netatmo\NetatmoService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Formulaire d’administration Netatmo.
 */
class NetatmoSettingsForm extends ConfigFormBase {

  public function __construct(
    protected NetatmoService $netatmo,
  ) {}

  public static function create(ContainerInterface $c): static {
    return new static($c->get('farmos_netatmo.netatmo_service'));
  }

  public function getFormId(): string {
    return 'farmos_netatmo_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['farmos_netatmo.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('farmos_netatmo.settings');

    $form['credentials'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Application credentials'),
    ];
    $form['credentials']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#required' => TRUE,
      '#default_value' => $config->get('client_id'),
    ];
    $form['credentials']['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret'),
      '#required' => TRUE,
      '#default_value' => $config->get('client_secret'),
    ];

    // Refresh token (caché à l’édition)
    $refresh_token = $config->get('refresh_token');
    $form['refresh_token'] = [
      '#type' => 'item',
      '#title' => $this->t('Authorization status'),
      '#markup' => $refresh_token
        ? $this->t('Authorized ✅')
        : $this->t('Not yet authorized ❌'),
    ];

    // Bouton “Autoriser” seulement si identifiants saisis
    if ($config->get('client_id') && $config->get('client_secret')) {
      $form['authorize'] = [
         '#type' => 'link',
         '#title' => $this->t('Authorize application with Netatmo'),
         '#url' => Url::fromRoute('farmos_netatmo.authorize'),
         '#attributes' => ['class' => ['button', 'button--primary']],
       ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('farmos_netatmo.settings')
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('client_secret', $form_state->getValue('client_secret'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
