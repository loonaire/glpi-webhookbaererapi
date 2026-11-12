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

namespace GlpiPlugin\Webhookbearerapi;

use CommonDBTM;
use CommonGLPI;
use CronTask;
use Glpi\Application\View\TemplateRenderer;
use Html;
use Log;
use Session;
use Toolbox;
use Webhook;

/**
 * API endpoint delivering bearer tokens used to authenticate the webhooks.
 */
class BearerApi extends CommonDBTM
{
    public static string $rightname = 'config';

    // Not get field
    public const GRANT_CLIENT_CREDENTIALS = 'client_credentials';
    public const GRANT_PASSWORD           = 'password';

    public const AUTH_BODY  = 'body';
    public const AUTH_BASIC = 'http_basic';

    public const FORMAT_FORM = 'form';
    public const FORMAT_JSON = 'json';

    // Source of the bearer token used to authenticate the webhooks.
    public const SOURCE_NATIVE = 'native'; // GLPI core OAuth 2.0 parameters ("Security" tab).
    public const SOURCE_PLUGIN = 'plugin'; // Providers of this plugin ("Bearer tokens" tab).

    /** @var array<int, string> */
    public static array $undisclosedFields = [
        'client_secret', 'password', 'access_token',
    ];

    public static function getTypeName($nb = 0)
    {
        return _n('Bearer API', 'Bearer APIs', $nb, 'webhookbearerapi');
    }

    public static function getSectorizedDetails(): array
    {
        return ['config', self::class];
    }

    public static function getIcon(): string
    {
        return 'ti ti-key';
    }

    public static function canCreate(): bool
    {
        return static::canUpdate();
    }

    public static function canPurge(): bool
    {
        return static::canUpdate();
    }

    public static function canDelete(): bool
    {
        return static::canUpdate();
    }

    /**
     * Supported OAuth2 grant types.
     *
     * @return array<string, string>
     */
    public static function getGrantTypes(): array
    {
        return [
            self::GRANT_CLIENT_CREDENTIALS => __('Client credentials (OAuth2)', 'webhookbearerapi'),
            self::GRANT_PASSWORD           => __('Password (OAuth2 resource owner password credentials)', 'webhookbearerapi'),
        ];
    }

    /**
     * Supported methods to send the client credentials to the token endpoint.
     *
     * @return array<string, string>
     */
    public static function getAuthMethods(): array
    {
        return [
            self::AUTH_BODY  => __('Client credentials in the request body', 'webhookbearerapi'),
            self::AUTH_BASIC => __('HTTP Basic authorization header', 'webhookbearerapi'),
        ];
    }

    /**
     * Supported formats of the token request body.
     *
     * @return array<string, string>
     */
    public static function getBodyFormats(): array
    {
        return [
            self::FORMAT_FORM => 'application/x-www-form-urlencoded',
            self::FORMAT_JSON => 'application/json',
        ];
    }

    public function post_getEmpty()
    {
        $this->fields['is_active']        = 1;
        $this->fields['webhooks_id']      = isset($_GET['webhooks_id']) ? (int) $_GET['webhooks_id'] : null;
        $this->fields['grant_type']       = self::GRANT_CLIENT_CREDENTIALS;
        $this->fields['auth_method']      = self::AUTH_BODY;
        $this->fields['body_format']      = self::FORMAT_FORM;
        $this->fields['token_field']      = 'access_token';
        $this->fields['expires_in_field'] = 'expires_in';
        $this->fields['default_expires_in'] = 3600;
        $this->fields['refresh_margin']   = 300;
        $this->fields['header_name']      = 'Authorization';
        $this->fields['token_prefix']     = 'Bearer';
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'                 => '3',
            'table'              => self::getTable(),
            'field'              => 'is_active',
            'name'               => __('Active'),
            'datatype'           => 'bool',
        ];

        $tab[] = [
            'id'                 => '4',
            'table'              => Webhook::getTable(),
            'field'              => 'name',
            'linkfield'          => 'webhooks_id',
            'name'               => _n('Webhook', 'Webhooks', 1),
            'datatype'           => 'dropdown',
        ];

        $tab[] = [
            'id'                 => '5',
            'table'              => self::getTable(),
            'field'              => 'token_url',
            'name'               => __('Token URL', 'webhookbearerapi'),
            'datatype'           => 'string',
        ];

        $tab[] = [
            'id'                 => '6',
            'table'              => self::getTable(),
            'field'              => 'grant_type',
            'name'               => __('Grant type', 'webhookbearerapi'),
            'datatype'           => 'specific',
            'searchtype'         => 'equals',
        ];

        $tab[] = [
            'id'                 => '7',
            'table'              => self::getTable(),
            'field'              => 'last_refresh',
            'name'               => __('Last token refresh', 'webhookbearerapi'),
            'datatype'           => 'datetime',
            'massiveaction'      => false,
        ];

        $tab[] = [
            'id'                 => '8',
            'table'              => self::getTable(),
            'field'              => 'token_expires_at',
            'name'               => __('Token expiration', 'webhookbearerapi'),
            'datatype'           => 'datetime',
            'massiveaction'      => false,
        ];

        $tab[] = [
            'id'                 => '9',
            'table'              => self::getTable(),
            'field'              => 'last_error',
            'name'               => __('Last error', 'webhookbearerapi'),
            'datatype'           => 'text',
            'massiveaction'      => false,
            'nosearch'           => true,
        ];

        $tab[] = [
            'id'                 => '21',
            'table'              => self::getTable(),
            'field'              => 'date_mod',
            'name'               => __('Last update'),
            'datatype'           => 'datetime',
            'massiveaction'      => false,
        ];

        $tab[] = [
            'id'                 => '122',
            'table'              => self::getTable(),
            'field'              => 'date_creation',
            'name'               => __('Creation date'),
            'datatype'           => 'datetime',
            'massiveaction'      => false,
        ];

        return $tab;
    }

    /**
     * Display the value of the "grant_type" search option.
     *
     * @used-by \Search
     *
     * @param array $options
     *
     * @return string|void
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'grant_type') {
            $types = self::getGrantTypes();
            $value = $values['grant_type'] ?? '';
            return $types[$value] ?? $value;
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab(Log::class, $ong, $options);

        return $ong;
    }

    /**
     * Prepare the input before storing it: encrypt the secrets, validate the URLs and the
     * format of the values.
     *
     * @param array $input
     *
     * @return array|false
     */
    public function prepareInputForAdd($input)
    {
        if (($input = $this->sanitizeInput($input)) === false) {
            return false;
        }

        $input['date_creation'] = date('Y-m-d H:i:s');
        $input['date_mod']      = $input['date_creation'];

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (($input = $this->sanitizeInput($input)) === false) {
            return false;
        }

        $input['date_mod'] = date('Y-m-d H:i:s');

        return $input;
    }

    /**
     * Validate and normalize the given input.
     *
     * @param array $input
     *
     * @return array|false
     */
    private function sanitizeInput($input)
    {
        if (isset($input['token_url'])) {
            $url = trim($input['token_url']);
            if ($url === '' || !Toolbox::isValidWebUrl($url)) {
                Session::addMessageAfterRedirect(
                    htmlescape(__('A valid token URL is required.', 'webhookbearerapi')),
                    false,
                    ERROR
                );
                return false;
            }
            $input['token_url'] = $url;
        }

        if (isset($input['grant_type']) && !array_key_exists($input['grant_type'], self::getGrantTypes())) {
            $input['grant_type'] = self::GRANT_CLIENT_CREDENTIALS;
        }
        if (isset($input['auth_method']) && !array_key_exists($input['auth_method'], self::getAuthMethods())) {
            $input['auth_method'] = self::AUTH_BODY;
        }
        if (isset($input['body_format']) && !array_key_exists($input['body_format'], self::getBodyFormats())) {
            $input['body_format'] = self::FORMAT_FORM;
        }

        if (isset($input['webhooks_id']) && (int) $input['webhooks_id'] <= 0) {
            // "None": store NULL to allow several unattached providers (NULL is not unique).
            $input['webhooks_id'] = null;
        } elseif (isset($input['webhooks_id'])) {
            $existing = self::getForWebhook((int) $input['webhooks_id']);
            if ($existing !== null && (int) $existing->getID() !== (int) ($input['id'] ?? -1)) {
                Session::addMessageAfterRedirect(
                    htmlescape(sprintf(
                        __('A token provider is already configured for this webhook (%1$s).', 'webhookbearerapi'),
                        $existing->getName()
                    )),
                    false,
                    ERROR
                );
                return false;
            }
        }

        if (isset($input['client_secret'])) {
            if ($input['client_secret'] === '' || $input['client_secret'] === '••••••') {
                unset($input['client_secret']);
            } else {
                $input['client_secret'] = (new \GLPIKey())->encrypt($input['client_secret']);
            }
        }
        if (isset($input['password'])) {
            if ($input['password'] === '' || $input['password'] === '••••••') {
                unset($input['password']);
            } else {
                $input['password'] = (new \GLPIKey())->encrypt($input['password']);
            }
        }

        if (isset($input['extra_params'])) {
            $extra = trim($input['extra_params']);
            if ($extra === '') {
                $input['extra_params'] = null;
            } else {
                $decoded = json_decode($extra, true);
                if (!is_array($decoded)) {
                    Session::addMessageAfterRedirect(
                        htmlescape(__('The additional parameters must be a valid JSON object.', 'webhookbearerapi')),
                        false,
                        ERROR
                    );
                    return false;
                }
                $input['extra_params'] = json_encode($decoded);
            }
        }

        foreach (['default_expires_in', 'refresh_margin'] as $int_field) {
            if (isset($input[$int_field])) {
                $input[$int_field] = max(0, (int) $input[$int_field]);
            }
        }

        return $input;
    }

    /**
     * Show the form of the item, with the state of the cached token.
     *
     * @param int   $ID
     * @param array $options
     *
     * @return bool
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@webhookbearerapi/bearerapi.form.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'grant_types'  => static::getGrantTypes(),
            'auth_methods' => static::getAuthMethods(),
            'body_formats' => static::getBodyFormats(),
        ]);

        return true;
    }

    /**
     * Tab title on the webhook forms.
     *
     * @used-by CommonDBTM::addStandardTab()
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($withtemplate || !$item instanceof Webhook || !static::canView()) {
            return '';
        }

        $nb = countElementsInTable(
            static::getTable(),
            ['webhooks_id' => $item->getID()]
        );

        return static::createTabEntry(static::getTypeName(Session::getPluralNumber()), $nb);
    }

    /**
     * Tab content on the webhook forms: list the token providers attached to the webhook.
     *
     * @used-by CommonDBTM::displayStandardTab()
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Webhook) {
            return false;
        }

        static::showForWebhook($item);

        return true;
    }

    /**
     * Display the token providers attached to the given webhook.
     *
     * @param Webhook $webhook
     *
     * @return void
     */
    public static function showForWebhook(Webhook $webhook)
    {
        $target   = static::getFormURL();
        $provider = self::getForWebhook((int) $webhook->getID());

        echo "<div class='mb-2'>";
        echo "<a href='{$target}?id=-1&webhooks_id={$webhook->getID()}' class='btn btn-primary'>";
        echo "<i class='ti ti-plus' aria-hidden='true'></i><span>" . __('Add') . "</span></a>";
        echo "</div>";

        if ($provider === null) {
            echo "<div class='text-muted'>" . __('No token provider for this webhook.', 'webhookbearerapi') . "</div>";
            return;
        }

        $expiration = $provider->fields['token_expires_at'];
        if (empty($expiration)) {
            $state    = 'text-danger';
            $statetxt = __('No token', 'webhookbearerapi');
        } else {
            $valid    = strtotime($expiration) > (time() + (int) $provider->fields['refresh_margin']);
            $state    = $valid ? 'text-success' : 'text-warning';
            $statetxt = sprintf(
                __('%1$s: %2$s'),
                $valid ? __('Valid', 'webhookbearerapi') : __('Expired', 'webhookbearerapi'),
                htmlescape(date('Y-m-d H:i:s', strtotime($expiration)))
            );
        }

        echo "<table class='table table-striped align-middle'><tbody>";
        echo "<tr><th>" . __('Name') . "</th><td><a href='{$target}?id={$provider->getID()}'>"
            . htmlescape($provider->getName()) . "</a></td></tr>";
        echo "<tr><th>" . __('Active') . "</th><td>" . ($provider->fields['is_active'] ? __('Yes') : __('No')) . "</td></tr>";
        echo "<tr><th>" . __('Bearer token', 'webhookbearerapi') . "</th><td class='{$state}'>{$statetxt}</td></tr>";
        if (!empty($provider->fields['last_refresh'])) {
            echo "<tr><th>" . __('Last token refresh', 'webhookbearerapi') . "</th><td>"
                . htmlescape($provider->fields['last_refresh']) . "</td></tr>";
        }
        if (!empty($provider->fields['last_error'])) {
            echo "<tr><th>" . __('Last error', 'webhookbearerapi') . "</th><td class='text-danger'>"
                . htmlescape($provider->fields['last_error']) . "</td></tr>";
        }
        echo "</tbody></table>";
    }

    /**
     * Load the token provider attached to the given webhook, if any.
     *
     * @param int $webhooks_id
     *
     * @return BearerApi|null
     */
    public static function getForWebhook(int $webhooks_id): ?BearerApi
    {
        global $DB;

        if ($webhooks_id <= 0) {
            return null;
        }

        $row = $DB->request([
            'SELECT' => 'id',
            'FROM'   => static::getTable(),
            'WHERE'  => [
                'webhooks_id' => $webhooks_id,
                'is_deleted'  => 0,
            ],
            'ORDER'  => 'id ASC',
            'LIMIT'  => 1,
        ])->current();

        if ($row === null) {
            return null;
        }

        $provider = new self();
        if ($provider->getFromDB((int) $row['id'])) {
            return $provider;
        }

        return null;
    }

    /**
     * Table storing the authentication source choice of each webhook.
     *
     * @return string
     */
    public static function getConfigTable(): string
    {
        return 'glpi_plugin_webhookbearerapi_webhookconfigs';
    }

    /**
     * Load the explicitly configured authentication source of a webhook.
     *
     * @param int $webhooks_id
     *
     * @return string|null One of the SOURCE_* constants, or null when not explicitly set.
     */
    public static function getAuthSource(int $webhooks_id): ?string
    {
        global $DB;

        if ($webhooks_id <= 0 || !$DB->tableExists(static::getConfigTable())) {
            return null;
        }

        $row = $DB->request([
            'SELECT' => 'auth_source',
            'FROM'   => static::getConfigTable(),
            'WHERE'  => ['webhooks_id' => $webhooks_id],
        ])->current();

        return $row === null ? null : (string) $row['auth_source'];
    }

    /**
     * Compute the effective authentication source of a webhook: the explicitly configured
     * choice when there is one, otherwise the legacy automatic behavior (this plugin when
     * a provider is attached to the webhook, GLPI core otherwise).
     *
     * @param int $webhooks_id
     *
     * @return string One of the SOURCE_* constants.
     */
    public static function getEffectiveAuthSource(int $webhooks_id): string
    {
        $source = static::getAuthSource($webhooks_id);
        if ($source !== null) {
            return $source;
        }

        return static::getForWebhook($webhooks_id) !== null
            ? self::SOURCE_PLUGIN
            : self::SOURCE_NATIVE;
    }

    /**
     * Store the authentication source choice of a webhook.
     *
     * @param int    $webhooks_id
     * @param string $source One of the SOURCE_* constants.
     *
     * @return void
     */
    public static function setAuthSource(int $webhooks_id, string $source): void
    {
        global $DB;

        if ($webhooks_id <= 0 || !in_array($source, [self::SOURCE_NATIVE, self::SOURCE_PLUGIN], true)) {
            return;
        }

        $existing = static::getAuthSource($webhooks_id);

        if ($existing === null) {
            $DB->insert(static::getConfigTable(), [
                'webhooks_id' => $webhooks_id,
                'auth_source' => $source,
            ]);
        } else if ($existing !== $source) {
            $DB->update(static::getConfigTable(), [
                'auth_source' => $source,
            ], [
                'webhooks_id' => $webhooks_id,
            ]);
        }
    }

    /**
     * Remove the authentication source choice of a webhook.
     *
     * @param int $webhooks_id
     *
     * @return void
     */
    public static function deleteAuthSource(int $webhooks_id): void
    {
        global $DB;

        if ($webhooks_id <= 0 || !$DB->tableExists(static::getConfigTable())) {
            return;
        }

        $DB->delete(static::getConfigTable(), ['webhooks_id' => $webhooks_id]);
    }

    /**
     * Cron: refresh the due tokens of the active APIs.
     *
     * @used-by CronTask::launch()
     *
     * @param CronTask $task
     *
     * @return int
     */
    public static function cronRefreshTokens(CronTask $task): int
    {
        $task->setVolume(0);

        $result = TokenManager::refreshDueTokens();

        if ($result['total'] === 0) {
            return 0;
        }

        $task->setVolume($result['total']);
        $task->log(
            sprintf(
                __('%1$d/%2$d bearer tokens refreshed', 'webhookbearerapi'),
                $result['ok'],
                $result['total']
            )
        );
        if ($result['ko'] > 0) {
            $task->log(
                sprintf(
                    __('%1$d tokens could not be refreshed (see the "%2$s" log file)', 'webhookbearerapi'),
                    $result['ko'],
                    'webhookbearerapi.log'
                )
            );
        }

        return 1;
    }

    /**
     * Description of the cron tasks of this itemtype.
     *
     * @used-by CronTask
     *
     * @param string $name
     *
     * @return array
     */
    public static function cronInfo($name)
    {
        return match (strtolower((string) $name)) {
            'refreshtokens' => [
                'description' => __('Refresh the bearer tokens of the APIs used by the webhooks', 'webhookbearerapi'),
            ],
            default => [],
        };
    }
}
