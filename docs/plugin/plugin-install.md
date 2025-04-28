# Installation

### Via Composer

1. Déclarez le dépôt VCS dans votre projet

Exécutez (à la racine de votre site Drupal) :

`composer config repositories.farmos_netatmo vcs https://github.com/MrRaph/farmos_netatmo`

2. Installez le module en pointant sur la branche

`composer require drupal/farmos_netatmo:dev-develop`

3. Enable module

Go to https://[FarmOS Host]/setup/modules and enable it.

### Manuellement

1. Copier le dossier dans `modules/contrib`.
2. Exécuter `composer install` pour récupérer les dépendances.
3. Activer : `drush en farmos_netatmo -y`
