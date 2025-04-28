<?php

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

  // Create the view.
  $view = View::create([
    'id' => 'netatmo_sensor_data',
    'label' => t('Données Netatmo capteur'),
    'module' => 'views',
    'description' => t('Affiche les points de données Netatmo pour le capteur en cours.'),
    'base_table' => 'data_stream_basic',
    'display' => [],
  ]);

  // Add a Page display for the asset data.
  $page = $view->addDisplay('page', 'Page', 'page_1');

  // URL path: /farm/asset/%/netatmo-data
  $page->setDisplayOption('page_1', 'path', 'farm/asset/%/netatmo-data');

  // Permission access.
  $page->setDisplayOption('page_1', 'access', [
    'type' => 'perm',
    'perm' => 'access content',
  ]);

  // Contextual filter: data_stream_basic.id from URL.
  $page->setDisplayOption('page_1', 'arguments', [
    'id' => [
      'id' => 'id',
      'table' => 'data_stream_basic',
      'field' => 'id',
      'default_action' => 'display_all_results',
      'default_argument_type' => 'fixed',
    ],
  ]);

  // Fields: timestamp and value.
  $page->setDisplayOption('page_1', 'fields', [
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

  // Sort by timestamp desc.
  $page->setDisplayOption('page_1', 'sorts', [
    'timestamp' => [
      'id' => 'timestamp',
      'table' => 'data_stream_basic',
      'field' => 'timestamp',
      'order' => 'DESC',
    ],
  ]);

  // Style: Chart line.
  $page->setDisplayOption('page_1', 'style', [
    'type' => 'chart',
    'options' => [
      'chart_type' => 'line',
      'x_field' => 'timestamp',
      'y_field' => 'value',
    ],
  ]);

  // Register the view.
  $views[$view->id()] = $view;
  return $views;
}
