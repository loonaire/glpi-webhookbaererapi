# Webhook Bearer API

Plugin GLPI 12 — authentification par jeton bearer pour les webhooks appelant des API.

Webhook Bearer API ajoute un fournisseur de jetons aux webhooks GLPI : définissez un
serveur de jetons (OAuth2 *client credentials* ou *password grant*), le jeton bearer est
automatiquement ajouté aux en-têtes de la requête à l'envoi du webhook. Une tâche
planifiée renouvelle les jetons avant leur expiration.

## Fonctionnalités

- **Fournisseurs « API bearer »** (Configuration > Générale > API bearer) :
  - URL du jeton, type d'autorisation *client credentials* ou *mot de passe* ;
  - envoi des identifiants dans le corps (formulaire ou JSON) ou en en-tête HTTP Basic ;
  - champs personnalisés du jeton et de l'expiration dans la réponse JSON, portée
    (*scope*), paramètres supplémentaires ;
  - nom d'en-tête et préfixe du jeton configurables (par défaut `Authorization: Bearer`).
- **Statut et rafraîchissement** : onglet « Jetons bearer » sur chaque webhook — état du
  jeton, date d'expiration, dernière erreur, rafraîchissement manuel.
- **Tâche planifiée** `RefreshTokens` (toutes les 10 minutes par défaut) : renouvelle les
  jetons des API utilisées par les webhooks actifs avant leur expiration.
- **Source d'authentification de l'API** par webhook (formulaire du webhook) :
  - *GLPI natif (OAuth 2.0)* : utilise les paramètres « Utiliser OAuth » de l'onglet
    Sécurité ; le plugin n'ajoute aucun jeton ;
  - *Plugin Bearer API* : utilise le fournisseur défini par ce plugin (l'option
    « Utiliser OAuth » est alors désactivée).
- Purge propre : les données du plugin sont supprimées avec les webhooks et à la
  désinstallation.

## Prérequis

- GLPI `~12.0.0` ;
- PHP requis par GLPI 12 ;
- base de données MariaDB/MySQL.

## Installation

1. Copier le dossier `webhookbearerapi` dans `glpi/plugins/` ;
2. Dans GLPI : **Configuration > Plugins**, installer puis activer « Webhook Bearer API ».

La table `glpi_plugin_webhookbearerapi_bearerapis` (fournisseurs et jetons en cache), la
table `glpi_plugin_webhookbearerapi_webhookconfigs` (source d'authentification par
webhook) et la tâche planifiée sont créées à l'activation.

## Configuration

1. **Créer un fournisseur** : Configuration > Générale > API bearer. Renseigner l'URL du
   jeton, le type d'autorisation et les identifiants, puis rattacher le fournisseur au
   webhook concerné.
2. **Choisir la source d'authentification** sur le formulaire du webhook
   (champ « Source d'authentification de l'API ») : *Plugin Bearer API* par défaut dès
   qu'un fournisseur existe, sinon *GLPI natif*.
3. **Vérifier le jeton** : onglet « Jetons bearer » du webhook — l'état, l'expiration et
   la dernière erreur du jeton y sont affichés ; un bouton permet de forcer un
   rafraîchissement.

Le jeton est récupéré et mis en cache par le plugin, puis injecté dans les en-têtes de
chaque webhook mis en file d'attente, avant son insertion en base.

## Traductions

Anglais (en_US, en_GB) et français (fr_FR) — fichiers dans `locales/`, modèle
`locales/webhookbearerapi.pot`.

## Structure

```
webhookbearerapi/
├── front/            pages du plugin (liste et formulaire des API bearer)
├── locales/          traductions .po/.mo + modèle .pot
├── src/
│   ├── BearerApi.php         itemtype fournisseur de jetons + onglet + cron
│   └── TokenManager.php      récupération, cache et injection des jetons
├── templates/        formulaires Twig
├── hook.php          installation, hooks, tâche planifiée
├── setup.php         déclarations du plugin et des hooks
└── webhookbearerapi.xml      manifeste marketplace
```

## Licence

GPL v2 ou ultérieure (GPLv2+). Voir [LICENSE](LICENSE).

## Auteur & liens

- Auteur : **Loonaire**
- Dépôt : <https://github.com/loonaire/glpi-webhookbearerapi>
- Version : 1.0 — compatible GLPI `~12.0.0`
