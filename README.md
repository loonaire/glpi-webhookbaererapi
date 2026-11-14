# Webhook Bearer API

Plugin GLPI 12 — authentification par jeton bearer pour les webhooks appelant des API.

Webhook Bearer API ajoute un fournisseur de jetons aux webhooks GLPI : définissez un
serveur de jetons (OAuth2 *client credentials* ou *password grant*), le jeton bearer est
automatiquement ajouté aux en-têtes de la requête à l'envoi du webhook. Une tâche
planifiée renouvelle les jetons avant leur expiration.

## Fonctionnalités

- **Fournisseurs « API bearer »** (Configuration > Générale > API bearer) : configurations
  génériques de serveurs de jetons, utilisables par autant de webhooks que nécessaire :
  - URL du jeton, type d'autorisation *client credentials* ou *mot de passe* ;
  - envoi des identifiants dans le corps (formulaire ou JSON) ou en en-tête HTTP Basic ;
  - champs personnalisés du jeton et de l'expiration dans la réponse JSON, portée
    (*scope*), paramètres supplémentaires ;
  - nom d'en-tête et préfixe du jeton configurables (par défaut `Authorization: Bearer`) ;
  - onglet « Webhooks » : webhooks qui utilisent le fournisseur.
- **Source d'authentification de l'API** par webhook (formulaire du webhook) :
  - *GLPI natif (OAuth 2.0)* : utilise les paramètres « Utiliser OAuth » de l'onglet
    Sécurité ; le plugin n'ajoute aucun jeton ;
  - un fournisseur du plugin : le jeton qu'il délivre est ajouté aux requêtes (l'option
    « Utiliser OAuth » est alors désactivée).
- **Statut** : onglet « API bearer » sur chaque webhook — fournisseur utilisé, état du
  jeton, date d'expiration, dernière erreur.
- **Tâche planifiée** `RefreshTokens` (toutes les 10 minutes par défaut) : renouvelle les
  jetons des fournisseurs actifs avant leur expiration.
- Purge propre : le choix du fournisseur est supprimé avec le webhook, les données du
  plugin à la désinstallation.

## Prérequis

- GLPI `~12.0.0` ;
- PHP requis par GLPI 12 ;
- base de données MariaDB/MySQL.

## Installation

1. Copier le dossier `webhookbearerapi` dans `glpi/plugins/` ;
2. Dans GLPI : **Configuration > Plugins**, installer puis activer « Webhook Bearer API ».

La table `glpi_plugin_webhookbearerapi_bearerapis` (fournisseurs et jetons en cache), la
table `glpi_plugin_webhookbearerapi_webhookconfigs` (fournisseur utilisé par chaque
webhook) et la tâche planifiée sont créées à l'activation.

## Configuration

1. **Créer un fournisseur** : Configuration > Générale > API bearer. Renseigner l'URL du
   jeton, le type d'autorisation et les identifiants. Le fournisseur décrit un serveur de
   jetons, indépendamment des webhooks : un même fournisseur peut servir plusieurs
   webhooks appelant la même API.
2. **Choisir le fournisseur sur chaque webhook** : champ « Source d'authentification de
   l'API » du formulaire du webhook :
   - *GLPI natif (OAuth 2.0)* : le plugin n'ajoute aucun jeton ;
   - un fournisseur (groupe « Fournisseurs de jetons ») : le jeton est ajouté aux
     requêtes du webhook. L'option « Utiliser OAuth » de l'onglet Sécurité est alors
     désactivée (un message l'indique), car GLPI remplacerait sinon le jeton du plugin.
3. **Vérifier le jeton** : onglet « API bearer » du webhook (fournisseur utilisé, état et
   expiration du jeton, dernière erreur) ou onglet « Webhooks » du fournisseur. Un bouton
   du formulaire du fournisseur permet de forcer un rafraîchissement.

À la mise à jour depuis la version 1.0, le fournisseur rattaché à chaque webhook
(ancien champ « Webhook » du fournisseur) devient le fournisseur choisi par ce webhook,
sauf si le webhook utilisait l'authentification native de GLPI.

## Fonctionnement de l'envoi

Deux URL interviennent :

- l'**URL du jeton** du fournisseur : le plugin y demande le jeton ;
- l'**URL cible** du webhook (champ « URL » du formulaire du webhook) : GLPI y envoie la
  requête, à laquelle le plugin ajoute l'en-tête `Authorization: Bearer <jeton>`.

Lors d'un événement, GLPI crée une entrée dans la file d'attente des webhooks en y
recopiant l'URL cible, les en-têtes et le contenu ; la tâche planifiée « queuedwebhook »
envoie ensuite cette entrée, et la renvoie en cas d'échec avec ces mêmes copies. Le
plugin :

- ajoute le jeton aux en-têtes de l'entrée ;
- ajoute `Content-Type: application/json` quand le contenu est du JSON et que le webhook
  ne définit pas cet en-tête : sans lui, GLPI envoie le contenu comme un formulaire
  (`application/x-www-form-urlencoded`) et les API JSON, dont celle de GLPI, l'ignorent ;
- met à jour les entrées encore à envoyer quand le webhook est modifié, puis à chaque
  passage de la tâche planifiée `RefreshTokens` : URL cible actuelle du webhook (une URL
  construite avec des variables, comme `{{ item.id }}`, n'est recalculée que si le webhook
  utilise le contenu par défaut), jeton courant, `Content-Type` ;
- retire le jeton des entrées déjà envoyées, pour qu'il ne reste pas lisible dans la file
  (il est aussi retiré à la suppression du fournisseur et à la désinstallation).

Les requêtes réellement envoyées (URL, code de retour, nombre d'essais) sont visibles
dans l'onglet « Requêtes » du webhook ; les erreurs d'envoi sont journalisées dans
`files/_log/webhook.log`, celles de récupération du jeton dans
`files/_log/webhookbearerapi.log`.

La fréquence de `RefreshTokens` (10 minutes par défaut) doit rester inférieure à la durée
de vie des jetons.

L'authentification challenge-réponse (« Utiliser le challenge CRA ? » dans l'onglet
Sécurité du webhook, ou constante `GLPI_WEBHOOK_CRA_MANDATORY`) n'est pas compatible :
GLPI envoie sa requête de vérification sans le jeton, l'API la refuse et le webhook n'est
jamais envoyé. Le plugin l'indique par un avertissement.

Si le serveur de jetons ne répond pas, la requête est abandonnée au bout de 10 secondes et
aucune nouvelle demande n'est faite pendant 60 secondes lors de la mise en file des
webhooks, pour ne pas ralentir l'enregistrement des éléments.

## Serveur de jetons sur un réseau privé

Pour se protéger des attaques SSRF, GLPI refuse par défaut les requêtes émises côté
serveur vers une adresse privée ou locale (`10.0.0.0/8`, `172.16.0.0/12`,
`192.168.0.0/16`, `127.0.0.0/8`, etc.). Un serveur de jetons interne (Keycloak, ADFS…)
est donc refusé : la dernière erreur du fournisseur contient alors
`... is blocked for "https://..."`.

Pour l'autoriser, ajouter le contexte du plugin à la constante
`GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS` dans le fichier
`config/local_define.php` de GLPI (à créer s'il n'existe pas). La constante **remplace**
la liste par défaut de GLPI : y reprendre les contextes `Agent` et `Auth` pour les
conserver.

```php
<?php

define('GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS', [
    Agent::class, // Agents d'inventaire (valeur par défaut de GLPI)
    Auth::class,  // Authentifications externes (valeur par défaut de GLPI)
    GlpiPlugin\Webhookbearerapi\BearerApi::class, // Serveurs de jetons de ce plugin
    // Webhook::class, // À ajouter si l'API appelée par le webhook est elle aussi sur le réseau privé
]);
```

Les requêtes de jeton passent par le proxy configuré dans GLPI. Pour un serveur de jetons
joignable directement, cocher l'exclusion « Webhook Bearer API » dans
**Configuration > Générale > Système** (section proxy).

## Exemple : envoyer vers l'API d'un autre GLPI

Objectif : créer un ticket sur un GLPI « cible » quand un ticket est créé sur le GLPI
« source », via l'API v2 de la cible (`api.php`, authentification OAuth 2.0). L'ancienne
API `apirest.php` n'utilise pas de jeton bearer et ne convient pas.

**Sur le GLPI cible**

1. **Configuration > Générale > API** : activer « Activer l'API ».
2. Créer un utilisateur dédié (par exemple `api-webhook`) avec un mot de passe et un
   profil autorisé à créer des tickets dans l'entité voulue.
3. **Configuration > Clients OAuth** : ajouter un client avec le grant
   **Mot de passe** et le scope **api** ; noter le *Client ID* et le *secret client*.
   Le grant *Client credentials* ne convient pas : le jeton n'est alors lié à aucun
   utilisateur et l'API répond « You are not authenticated ».
4. Repérer l'URL de l'API dans la documentation `https://glpi-cible/api.php/doc` :
   par exemple `https://glpi-cible/api.php/v2/Assistance/Ticket` pour créer un ticket
   (le préfixe de version, `v2` ou `v3`, dépend de la version de la cible).

**Sur le GLPI source**

1. **Configuration > Générale > API bearer** : créer un fournisseur :
   - URL du jeton : `https://glpi-cible/api.php/token` ;
   - type d'autorisation : *Password* ; identifiants dans le corps ; format
     `application/x-www-form-urlencoded` ;
   - Client ID / secret client : ceux du client OAuth de la cible ;
   - utilisateur / mot de passe : ceux de `api-webhook` ;
   - portée : `api` (obligatoire : sans elle, l'API répond « You do not have the
     required scope(s) ») ;
   - laisser les autres champs par défaut (`access_token`, `expires_in`,
     `Authorization`, `Bearer`), enregistrer puis cliquer sur « Rafraîchir le jeton
     maintenant » : l'état du jeton doit être « Valide ».
2. **Configuration > Webhooks** : créer un webhook :
   - type d'élément *Ticket*, événement *Nouveau* ;
   - URL : `https://glpi-cible/api.php/v2/Assistance/Ticket`, méthode **POST** ;
   - source d'authentification de l'API : le fournisseur créé ci-dessus ;
   - onglet **Sécurité** : laisser « Utiliser OAuth » et « Utiliser le challenge CRA ? »
     désactivés ;
   - onglet **Payload** : remplacer le contenu par défaut, qui ne correspond pas au format
     attendu par l'API de la cible, par exemple :

     ```twig
     {
         "name": {{ item.name|json_encode|raw }},
         "content": {{ item.content|json_encode|raw }}
     }
     ```

     Le filtre `json_encode|raw` produit des chaînes JSON valides même avec des retours à
     la ligne (GLPI préfixe toutefois les guillemets doubles des valeurs d'une barre oblique
     inverse, visible sur la cible) ; les champs disponibles sont proposés par l'éditeur
     (`item.…`).
   - optionnel, onglet **En-têtes** : `GLPI-Entity` (id de l'entité cible) ou
     `GLPI-Profile` (id du profil) pour choisir l'entité ou le profil utilisés sur la cible.
3. Si la cible est sur un réseau privé, ajouter aussi `Webhook::class` à
   `GLPI_SERVERSIDE_URL_ALLOWED_PRIVATE_NETWORKS_CONTEXTS` (voir la section précédente).
4. Créer un ticket sur la source, puis vérifier l'onglet « Requêtes » du webhook (envoi
   par la tâche planifiée « queuedwebhook », au plus tôt une minute après l'événement) :
   un code `201` indique que le ticket a été créé sur la cible.

**Erreurs fréquentes** (journal `files/_log/webhook.log` ; l'onglet « API bearer » du
webhook résume la dernière requête : URL, code de retour, présence du jeton, validité du
JSON) :

| Message de la cible | Cause | Solution |
|---|---|---|
| `Invalid JSON body` | Le contenu envoyé avec `Content-Type: application/json` n'est pas du JSON valide (valeur texte contenant des retours à la ligne ou des guillemets, modèle du payload en erreur). | Écrire les valeurs texte du payload avec `{{ item.champ\|json_encode\|raw }}`, sans guillemets autour. |
| `You are not authenticated` / `The Authorization header is missing or invalid` | Jeton absent des en-têtes (webhook sans fournisseur, fournisseur inactif, échec de récupération du jeton) ou jeton *Client credentials*, lié à aucun utilisateur. | Choisir le fournisseur dans le formulaire du webhook ; utiliser le type d'autorisation *Password*. |
| `You do not have the required scope(s) to access this endpoint` | Jeton sans la portée `api`. | Mettre `api` dans la portée du fournisseur et cocher le scope `api` sur le client OAuth de la cible ; le plugin redemande un jeton dès que les paramètres du fournisseur sont enregistrés. |
| `You don't have permission to perform this action` (sans mention de portée) | L'utilisateur de l'API n'a pas le droit demandé sur la cible. | Ajuster le profil de l'utilisateur, ou choisir le profil et l'entité avec les en-têtes `GLPI-Profile` / `GLPI-Entity`. |

## Traductions

Anglais (en_US, en_GB) et français (fr_FR) — fichiers dans `locales/`, modèle
`locales/webhookbearerapi.pot`.

Après une modification des chaînes, `bash tools/update_locales.sh` (GNU gettext requis)
régénère le modèle, met à jour les `.po` et recompile les `.mo` ; traduire ensuite les
nouvelles entrées de `locales/fr_FR.po` et relancer le script.

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
- Version : 1.1 — compatible GLPI `~12.0.0`
