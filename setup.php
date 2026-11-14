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

use Glpi\Config\ProxyExclusion;
use Glpi\Config\ProxyExclusions;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Webhookbearerapi\BearerApi;

define('PLUGIN_WEBHOOKBEARERAPI_VERSION', '1.1');

// Minimal GLPI version, inclusive
define('PLUGIN_WEBHOOKBEARERAPI_MIN_GLPI', '12.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_WEBHOOKBEARERAPI_MAX_GLPI', '12.0.99');

/**
 * Init hooks of the plugin.
 *
 * @return void
 */
function plugin_init_webhookbearerapi()
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    // "Bearer API" tab on the webhook forms (token provider used by the webhook).
    Plugin::registerClass(BearerApi::class, ['addtabon' => Webhook::class]);

    // Entry of the "Setup" menu, close to the native webhooks.
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['webhookbearerapi'] = ['config' => BearerApi::class];

    // Token retrieval requests are sent with the GLPI HTTP client, in the context of the
    // BearerApi class: this entry lets administrators exclude these requests from the proxy
    // (Setup > General > System).
    if (($CFG_GLPI['possible_proxy_exclusions'] ?? null) instanceof ProxyExclusions) {
        $CFG_GLPI['possible_proxy_exclusions']->addExclusion(new ProxyExclusion(
            BearerApi::class,
            __('Webhook Bearer API', 'webhookbearerapi'),
            __('Retrieval of the bearer tokens of the API endpoints', 'webhookbearerapi')
        ));
    }

    // Fields stored encrypted with the GLPI key: allows handling them with the
    // `glpi:security:changekey` command.
    $PLUGIN_HOOKS[Hooks::SECURED_FIELDS]['webhookbearerapi'] = [
        BearerApi::getTable() . '.client_secret',
        BearerApi::getTable() . '.password',
        BearerApi::getTable() . '.access_token',
    ];

    // Inject the "Authorization: Bearer <token>" header into the queued webhooks
    // targeting a configured API, just before the queue entry is created.
    // Note: since GLPI 12, hooks called with an object parameter are only triggered
    // when registered for the specific itemtype (see Plugin::doHook).
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['webhookbearerapi'] = [
        QueuedWebhook::class => 'plugin_webhookbearerapi_pre_item_add',
        Webhook::class       => 'plugin_webhookbearerapi_webhook_prepare',
    ];

    // Keep the native OAuth 2.0 option of the webhooks consistent with their authentication
    // source, then record the source chosen on the webhook form once the webhook is saved.
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['webhookbearerapi'] = [
        Webhook::class => 'plugin_webhookbearerapi_webhook_prepare',
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['webhookbearerapi'] = [
        Webhook::class => 'plugin_webhookbearerapi_webhook_save_auth_source',
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['webhookbearerapi'] = [
        Webhook::class => 'plugin_webhookbearerapi_webhook_save_auth_source',
    ];

    // "API authentication source" field of the main webhook form.
    $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['webhookbearerapi'] = 'plugin_webhookbearerapi_post_item_form';

    // Remove the token providers of a webhook when it is purged.
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['webhookbearerapi'] = [
        Webhook::class => 'plugin_webhookbearerapi_pre_item_purge',
    ];
}

/**
 * Get the name and the version of the plugin.
 *
 * @return array
 */
function plugin_version_webhookbearerapi()
{
    return [
        'name'         => __('Webhook Bearer API', 'webhookbearerapi'),
        'version'      => PLUGIN_WEBHOOKBEARERAPI_VERSION,
        'author'       => 'Loonaire',
        'license'      => 'GPLv2+',
        'homepage'     => 'https://github.com/loonaire/glpi-webhookbearerapi',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_WEBHOOKBEARERAPI_MIN_GLPI,
                'max' => PLUGIN_WEBHOOKBEARERAPI_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.3',
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install.
 *
 * @return bool
 */
function plugin_webhookbearerapi_check_prerequisites()
{
    return true;
}

/**
 * Check configuration process.
 *
 * @param bool $verbose Whether to display message on failure. Defaults to false
 *
 * @return bool
 */
function plugin_webhookbearerapi_check_config($verbose = false)
{
    return true;
}
