# farm_netatmo

Module Drupal/farmOS pour importer automatiquement les mesures des stations météo Netatmo.

## Documentation

Voir [Docs](./docs/index.md).

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
