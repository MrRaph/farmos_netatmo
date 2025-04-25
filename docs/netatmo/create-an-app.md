# Créer et configurer l’application dans Netatmo

1. Connectez‑vous à **dev.netatmo.com** › menu **MY APPS** › **CREATE AN APP**.
2. Renseignez :
   - **App name** : le nom de votre ferme / projet.
   - **Description** : optionnel.
   - **Callback URL** : `https://<votre-domaine>/farm/netatmo/oauth/callback`
   - **Data protection officer e‑mail** : obligatoire.
3. Enregistrez ➜ Netatmo génère un **Client ID** et un **Client Secret**.
4. Dans l’onglet **Scopes**, cochez *read_station*.
5. (Facultatif) passez l’application en statut “Partner” si vous souhaitez de futurs scopes additionnels – *non requis* pour la météo.

> ⚠️ **Ne pas utiliser** le flux "Resource Owner Password" : Netatmo le bloque pour les nouvelles apps. Le module s’appuie sur *authorization_code → refresh_token*.
