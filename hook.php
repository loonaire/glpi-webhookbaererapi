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

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(BearerApi::getTable())) {
        $DB->doQuery("CREATE TABLE `" . BearerApi::getTable() . "` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `is_active` tinyint NOT NULL DEFAULT 1,
            `is_deleted` tinyint NOT NULL DEFAULT 0,
            `webhooks_id` int {$default_key_sign} DEFAULT NULL,
            `token_url` varchar(255) DEFAULT NULL,
            `grant_type` varchar(32) NOT NULL DEFAULT 'client_credentials',
            `auth_method` varchar(16) NOT NULL DEFAULT 'body',
            `body_format` varchar(16) NOT NULL DEFAULT 'form',
            `client_id` varchar(255) DEFAULT NULL,
            `client_secret` varchar(255) DEFAULT NULL,
            `username` varchar(255) DEFAULT NULL,
            `password` varchar(255) DEFAULT NULL,
            `scope` varchar(255) DEFAULT NULL,
            `extra_params` text,
            `token_field` varchar(64) NOT NULL DEFAULT 'access_token',
            `expires_in_field` varchar(64) NOT NULL DEFAULT 'expires_in',
            `default_expires_in` int NOT NULL DEFAULT 3600,
            `refresh_margin` int NOT NULL DEFAULT 300,
            `header_name` varchar(64) NOT NULL DEFAULT 'Authorization',
            `token_prefix` varchar(32) NOT NULL DEFAULT 'Bearer',
            `access_token` text,
            `token_expires_at` datetime DEFAULT NULL,
            `last_refresh` datetime DEFAULT NULL,
            `last_error` text,
            `comment` text,
            `date_mod` datetime DEFAULT NULL,
            `date_creation` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `webhooks_id` (`webhooks_id`),
            KEY `is_active` (`is_active`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;");
    }

    if (!$DB->tableExists(BearerApi::getConfigTable())) {
        $DB->doQuery("CREATE TABLE `" . BearerApi::getConfigTable() . "` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `webhooks_id` int {$default_key_sign} NOT NULL,
            `auth_source` varchar(16) NOT NULL DEFAULT 'plugin',
            PRIMARY KEY (`id`),
            UNIQUE KEY `webhooks_id` (`webhooks_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;");
    }

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

    return true;
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
 * Hook called before any item is purged.
 *
 * Removes the token providers and the authentication source choice attached to a purged
 * webhook.
 *
 * @param CommonDBTM $item The item being purged
 *
 * @return void
 */
function plugin_webhookbearerapi_pre_item_purge(CommonDBTM $item)
{
    global $DB;

    if (!$item instanceof Webhook) {
        return;
    }

    $DB->delete(BearerApi::getTable(), ['webhooks_id' => $item->getID()]);

    BearerApi::deleteAuthSource($item->getID());
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

    $source = BearerApi::getEffectiveAuthSource($item->getID());

    $name  = 'plugin_webhookbearerapi_auth_source';
    $rand  = mt_rand();
    $row_id    = $name . '_row_' . $rand;
    $select_id = 'dropdown_' . $name . $rand;

    $label = htmlspecialchars(
        __('API authentication source', 'webhookbearerapi'),
        ENT_QUOTES,
        'UTF-8'
    );

    $choices = [
        BearerApi::SOURCE_NATIVE => __('Native GLPI (OAuth 2.0)', 'webhookbearerapi'),
        BearerApi::SOURCE_PLUGIN => __('Bearer API plugin', 'webhookbearerapi'),
    ];

    $helpers = [
        BearerApi::SOURCE_NATIVE => __(
            'The webhook authenticates with the OAuth 2.0 parameters defined in the "Security" tab. This plugin does not add any token.',
            'webhookbearerapi'
        ),
        BearerApi::SOURCE_PLUGIN => __(
            'The webhook is authenticated with a token managed by this plugin (see the "Bearer tokens" tab). The "Use OAuth" option of the "Security" tab is then disabled.',
            'webhookbearerapi'
        ),
    ];

    // Build the tooltip content like the "label" macro of basic_inputs_macros.html.twig:
    // the text is escaped first, then the line breaks are replaced with <br>.
    $helper_text = implode("\n", [
        sprintf('%s : %s', $choices[BearerApi::SOURCE_NATIVE], $helpers[BearerApi::SOURCE_NATIVE]),
        sprintf('%s : %s', $choices[BearerApi::SOURCE_PLUGIN], $helpers[BearerApi::SOURCE_PLUGIN]),
    ]);
    $helper_aria = htmlspecialchars($helper_text, ENT_QUOTES, 'UTF-8');
    $helper_attr = str_replace("\n", '<br>', $helper_aria);

    // Same markup as the "form-help" icon of the native fields (like the "URL" field).
    $form_help = "<span class='form-help' tabindex='0'"
        . " aria-label='{$helper_aria}'"
        . " data-bs-toggle='tooltip'"
        . " data-bs-trigger='hover focus'"
        . " data-bs-placement='top'"
        . " data-bs-html='true'"
        . " data-bs-title='{$helper_attr}'>?</span>";

    // Reproduce the markup of the "horizontalField" macro (components/form/fields_macros.html.twig)
    // so the field aligns with the other fields of the native form.
    echo "<div class='form-field row align-items-center col-12 col-sm-6 mb-2 plugin-wba-auth-source' id='{$row_id}'>";
    echo "<label class='col-form-label col-xxl-5 text-xxl-end' for='{$select_id}'>{$label}{$form_help}</label>";
    echo "<div class='col-xxl-7 field-container'>";

    Dropdown::showFromArray($name, $choices, [
        'value' => $source,
        'rand'  => $rand,
        'width' => '100%',
    ]);

    echo "</div></div>";

    // The form is re-fetched by the tab system each time the "Webhook" tab is shown,
    // possibly without executing the injected scripts: use a global MutationObserver,
    // registered once, to relocate the field below "Log in item history" and remove the
    // stale copies left by the previous renders. Vanilla JS, as no script ordering with
    // jQuery can be assumed on AJAX re-injections.
    echo "<script>";
    echo "(function () {";
    echo "var place = function () {";
    echo "var rows = document.querySelectorAll('.plugin-wba-auth-source');";
    echo "var last = rows[rows.length - 1];";
    echo "if (!last) { return; }";
    echo "for (var i = 0; i < rows.length - 1; i++) { rows[i].parentNode.removeChild(rows[i]); }";
    echo "var form = last.closest('form');";
    echo "if (!form) { return; }";
    echo "var target = form.querySelector('[data-testid=\"form-field-log_in_item_history\"]');";
    echo "if (target && target.nextElementSibling !== last) { target.parentNode.insertBefore(last, target.nextElementSibling); }";
    // The tooltip icon may belong to freshly injected content: ensure it is initialized.
    echo "var help = last.querySelector('.form-help');";
    echo "if (help && window.bootstrap && bootstrap.Tooltip) { bootstrap.Tooltip.getOrCreateInstance(help); }";
    echo "};";
    echo "if (!window.wba_auth_source_observer) {";
    echo "window.wba_auth_source_observer = new MutationObserver(place);";
    echo "window.wba_auth_source_observer.observe(document.body, {childList: true, subtree: true});";
    echo "}";
    echo "place();";
    echo "})();";
    echo "</script>";
}

/**
 * Hook called before a webhook is added or updated: records the authentication source
 * choice and keeps the native OAuth 2.0 option of GLPI disabled when this plugin is
 * selected, as the core fetches and sends its own token which would then replace the
 * token provided by this plugin.
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

    $submitted = $item->input['plugin_webhookbearerapi_auth_source'] ?? null;

    if ($submitted === BearerApi::SOURCE_NATIVE || $submitted === BearerApi::SOURCE_PLUGIN) {
        $source = $submitted;

        // On additions, the id does not exist yet: the choice is recorded by the
        // item_add hook.
        if (!$item->isNewID($item->getID())) {
            BearerApi::setAuthSource($item->getID(), $submitted);
        }
    } else {
        $source = $item->isNewID($item->getID())
            ? BearerApi::SOURCE_NATIVE
            : BearerApi::getEffectiveAuthSource($item->getID());
    }

    if ($source === BearerApi::SOURCE_PLUGIN) {
        $item->input['use_oauth'] = 0;
    }
}

/**
 * Hook called after a webhook is added: records the authentication source choice made on
 * the webhook form.
 *
 * @param CommonDBTM $item The added webhook
 *
 * @return void
 */
function plugin_webhookbearerapi_webhook_post_add(CommonDBTM $item)
{
    if (!$item instanceof Webhook) {
        return;
    }

    $submitted = $item->input['plugin_webhookbearerapi_auth_source'] ?? null;

    if ($submitted === BearerApi::SOURCE_NATIVE || $submitted === BearerApi::SOURCE_PLUGIN) {
        BearerApi::setAuthSource($item->getID(), $submitted);
    }
}
