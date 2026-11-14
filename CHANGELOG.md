# Journal des modifications

Ce fichier recense toutes les modifications notables du plugin, selon le principe
du projet [Keep a Changelog](http://keepachangelog.com/) ; ce projet suit la
[gestion sémantique de version](http://semver.org/).

## [Non publié]

### Ajouté

### Modifié

### Corrigé

### Retiré

## [1.1.1] - 2026-11-14

### Corrigé

- Liens vers le dépôt GitHub (page d'accueil, releases, issues et README dans
  `webhookbearerapi.xml` et dans la liste des plugins de GLPI) : ils pointaient vers
  un dépôt inexistant.

## [1.1] - 2026-11-14

### Ajouté

- La tâche planifiée `RefreshTokens` met à jour le jeton des webhooks en file
  d'attente encore à envoyer et le retire des entrées déjà envoyées (ainsi qu'à la
  suppression d'un fournisseur et à la désinstallation).
- Temporisation de 60 secondes après un échec de récupération du jeton lors de la
  mise en file des webhooks ; le jeton en cache reste utilisé tant qu'il n'a pas
  expiré.
- Avertissement lorsque l'option « Utiliser OAuth » est désactivée par le plugin.
- Onglet « Webhooks » sur les fournisseurs : webhooks qui utilisent le fournisseur.
- Aide sur le champ « Portée » (paramètre OAuth 2.0 `scope`).
- Documentation des serveurs de jetons sur un réseau privé
  (`GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS`) et de l'exclusion du proxy.
- Script `tools/update_locales.sh` de mise à jour des traductions.
- En-tête `Content-Type: application/json` ajouté aux webhooks utilisant un fournisseur
  quand leur contenu est du JSON et qu'ils ne définissent pas cet en-tête (GLPI envoyait
  le contenu comme un formulaire, ignoré par les API JSON comme celle de GLPI).
- Les entrées de la file encore à envoyer suivent l'URL cible actuelle du webhook
  (recalculée à la modification du webhook et par la tâche planifiée) au lieu de garder
  l'URL recopiée lors de l'événement.
- Avertissement lorsque l'authentification challenge-réponse est activée sur un webhook
  utilisant un fournisseur (requête de vérification envoyée sans jeton).
- Onglet « API bearer » du webhook : URL cible et URL du jeton affichées ; aide sur le
  champ « URL du jeton ».
- Documentation : fonctionnement de l'envoi, exemple d'envoi vers l'API d'un autre
  GLPI et erreurs fréquentes.
- Onglet « API bearer » du webhook : diagnostic de la dernière requête en file (URL, code
  de retour, présence du jeton, `Content-Type`, validité du JSON) avec des pistes selon
  l'erreur.
- Avertissements à l'enregistrement d'un fournisseur dont l'URL du jeton est celle d'un
  GLPI : type d'autorisation *Client credentials* (jeton lié à aucun utilisateur) ou
  portée `api` absente.

### Modifié

- Migration de la base de données à la mise à jour depuis la version 1.0.
- Les dates sont stockées dans des colonnes `timestamp`, comme dans GLPI ; les jetons
  en cache sont renouvelés à la migration.
- Les colonnes `client_secret` et `password` passent en `text` (secrets longs).
- La marge de rafraîchissement est limitée à la moitié de la durée de vie du jeton, et
  la durée annoncée par le serveur n'est plus allongée à 60 secondes minimum.
- La tâche planifiée renouvelle aussi les jetons qui expireraient avant son prochain
  passage.
- Le bouton « Rafraîchir le jeton maintenant » est placé avec les boutons du
  formulaire.
- Les fournisseurs deviennent des configurations génériques de serveurs de jetons : le
  champ « Webhook » du fournisseur est retiré, et chaque webhook choisit son
  fournisseur dans le champ « Source d'authentification de l'API » de son formulaire
  (« GLPI natif (OAuth 2.0) » ou l'un des fournisseurs). Un fournisseur peut servir
  plusieurs webhooks. La migration reprend le fournisseur rattaché à chaque webhook,
  sauf pour les webhooks en authentification native ; l'option de recherche
  « Webhook » des fournisseurs est supprimée.
- La purge d'un webhook ne supprime plus son fournisseur ; la purge d'un fournisseur
  remet les webhooks qui l'utilisaient en authentification native (un message les
  liste).
- Le choix du fournisseur est enregistré après l'enregistrement du webhook (et non plus
  avant sa validation).
- Le champ « Source d'authentification de l'API » utilise les macros de formulaire de
  GLPI et n'est plus ajouté qu'au formulaire principal du webhook (plus d'observateur
  JavaScript global).
- L'onglet du webhook, renommé « API bearer », affiche le fournisseur utilisé ; il est
  rendu par un gabarit Twig.
- Les pages `front/` n'incluent plus `inc/includes.php`, obsolète depuis GLPI 11 ; retour
  à la liste après la suppression d'un fournisseur.

### Corrigé

- Webhooks envoyés ou réessayés avec un jeton expiré depuis leur mise en file.
- Le jeton en cache n'était pas renouvelé quand les paramètres de la demande de jeton
  changeaient (portée, identifiants, URL…) : il restait utilisé jusqu'à son expiration.
  Un nouveau jeton est maintenant demandé à l'enregistrement du fournisseur, puis
  transmis aux webhooks en file.
- Jetons conservés en clair dans la file d'attente des webhooks après leur envoi.
- Délai d'attente de 5 s (inactivité) et 10 s (total) pour les requêtes de jeton :
  un serveur de jetons lent ne bloque plus l'enregistrement des éléments.
- Jeton renouvelé à chaque utilisation lorsque sa durée de vie est inférieure à la
  marge de rafraîchissement.
- Dates d'expiration faussées lorsque les fuseaux horaires sont activés.
- Appuyer sur Entrée dans le formulaire d'un fournisseur rafraîchissait le jeton au
  lieu d'enregistrer les modifications.
- Traductions : la plupart des chaînes n'étaient pas traduites (guillemet parasite
  dans les fichiers `.po`/`.mo`) et l'en-tête `Plural-Forms` était absent.
- Jeton du plugin injecté inutilement puis remplacé par GLPI lorsque l'option
  « Utiliser OAuth » du webhook était activée.
- Échappement HTML des messages et des valeurs affichées (messages de session, option de
  recherche « Type d'autorisation », aide du champ « Marge de rafraîchissement »).

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
