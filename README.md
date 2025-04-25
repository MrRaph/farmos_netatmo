# farm_netatmo

Module Drupal/farmOS pour importer automatiquement les mesures des stations météo Netatmo.

## Installation

### Via Composer

1. Déclarez le dépôt VCS dans votre projet

Exécutez (à la racine de votre site Drupal) :

`composer config repositories.farm_netatmo vcs https://github.com/MrRaph/farmos_netatmo`

2. Installez le module en pointant sur la branche

`composer require drupal/farm_netatmo:dev-develop`

3. Enable module

Go to https://[FarmOS Host]/setup/modules and enable it.

### Manuellement

1. Copier le dossier dans `modules/contrib`.
2. Exécuter `composer install` pour récupérer les dépendances.
3. Activer : `drush en farm_netatmo -y`

## Configuration

Rendez‑vous dans **Administration → Configuration → farmOS → Netatmo**  
et renseignez vos identifiants API Netatmo.

Chaque asset de type *sensor* doit contenir le champ **Netatmo device id**.

## Fonctionnement

Une *queue* cron (`farm_netatmo_sync`) parcourt les assets et interroge
l’API `getstationsdata`.  
Les nouvelles métriques sont créées dynamiquement sous forme de
**DataStreams** de type `basic`.

```bash
# Lancer manuellement
drush queue:run farm_netatmo_sync
```

## Crédits

Inspiré du module *farm_ecowitt*.
