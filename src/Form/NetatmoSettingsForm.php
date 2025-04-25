<?php

namespace Drupal\farm_netatmo\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Formulaire de configuration des identifiants Netatmo.
 */
class NetatmoSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['farm_netatmo.settings'];
  }

  public function getFormId(): string {
    return 'farm_netatmo_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('farm_netatmo.settings');

    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#required' => TRUE,
      '#default_value' => $config->get('client_id'),
    ];
    $form['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret'),
      '#required' => TRUE,
      '#default_value' => $config->get('client_secret'),
    ];
    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nom d’utilisateur (e‑mail)'),
      '#required' => TRUE,
      '#default_value' => $config->get('username'),
    ];
    $form['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Mot de passe'),
      '#required' => TRUE,
      '#default_value' => $config->get('password'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('farm_netatmo.settings')
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('client_secret', $form_state->getValue('client_secret'))
      ->set('username', $form_state->getValue('username'))
      ->set('password', $form_state->getValue('password'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
