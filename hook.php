<?php

/**
 * -------------------------------------------------------------------------
 * Webhook Bearer API plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Webhook Bearer API.
 *
 * Webhook Bearer API is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Webhook Bearer API is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Webhook Bearer API. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @license   GPLv2+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://github.com/loonaire/glpi-webhookbearerapi
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Webhookbearerapi\BearerApi;
use GlpiPlugin\Webhookbearerapi\TokenManager;

/**
 * Plugin install process.
 *
 * @return bool
 */
function plugin_webhookbearerapi_install()
{
    global $DB;

    $migration = new Migration(PLUGIN_WEBHOOKBEARERAPI_VERSION);

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    // Token providers: generic token server configurations, shared by any number of webhooks.
    if (!$DB->tableExists(BearerApi::getTable())) {
        $DB->doQuery("CREATE TABLE `" . BearerApi::getTable() . "` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `is_active` tinyint NOT NULL DEFAULT 1,
            `is_deleted` tinyint NOT NULL DEFAULT 0,
            `token_url` varchar(255) DEFAULT NULL,
            `grant_type` varchar(32) NOT NULL DEFAULT 'client_credentials',
            `auth_method` varchar(16) NOT NULL DEFAULT 'body',
            `body_format` varchar(16) NOT NULL DEFAULT 'form',
            `client_id` varchar(255) DEFAULT NULL,
            `client_secret` text,
            `username` varchar(255) DEFAULT NULL,
            `password` text,
            `scope` varchar(255) DEFAULT NULL,
            `extra_params` text,
            `token_field` varchar(64) NOT NULL DEFAULT 'access_token',
            `expires_in_field` varchar(64) NOT NULL DEFAULT 'expires_in',
            `default_expires_in` int NOT NULL DEFAULT 3600,
            `refresh_margin` int NOT NULL DEFAULT 300,
            `header_name` varchar(64) NOT NULL DEFAULT 'Authorization',
            `token_prefix` varchar(32) NOT NULL DEFAULT 'Bearer',
            `access_token` text,
            `token_expires_at` timestamp NULL DEFAULT NULL,
            `last_refresh` timestamp NULL DEFAULT NULL,
            `last_failure` timestamp NULL DEFAULT NULL,
            `last_error` text,
            `comment` text,
            `date_mod` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `is_active` (`is_active`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;");
    }

    // Token provider used by each webhook (no row: the webhook uses the native authentication).
    if (!$DB->tableExists(BearerApi::getConfigTable())) {
        $DB->doQuery("CREATE TABLE `" . BearerApi::getConfigTable() . "` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `webhooks_id` int {$default_key_sign} NOT NULL,
            `plugin_webhookbearerapi_bearerapis_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `webhooks_id` (`webhooks_id`),
            KEY `plugin_webhookbearerapi_bearerapis_id` (`plugin_webhookbearerapi_bearerapis_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;");
    }

    plugin_webhookbearerapi_upgrade($migration);

    // Task refreshing the bearer tokens before they expire. Its frequency must stay
    // shorter than the shortest token lifetime used by the configured APIs.
    CronTask::register(
        BearerApi::class,
        'RefreshTokens',
        10 * MINUTE_TIMESTAMP,
        [
            'comment' => __('Refresh the bearer tokens of the APIs used by the webhooks', 'webhookbearerapi'),
        ]
    );

    $migration->executeMigration();

    return true;
}

/**
 * Upgrade the tables created by a previous version of the plugin.
 *
 * Every step checks the current state of the tables, so the function does nothing on tables that
 * are already up to date (fresh installation).
 *
 * @param Migration $migration
 *
 * @return void
 */
function plugin_webhookbearerapi_upgrade(Migration $migration)
{
    global $DB;

    $table        = BearerApi::getTable();
    $config_table = BearerApi::getConfigTable();
    $fk           = BearerApi::getForeignKeyField();

    // 1.1: once encrypted, a secret longer than ~150 characters no longer fits in 255 characters.
    foreach (['client_secret', 'password'] as $field) {
        $type = strtolower((string) ($DB->getField($table, $field, false)['Type'] ?? ''));
        if (str_starts_with($type, 'varchar')) {
            $migration->changeField($table, $field, $field, 'text');
        }
    }

    // 1.1: store the dates in `timestamp` columns, like the GLPI core. The values of `datetime`
    // columns depend on the timezone of the process that wrote them (user web request or cron),
    // which made the token expiration checks unreliable when the timezones are enabled.
    $converted_dates = false;
    foreach (['token_expires_at', 'last_refresh', 'date_mod', 'date_creation'] as $field) {
        $type = strtolower((string) ($DB->getField($table, $field, false)['Type'] ?? ''));
        if (str_starts_with($type, 'datetime')) {
            $migration->changeField($table, $field, $field, 'timestamp');
            $converted_dates = true;
        }
    }

    // 1.1: date of the last failed token request, used to wait before trying again.
    $migration->addField($table, 'last_failure', 'timestamp', ['after' => 'last_refresh']);

    $migration->migrationOneTable($table);

    if ($converted_dates) {
        // The cached expiration dates may have been written in another timezone: renew the tokens.
        $DB->update($table, ['token_expires_at' => null], ['NOT' => ['token_expires_at' => null]]);
    }

    // 1.1: the webhooks choose their token provider, so that a provider (generic token server
    // configuration) can be shared by several webhooks. The link moves from the providers
    // (`webhooks_id`, one webhook per provider) to the webhook configuration table, which replaces
    // its `auth_source` column ("native" or "plugin") with the provider used.
    $migration->addField($config_table, $fk, 'fkey', ['after' => 'webhooks_id']);
    $migration->addKey($config_table, $fk);
    $migration->migrationOneTable($config_table);

    if ($DB->fieldExists($table, 'webhooks_id', false)) {
        $has_auth_source = $DB->fieldExists($config_table, 'auth_source', false);
        $migrated        = [];

        // In version 1.0, a webhook used the oldest of its providers outside the trash.
        $iterator = $DB->request([
            'SELECT' => ['id', 'webhooks_id'],
            'FROM'   => $table,
            'WHERE'  => ['webhooks_id' => ['>', 0]],
            'ORDER'  => ['is_deleted ASC', 'id ASC'],
        ]);
        foreach ($iterator as $row) {
            $webhooks_id = (int) $row['webhooks_id'];
            if (isset($migrated[$webhooks_id])) {
                continue;
            }
            $migrated[$webhooks_id] = true;

            $config = $has_auth_source
                ? $DB->request([
                    'SELECT' => 'auth_source',
                    'FROM'   => $config_table,
                    'WHERE'  => ['webhooks_id' => $webhooks_id],
                ])->current()
                : null;

            if ($config !== null) {
                $uses_provider = $config['auth_source'] === 'plugin';
            } else {
                // No explicit choice: the provider was used unless the native OAuth 2.0 option of
                // the webhook was enabled (GLPI then replaced the token of the plugin).
                $webhook = $DB->request([
                    'SELECT' => 'use_oauth',
                    'FROM'   => Webhook::getTable(),
                    'WHERE'  => ['id' => $webhooks_id],
                ])->current();
                $uses_provider = $webhook !== null && !$webhook['use_oauth'];
            }

            if ($uses_provider) {
                $DB->updateOrInsert($config_table, [$fk => (int) $row['id']], ['webhooks_id' => $webhooks_id]);
            }
        }

        // Dropping the column also drops its index.
        $migration->dropField($table, 'webhooks_id');
        $migration->migrationOneTable($table);
    }

    $migration->dropField($config_table, 'auth_source');
    $migration->migrationOneTable($config_table);

    // A webhook without provider uses the native authentication: no configuration row needed.
    $DB->delete($config_table, [$fk => 0]);

    // 1.1: the "Webhook" search option (4) of the providers no longer exists.
    $DB->delete(DisplayPreference::getTable(), ['itemtype' => BearerApi::class, 'num' => 4]);
}

/**
 * Plugin uninstall process.
 *
 * @return bool
 */
function plugin_webhookbearerapi_uninstall()
{
    global $DB;

    if ($DB->tableExists(BearerApi::getTable())) {
        // Do not leave the tokens of this plugin readable in the webhook queue.
        TokenManager::removeAllTokensFromQueue();

        $DB->doQuery('DROP TABLE `' . BearerApi::getTable() . '`');
    }

    if ($DB->tableExists(BearerApi::getConfigTable())) {
        $DB->doQuery('DROP TABLE `' . BearerApi::getConfigTable() . '`');
    }

    CronTask::unregister('webhookbearerapi');

    return true;
}

/**
 * Hook called before any item is added.
 *
 * Injects the bearer token header into the queued webhooks targeting an API configured
 * in this plugin, before the queue entry is inserted in the database.
 *
 * @param CommonDBTM $item The item being added
 *
 * @return void
 */
function plugin_webhookbearerapi_pre_item_add(CommonDBTM $item)
{
    if (!$item instanceof QueuedWebhook) {
        return;
    }

    TokenManager::prepareQueuedWebhook($item);
}

/**
 * Hook called before any item is purged: forgets the token provider used by a purged webhook.
 * The token providers themselves are shared configurations and are kept.
 *
 * @param CommonDBTM $item The item being purged
 *
 * @return void
 */
function plugin_webhookbearerapi_pre_item_purge(CommonDBTM $item)
{
    if (!$item instanceof Webhook) {
        return;
    }

    BearerApi::setWebhookProvider($item->getID(), 0);
}

/**
 * Hook called after the fields of an item form: displays the "API authentication source"
 * choice on the webhook forms.
 *
 * @param array $params The hook parameters ('item' => displayed item, 'options' => form options)
 *
 * @return void
 */
function plugin_webhookbearerapi_post_item_form(array $params)
{
    $item    = $params['item'] ?? null;
    $options = $params['options'] ?? [];

    if (!$item instanceof Webhook || (int) ($options['withtemplate'] ?? 0) > 0) {
        return;
    }

    // The hook is called by the form of each webhook tab. The "Security" and "Custom headers"
    // forms are displayed without footer (see Webhook::showSecurityForm() and
    // Webhook::showCustomHeaders()): the field only belongs to the main form.
    if (($options['formfooter'] ?? true) === false) {
        return;
    }

    $provider_id = $item->isNewItem() ? 0 : BearerApi::getWebhookProviderId($item->getID());

    // One line per kind of choice in the tooltip (the field macro escapes the text, then
    // converts the line breaks).
    $helper = implode("\n", [
        sprintf(
            '%s : %s',
            __('Native GLPI (OAuth 2.0)', 'webhookbearerapi'),
            __('The webhook authenticates with the OAuth 2.0 parameters defined in the "Security" tab. This plugin does not add any token.', 'webhookbearerapi')
        ),
        sprintf(
            '%s : %s',
            BearerApi::getProvidersGroupLabel(),
            __('The webhook is authenticated with a token delivered by the selected provider (Setup > General > Bearer APIs). The "Use OAuth" option of the "Security" tab is then disabled.', 'webhookbearerapi')
        ),
    ]);

    TemplateRenderer::getInstance()->display('@webhookbearerapi/webhook_auth_source.html.twig', [
        'value'   => $provider_id > 0 ? (string) $provider_id : BearerApi::SOURCE_NATIVE,
        'choices' => BearerApi::getAuthChoices($provider_id),
        'helper'  => $helper,
    ]);
}

/**
 * Hook called before a webhook is added or updated: keeps the native OAuth 2.0 option of
 * GLPI disabled when the webhook uses a token provider of this plugin, as the core would fetch
 * and send its own token, replacing the token provided by this plugin.
 *
 * The provider chosen on the form is recorded once the webhook is saved (see
 * plugin_webhookbearerapi_webhook_save_auth_source()), so that it is not stored when the
 * webhook is refused.
 *
 * @param CommonDBTM $item The webhook being added or updated
 *
 * @return void
 */
function plugin_webhookbearerapi_webhook_prepare(CommonDBTM $item)
{
    if (!$item instanceof Webhook) {
        return;
    }

    $is_new    = $item->isNewID($item->getID());
    $submitted = plugin_webhookbearerapi_get_submitted_provider($item);
    $current   = $is_new ? 0 : BearerApi::getWebhookProviderId($item->getID());

    // The item_update hook is only called when a field of the webhook changes: when the
    // authentication is the only change of the form, update the modification date so that the
    // choice is recorded.
    if ($submitted !== null && !$is_new && $submitted !== $current) {
        $item->input['date_mod'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }

    if (($submitted ?? $current) === 0) {
        return;
    }

    $use_oauth = $item->input['use_oauth'] ?? ($is_new ? 0 : ($item->fields['use_oauth'] ?? 0));
    if ((bool) $use_oauth) {
        Session::addMessageAfterRedirect(
            __s('The "Use OAuth" option has been disabled: this webhook is authenticated with a token of the Bearer API plugin. To use the native OAuth 2.0 authentication of GLPI, select "Native GLPI (OAuth 2.0)" as API authentication source.', 'webhookbearerapi'),
            false,
            WARNING
        );
    }
    $item->input['use_oauth'] = 0;

    // The challenge-response check is sent by GLPI before each request, without the token.
    $use_cra = $item->input['use_cra_challenge'] ?? ($is_new ? 0 : ($item->fields['use_cra_challenge'] ?? 0));
    if (GLPI_WEBHOOK_CRA_MANDATORY || (bool) $use_cra) {
        Session::addMessageAfterRedirect(
            __s('The challenge-response authentication is enabled for this webhook ("Security" tab or GLPI_WEBHOOK_CRA_MANDATORY constant): GLPI sends its verification request without the bearer token, so a protected API (such as the API of another GLPI) refuses it and the webhook is never sent.', 'webhookbearerapi'),
            false,
            WARNING
        );
    }
}

/**
 * Hook called after a webhook is added or updated: records the token provider chosen on the
 * webhook form and updates the queued webhooks accordingly.
 *
 * @param CommonDBTM $item The added or updated webhook
 *
 * @return void
 */
function plugin_webhookbearerapi_webhook_save_auth_source(CommonDBTM $item)
{
    if (!$item instanceof Webhook) {
        return;
    }

    $webhooks_id = (int) $item->getID();
    $submitted   = plugin_webhookbearerapi_get_submitted_provider($item);
    $previous    = BearerApi::getWebhookProviderId($webhooks_id);

    if ($submitted === null || $submitted === $previous) {
        // Same provider: when the target URL, the payload (from which a template URL is built) or
        // the custom headers change, the queue entries still to be sent follow the new
        // configuration (GLPI copies them into each entry when the event occurs).
        $watched_fields = ['url', 'payload', 'use_default_payload', 'custom_headers'];
        if ($previous > 0 && array_intersect($watched_fields, $item->updates) !== []) {
            TokenManager::syncWebhook($webhooks_id);
        }
        return;
    }

    BearerApi::setWebhookProvider($webhooks_id, $submitted);

    // The queue entries of the webhook must not keep the token of the previous provider, and the
    // ones still to be sent get the token of the new provider.
    $old_provider = new BearerApi();
    if ($previous > 0 && $old_provider->getFromDB($previous)) {
        TokenManager::removeTokensFromQueue($old_provider, $webhooks_id);
    }
    $new_provider = new BearerApi();
    if ($submitted > 0 && $new_provider->getFromDB($submitted)) {
        TokenManager::syncQueuedWebhooks($new_provider);
    }
}

/**
 * Token provider chosen in the "API authentication source" field of the webhook form.
 *
 * @param Webhook $item
 *
 * @return int|null The id of the chosen provider, 0 for the native authentication, or null
 *                  when the input has no valid choice (e.g. form of another tab, API call)
 */
function plugin_webhookbearerapi_get_submitted_provider(Webhook $item): ?int
{
    $submitted = $item->input['plugin_webhookbearerapi_auth'] ?? null;

    if ($submitted === BearerApi::SOURCE_NATIVE) {
        return 0;
    }

    if (is_numeric($submitted) && (int) $submitted > 0) {
        $provider = new BearerApi();
        if ($provider->getFromDB((int) $submitted)) {
            return (int) $submitted;
        }
    }

    return null;
}
