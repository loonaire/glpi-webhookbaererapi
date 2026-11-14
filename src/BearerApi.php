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
use QueuedWebhook;
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

    // Value of the "API authentication source" field of the webhook forms for the native
    // authentication of GLPI (OAuth 2.0 parameters of the "Security" tab); the other values are
    // the ids of the token providers.
    public const SOURCE_NATIVE = 'native';

    // Settings sent with the token request: changing one of them renews the cached token.
    private const TOKEN_REQUEST_FIELDS = [
        'token_url', 'grant_type', 'auth_method', 'body_format', 'client_id', 'client_secret',
        'username', 'password', 'scope', 'extra_params',
    ];

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

        // Id 4 was the "Webhook" option of version 1.0 (the webhooks now choose their provider):
        // do not reuse it.

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
            $value = (string) ($values['grant_type'] ?? '');
            return htmlescape($types[$value] ?? $value);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong);
        // Webhooks using this provider.
        $this->addStandardTab(self::class, $ong, $options);
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

        // Minimum values of the durations, in seconds (same limits as the form).
        foreach (['default_expires_in' => 60, 'refresh_margin' => 0] as $int_field => $min) {
            if (isset($input[$int_field])) {
                $input[$int_field] = max($min, (int) $input[$int_field]);
            }
        }

        return $input;
    }

    public function post_updateItem($history = true)
    {
        // Renaming the header: the queue entries must not keep the token under the previous name,
        // and the entries still to be sent get it under the new name.
        if (in_array('header_name', $this->updates, true)) {
            $old_header_name = (string) ($this->oldvalues['header_name'] ?? '');
            foreach (static::getWebhookIds((int) $this->getID()) as $webhooks_id) {
                TokenManager::removeTokensFromQueue($this, $webhooks_id, $old_header_name);
            }
            TokenManager::syncQueuedWebhooks($this);
        }

        // The cached token was obtained with the previous settings (e.g. without the right scope):
        // it would otherwise be used until its expiration.
        if (array_intersect(self::TOKEN_REQUEST_FIELDS, $this->updates) !== []) {
            $this->renewToken();
        }

        $this->warnAboutGlpiApi();
    }

    public function post_addItem()
    {
        $this->warnAboutGlpiApi();
    }

    /**
     * Drop the cached token and request a new one with the current settings, then give it to the
     * queued webhooks still to be sent.
     *
     * @return void
     */
    private function renewToken(): void
    {
        TokenManager::clearToken($this);

        if ((int) $this->fields['is_active'] !== 1 || $this->isDeleted()) {
            return;
        }

        if (TokenManager::refreshToken($this) !== null) {
            TokenManager::syncQueuedWebhooks($this);
            Session::addMessageAfterRedirect(
                __s('The token settings have changed: a new token has been obtained.', 'webhookbearerapi')
            );
        } else {
            Session::addMessageAfterRedirect(
                htmlescape(sprintf(
                    __('The token settings have changed, but no new token could be obtained: %s', 'webhookbearerapi'),
                    $this->fields['last_error'] ?? ''
                )),
                false,
                ERROR
            );
        }
    }

    /**
     * Warn about the settings required by the API of GLPI, when the token URL is the token endpoint
     * of a GLPI (`.../api.php/token`).
     *
     * @return void
     */
    private function warnAboutGlpiApi(): void
    {
        $path = (string) (parse_url((string) $this->fields['token_url'], PHP_URL_PATH) ?? '');
        if (!preg_match('#/api\.php/token/?$#i', $path)) {
            return;
        }

        if ((string) $this->fields['grant_type'] === self::GRANT_CLIENT_CREDENTIALS) {
            Session::addMessageAfterRedirect(
                __s('This token URL is the one of a GLPI: GLPI only links its tokens to a user with the "Password" grant type. With "Client credentials", its API answers "You are not authenticated".', 'webhookbearerapi'),
                false,
                WARNING
            );
        }

        $scopes = preg_split('/\s+/', trim((string) $this->fields['scope'])) ?: [];
        if (!in_array('api', $scopes, true)) {
            Session::addMessageAfterRedirect(
                __s('This token URL is the one of a GLPI: the "api" scope is required to use its API (otherwise it answers "You do not have the required scope(s)"). The OAuth client of the target GLPI must also allow this scope.', 'webhookbearerapi'),
                false,
                WARNING
            );
        }
    }

    public function cleanDBonPurge()
    {
        $webhook_names = [];

        foreach (static::getWebhookIds((int) $this->getID()) as $webhooks_id) {
            // Do not leave the token of a purged provider readable in the webhook queue.
            TokenManager::removeTokensFromQueue($this, $webhooks_id);
            static::setWebhookProvider($webhooks_id, 0);

            $webhook = new Webhook();
            if ($webhook->getFromDB($webhooks_id)) {
                $webhook_names[] = $webhook->getName();
            }
        }

        if ($webhook_names !== []) {
            Session::addMessageAfterRedirect(
                htmlescape(sprintf(
                    __('These webhooks no longer use a token provider and are back to the native authentication of GLPI: %s', 'webhookbearerapi'),
                    implode(', ', $webhook_names)
                )),
                false,
                WARNING
            );
        }
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

        if (!$this->isNewItem()) {
            // Rendered after the "Save" button, which therefore remains the default button of the
            // form (pressing Enter in a field saves the changes).
            $options['addbuttons'] = [
                'refresh' => [
                    'text' => __('Refresh token now', 'webhookbearerapi'),
                    'icon' => 'ti ti-refresh',
                ],
            ];
        }

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
     * Tab titles: token provider used by a webhook (webhook forms), webhooks using a provider
     * (provider forms).
     *
     * @used-by CommonDBTM::addStandardTab()
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($withtemplate || !static::canView()) {
            return '';
        }

        if ($item instanceof Webhook) {
            $nb = static::getWebhookProviderId((int) $item->getID()) > 0 ? 1 : 0;
            return static::createTabEntry(static::getTypeName(1), $nb);
        }

        if ($item instanceof self && !$item->isNewItem()) {
            return static::createTabEntry(
                Webhook::getTypeName(Session::getPluralNumber()),
                count(static::getWebhookIds((int) $item->getID())),
                Webhook::class
            );
        }

        return '';
    }

    /**
     * Tab contents.
     *
     * @used-by CommonDBTM::displayStandardTab()
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Webhook) {
            static::showForWebhook($item);
            return true;
        }

        if ($item instanceof self) {
            static::showWebhooks($item);
            return true;
        }

        return false;
    }

    /**
     * Display the token provider used by the given webhook.
     *
     * @param Webhook $webhook
     *
     * @return void
     */
    public static function showForWebhook(Webhook $webhook)
    {
        $provider = self::getForWebhook((int) $webhook->getID(), false);

        $token_state = 'none';
        if ($provider !== null && !empty($provider->fields['access_token']) && !empty($provider->fields['token_expires_at'])) {
            $token_state = TokenManager::isExpired($provider) ? 'expired' : 'valid';
        }

        TemplateRenderer::getInstance()->display('@webhookbearerapi/webhook_tab.html.twig', [
            'provider'    => $provider,
            'webhook'     => $webhook,
            'token_state' => $token_state,
            'can_create'  => static::canCreate(),
            'add_url'     => static::getFormURL(),
            'last_request' => TokenManager::getLastQueuedRequest($webhook, $provider),
            'queue_url'    => QueuedWebhook::getFormURL(),
            // GLPI sends the challenge-response check without the token of the plugin.
            'cra_enabled' => GLPI_WEBHOOK_CRA_MANDATORY || (bool) ($webhook->fields['use_cra_challenge'] ?? false),
        ]);
    }

    /**
     * Display the webhooks using the given token provider.
     *
     * @param BearerApi $provider
     *
     * @return void
     */
    public static function showWebhooks(BearerApi $provider): void
    {
        $webhooks = [];
        foreach (static::getWebhookIds((int) $provider->getID()) as $webhooks_id) {
            $webhook = new Webhook();
            if ($webhook->getFromDB($webhooks_id)) {
                $webhooks[] = $webhook;
            }
        }

        TemplateRenderer::getInstance()->display('@webhookbearerapi/bearerapi_webhooks.html.twig', [
            'provider' => $provider,
            'webhooks' => $webhooks,
        ]);
    }

    /**
     * Load the token provider used by the given webhook, if any.
     *
     * @param int  $webhooks_id
     * @param bool $usable_only Only return an active provider that is not in the trash
     *
     * @return BearerApi|null
     */
    public static function getForWebhook(int $webhooks_id, bool $usable_only = true): ?BearerApi
    {
        $provider_id = static::getWebhookProviderId($webhooks_id);
        if ($provider_id <= 0) {
            return null;
        }

        $provider = new self();
        if (!$provider->getFromDB($provider_id)) {
            return null;
        }

        if ($usable_only && ($provider->isDeleted() || (int) $provider->fields['is_active'] !== 1)) {
            return null;
        }

        return $provider;
    }

    /**
     * Table storing the token provider used by each webhook (a webhook without row uses the
     * native authentication of GLPI).
     *
     * @return string
     */
    public static function getConfigTable(): string
    {
        return 'glpi_plugin_webhookbearerapi_webhookconfigs';
    }

    /**
     * Id of the token provider used by a webhook.
     *
     * @param int $webhooks_id
     *
     * @return int The provider id, 0 when the webhook uses the native authentication of GLPI
     */
    public static function getWebhookProviderId(int $webhooks_id): int
    {
        global $DB;

        if ($webhooks_id <= 0 || !$DB->tableExists(static::getConfigTable())) {
            return 0;
        }

        $fk  = static::getForeignKeyField();
        $row = $DB->request([
            'SELECT' => $fk,
            'FROM'   => static::getConfigTable(),
            'WHERE'  => ['webhooks_id' => $webhooks_id],
        ])->current();

        return $row === null ? 0 : (int) $row[$fk];
    }

    /**
     * Store the token provider used by a webhook.
     *
     * @param int $webhooks_id
     * @param int $bearerapis_id The provider id, 0 for the native authentication of GLPI
     *
     * @return void
     */
    public static function setWebhookProvider(int $webhooks_id, int $bearerapis_id): void
    {
        global $DB;

        if ($webhooks_id <= 0) {
            return;
        }

        if ($bearerapis_id > 0) {
            $DB->updateOrInsert(
                static::getConfigTable(),
                [static::getForeignKeyField() => $bearerapis_id],
                ['webhooks_id' => $webhooks_id]
            );
        } else {
            $DB->delete(static::getConfigTable(), ['webhooks_id' => $webhooks_id]);
        }
    }

    /**
     * Ids of the webhooks using a token provider.
     *
     * @param int $bearerapis_id
     *
     * @return int[]
     */
    public static function getWebhookIds(int $bearerapis_id): array
    {
        global $DB;

        if ($bearerapis_id <= 0) {
            return [];
        }

        $ids      = [];
        $iterator = $DB->request([
            'SELECT' => 'webhooks_id',
            'FROM'   => static::getConfigTable(),
            'WHERE'  => [static::getForeignKeyField() => $bearerapis_id],
            'ORDER'  => 'webhooks_id ASC',
        ]);
        foreach ($iterator as $row) {
            $ids[] = (int) $row['webhooks_id'];
        }

        return $ids;
    }

    /**
     * Label of the token providers group in the "API authentication source" field.
     *
     * @return string
     */
    public static function getProvidersGroupLabel(): string
    {
        return __('Token providers (Bearer API plugin)', 'webhookbearerapi');
    }

    /**
     * Choices of the "API authentication source" field of the webhook forms: the native
     * authentication of GLPI, then the token providers (outside the trash, plus the provider
     * currently used by the webhook so that the field shows the actual value).
     *
     * @param int $current_id Id of the provider currently used by the webhook
     *
     * @return array<string, string|array<int, string>>
     */
    public static function getAuthChoices(int $current_id = 0): array
    {
        global $DB;

        $providers = [];
        $iterator  = $DB->request([
            'SELECT' => ['id', 'name', 'is_active', 'is_deleted'],
            'FROM'   => static::getTable(),
            'WHERE'  => [
                'OR' => [
                    'is_deleted' => 0,
                    'id'         => $current_id,
                ],
            ],
            'ORDER'  => ['name ASC', 'id ASC'],
        ]);
        foreach ($iterator as $row) {
            $name = trim((string) $row['name']);
            if ($name === '') {
                $name = sprintf('%s (%d)', static::getTypeName(1), $row['id']);
            }
            if ($row['is_deleted']) {
                $name = sprintf('%s (%s)', $name, __('Deleted'));
            } elseif (!$row['is_active']) {
                $name = sprintf('%s (%s)', $name, __('Inactive'));
            }
            $providers[(int) $row['id']] = $name;
        }

        $choices = [self::SOURCE_NATIVE => __('Native GLPI (OAuth 2.0)', 'webhookbearerapi')];
        if ($providers !== []) {
            $choices[static::getProvidersGroupLabel()] = $providers;
        }

        return $choices;
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

        // Also renew the tokens that would expire before the next run of the task, so that the
        // queued webhooks always carry a token that is still valid when they are sent.
        $result = TokenManager::refreshDueTokens((int) ($task->fields['frequency'] ?? 0));
        $queue  = TokenManager::syncAllQueuedWebhooks();

        $volume = $result['total'] + $queue['updated'] + $queue['cleaned'];
        if ($volume === 0) {
            return 0;
        }

        $task->setVolume($volume);
        if ($queue['updated'] > 0 || $queue['cleaned'] > 0) {
            $task->log(
                sprintf(
                    __('Webhook queue: %1$d entries to send updated (target URL, token), token removed from %2$d sent entries', 'webhookbearerapi'),
                    $queue['updated'],
                    $queue['cleaned']
                )
            );
        }
        if ($result['total'] > 0) {
            $task->log(
                sprintf(
                    __('%1$d/%2$d bearer tokens refreshed', 'webhookbearerapi'),
                    $result['ok'],
                    $result['total']
                )
            );
        }
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
