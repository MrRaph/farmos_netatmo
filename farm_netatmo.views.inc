<?php

/**
 * @file
 * Définit la vue par défaut pour afficher les données Netatmo sur un asset.
 */

use Drupal\views\Entity\View;

/**
 * Implements hook_views_api().
 */
function farm_netatmo_views_api() {
  return [
    'api' => 3,
  ];
}

/**
 * Implements hook_views_default_views().
 */
function farm_netatmo_views_default_views() {
  $views = [];

  // Création de la vue 'netatmo_sensor_data'.
  $view = View::create([
    'id' => 'netatmo_sensor_data',
    'label' => t('Données Netatmo capteur'),
    'module' => 'views',
    'description' => t('Affiche les points de données Netatmo pour le capteur en cours.'),
    'base_table' => 'data_stream_basic',
    'display' => [],
  ]);

  // Master display.
  $display = $view->addDisplay('default', 'Master', 'master');
  $display->setDisplayOption('path', 'farm/asset/%/netatmo-data');
  $display->setDisplayOption('access', [
    'type' => 'perm',
    'perm' => 'access content',
  ]);

  // Contextual filter: data_stream_basic.id <- argument from URL.
  $display->setDisplayOption('arguments', [
    'id' => [
      'id' => 'id',
      'table' => 'data_stream_basic',
      'field' => 'id',
      'default_action' => 'display_all_results',
      'default_argument_type' => 'fixed',
    ],
  ]);

  // Fields.
  $display->setDisplayOption('fields', [
    'timestamp' => [
      'id' => 'timestamp',
      'table' => 'data_stream_basic',
      'field' => 'timestamp',
      'label' => t('Date'),
      'date_format' => 'medium',
    ],
    'value' => [
      'id' => 'value',
      'table' => 'data_stream_basic',
      'field' => 'value',
      'label' => t('Valeur'),
    ],
  ]);

  // Sort criteria: timestamp descending.
  $display->setDisplayOption('sorts', [
    'timestamp' => [
      'id' => 'timestamp',
      'table' => 'data_stream_basic',
      'field' => 'timestamp',
      'order' => 'DESC',
    ],
  ]);

  // Style: Chart (si plugin disponible), sinon table.
  $display->setDisplayOption('style', [
    'type' => 'chart',
    'options' => [
      'chart_type' => 'line',
      'x_field' => 'timestamp',
      'y_field' => 'value',
    ],
  ]);

  // Sauvegarde de la vue par défaut.
  $views[$view->id()] = $view;
  return $views;
}
