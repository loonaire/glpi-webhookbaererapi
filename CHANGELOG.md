# Journal des modifications

Ce fichier recense toutes les modifications notables du plugin, selon le principe
du projet [Keep a Changelog](http://keepachangelog.com/) ; ce projet suit la
[gestion sémantique de version](http://semver.org/).

## [Non publié]

### Ajouté

### Modifié

### Corrigé

### Retiré

## [1.0] - 2026-11-12

### Ajouté

- Fournisseurs « API bearer » (Configuration > Générale > API bearer) : URL du
  jeton, types d'autorisation *identifiants client* et *mot de passe*, envoi des
  identifiants dans le corps (formulaire ou JSON) ou en en-tête HTTP Basic,
  champs personnalisés du jeton et de l'expiration dans la réponse JSON, portée
  (*scope*), paramètres supplémentaires, nom d'en-tête et préfixe du jeton
  configurables.
- Récupération, mise en cache et injection automatique du jeton bearer dans les
  en-têtes des webhooks mis en file d'attente (`TokenManager`), avant leur
  insertion en base.
- Tâche planifiée `RefreshTokens` (toutes les 10 minutes par défaut) renouvelant
  les jetons des API des webhooks actifs avant leur expiration.
- Onglet « Jetons bearer » sur chaque webhook : état du jeton, date
  d'expiration, dernière erreur et rafraîchissement manuel.
- Sélecteur « Source d'authentification de l'API » sur le formulaire du webhook
  (GLPI natif OAuth 2.0 ou Plugin Bearer API), avec désactivation automatique
  de l'option « Utiliser OAuth » de l'onglet Sécurité en mode plugin.
- Traductions en français (fr_FR) et anglais (en_US, en_GB).
- Création automatique des tables (`glpi_plugin_webhookbearerapi_bearerapis`,
  `glpi_plugin_webhookbearerapi_webhookconfigs`) à l'installation et purge des
  données du plugin avec les webhooks et à la désinstallation.
