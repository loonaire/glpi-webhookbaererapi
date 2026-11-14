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
 * @link      https://github.com/loonaire/glpi-webhookbaererapi
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Webhookbearerapi;

use CommonDBTM;
use GLPIKey;
use Glpi\ContentTemplates\TemplateManager;
use Glpi\Toolbox\HttpClient;
use QueuedWebhook;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Toolbox;
use Webhook;

/**
 * Retrieves, caches and injects the bearer tokens delivered by the configured APIs.
 */
class TokenManager
{
    /**
     * Options of the HTTP client sending the token requests.
     *
     * A token may be requested while a user saves an item (the webhooks are queued during the
     * request): never wait for a slow or unreachable token server for long.
     */
    private const HTTP_OPTIONS = [
        'timeout'      => 5,  // Idle timeout, in seconds.
        'max_duration' => 10, // Maximum duration of the whole request, in seconds.
    ];

    /**
     * Delay, in seconds, during which no new token is requested after a failed request, when a
     * webhook is queued. Avoids slowing down every item update while the token server is down.
     */
    public const FAILURE_BACKOFF = 60;

    /**
     * Load the cached token of the given API, refreshing it first if it is missing or
     * about to expire.
     *
     * When the refresh fails (or is postponed after a recent failure), the cached token is still
     * returned as long as it has not actually expired.
     *
     * @param BearerApi $api
     * @param bool      $respect_backoff Do not request a new token when the last request failed
     *                                   less than FAILURE_BACKOFF seconds ago
     *
     * @return string|null The bearer token, or null when no usable token is available
     */
    public static function getValidToken(BearerApi $api, bool $respect_backoff = false): ?string
    {
        if (!self::needsRefresh($api)) {
            return self::decryptToken($api);
        }

        if (!$respect_backoff || !self::isInBackoff($api)) {
            $token = self::refreshToken($api);
            if ($token !== null) {
                return $token;
            }
        }

        return self::isExpired($api) ? null : self::decryptToken($api);
    }

    /**
     * Tell whether the cached token is missing or will expire within the refresh margin.
     *
     * @param BearerApi $api
     * @param int       $lookahead Additional delay, in seconds: the token is also considered due
     *                             if it expires within the margin after this delay (used by the
     *                             cron task to renew the tokens expiring before its next run)
     *
     * @return bool
     */
    public static function needsRefresh(BearerApi $api, int $lookahead = 0): bool
    {
        $expires_at = self::toTimestamp($api->fields['token_expires_at'] ?? null);
        if (empty($api->fields['access_token']) || $expires_at === null) {
            return true;
        }

        return $expires_at - time() <= self::getEffectiveMargin($api) + max(0, $lookahead);
    }

    /**
     * Tell whether the cached token is missing or has actually expired.
     *
     * @param BearerApi $api
     *
     * @return bool
     */
    public static function isExpired(BearerApi $api): bool
    {
        $expires_at = self::toTimestamp($api->fields['token_expires_at'] ?? null);

        return empty($api->fields['access_token']) || $expires_at === null || $expires_at <= time();
    }

    /**
     * Refresh margin actually applied: the configured margin, limited to half the lifetime of the
     * cached token. Otherwise, a token living less than the margin (e.g. 5 minutes with the
     * default margin) would be renewed each time it is used.
     *
     * @param BearerApi $api
     *
     * @return int The margin, in seconds
     */
    public static function getEffectiveMargin(BearerApi $api): int
    {
        $margin     = max(0, (int) ($api->fields['refresh_margin'] ?? 0));
        $issued_at  = self::toTimestamp($api->fields['last_refresh'] ?? null);
        $expires_at = self::toTimestamp($api->fields['token_expires_at'] ?? null);

        if ($issued_at !== null && $expires_at !== null && $expires_at > $issued_at) {
            $margin = min($margin, intdiv($expires_at - $issued_at, 2));
        }

        return $margin;
    }

    /**
     * Decrypt the cached token.
     *
     * @param BearerApi $api
     *
     * @return string|null
     */
    public static function decryptToken(BearerApi $api): ?string
    {
        if (empty($api->fields['access_token'])) {
            return null;
        }

        $token = (new GLPIKey())->decrypt((string) $api->fields['access_token']);

        return $token !== '' ? $token : null;
    }

    /**
     * Drop the cached token of the given API (e.g. when the settings of the token request change).
     *
     * @param BearerApi $api
     *
     * @return void
     */
    public static function clearToken(BearerApi $api): void
    {
        global $DB;

        $DB->update(
            BearerApi::getTable(),
            ['access_token' => null, 'token_expires_at' => null],
            ['id' => $api->getID()]
        );

        $api->fields['access_token']     = null;
        $api->fields['token_expires_at'] = null;
    }

    /**
     * Describe the last queue entry of a webhook, to help diagnosing the requests sent to its
     * target. The token itself is never returned.
     *
     * @param Webhook        $webhook
     * @param BearerApi|null $api     Provider used by the webhook
     *
     * @return array{
     *     id: int,
     *     date: string|null,
     *     url: string,
     *     status_code: int|null,
     *     sent_try: int,
     *     token: string,
     *     content_type: string,
     *     payload: string,
     *     json_error: string|null
     * }|null Null when the webhook has no queue entry. `token`: "present", "missing" or "removed"
     *        (token removed from the entries that will not be sent again). `payload`: "empty",
     *        "json", "invalid_json" or "other".
     */
    public static function getLastQueuedRequest(Webhook $webhook, ?BearerApi $api): ?array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id', 'url', 'headers', 'body', 'last_status_code', 'sent_try', 'create_time', 'is_deleted'],
            'FROM'   => QueuedWebhook::getTable(),
            'WHERE'  => ['webhooks_id' => $webhook->getID()],
            'ORDER'  => 'id DESC',
            'LIMIT'  => 1,
        ])->current();
        if ($row === null) {
            return null;
        }

        $headers     = self::decodeHeaders($row['headers']);
        $status_code = $row['last_status_code'] !== null ? (int) $row['last_status_code'] : null;
        $sent_try    = (int) $row['sent_try'];

        $token_key = self::findHeader($headers, $api !== null ? self::getHeaderName($api) : 'Authorization');
        $may_be_sent = !$row['is_deleted']
            && ($status_code === null || $status_code >= 400)
            && $sent_try <= (int) $webhook->fields['sent_try'];
        if ($token_key !== null && !empty($headers[$token_key])) {
            $token = 'present';
        } else {
            $token = $may_be_sent ? 'missing' : 'removed';
        }

        $content_type_key = self::findHeader($headers, 'Content-Type');

        $body       = trim((string) $row['body']);
        $json_error = null;
        if ($body === '') {
            $payload = 'empty';
        } else {
            json_decode($body);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = 'json';
            } elseif (in_array($body[0], ['{', '['], true)) {
                $payload    = 'invalid_json';
                $json_error = json_last_error_msg();
            } else {
                $payload = 'other';
            }
        }

        return [
            'id'           => (int) $row['id'],
            'date'         => $row['create_time'],
            'url'          => (string) $row['url'],
            'status_code'  => $status_code,
            'sent_try'     => $sent_try,
            'token'        => $token,
            'content_type' => $content_type_key !== null ? (string) $headers[$content_type_key] : '',
            'payload'      => $payload,
            'json_error'   => $json_error,
        ];
    }

    /**
     * Request a new token from the token URL of the given API and cache it.
     *
     * @param BearerApi $api
     *
     * @return string|null The new bearer token, or null on failure (reason stored in `last_error`)
     */
    public static function refreshToken(BearerApi $api): ?string
    {
        global $DB;

        $url = trim((string) $api->fields['token_url']);
        if ($url === '') {
            self::saveError($api, __('The token URL is not configured.', 'webhookbearerapi'));
            return null;
        }

        [$options, $error] = self::buildRequestOptions($api);
        if ($error !== null) {
            self::saveError($api, $error);
            return null;
        }

        $content = null;
        try {
            $client   = new HttpClient(BearerApi::class, self::HTTP_OPTIONS);
            $response = $client->post($url, $options);
            $content  = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $detail = '';
            if ($e instanceof HttpExceptionInterface) {
                // Keep only the first line of the response body, which often carries the
                // error description returned by the OAuth server.
                $body   = $e->getResponse()->getContent(false);
                $detail = preg_split('/\R/', trim($body))[0] ?? '';
                $detail = $detail !== '' ? sprintf(' (%s)', $detail) : '';
            }
            self::saveError($api, $e->getMessage() . $detail);
            return null;
        } catch (\Throwable $e) {
            self::saveError($api, $e->getMessage());
            return null;
        }

        $data = json_decode((string) $content, true);
        if (!is_array($data)) {
            self::saveError($api, __('The token response is not a valid JSON object.', 'webhookbearerapi'));
            return null;
        }

        $token_field = trim((string) $api->fields['token_field']) ?: 'access_token';
        $token       = self::extract($data, $token_field);
        if (!is_string($token) || $token === '') {
            self::saveError(
                $api,
                sprintf(__('No bearer token found in the response (looked for "%s").', 'webhookbearerapi'), $token_field)
            );
            return null;
        }

        // Keep the lifetime announced by the server, even a short one: caching a token longer
        // than its lifetime would send expired tokens.
        $expires_in_field = trim((string) $api->fields['expires_in_field']) ?: 'expires_in';
        $expires_in       = self::extract($data, $expires_in_field);
        $expires_in       = is_numeric($expires_in) && (int) $expires_in > 0
            ? (int) $expires_in
            : max(1, (int) $api->fields['default_expires_in']);

        $now          = time();
        $refreshed_at = date('Y-m-d H:i:s', $now);
        $expires_at   = date('Y-m-d H:i:s', $now + $expires_in);
        $encrypted    = (new GLPIKey())->encrypt($token);

        $DB->update(
            BearerApi::getTable(),
            [
                'access_token'     => $encrypted,
                'token_expires_at' => $expires_at,
                'last_refresh'     => $refreshed_at,
                'last_failure'     => null,
                'last_error'       => null,
                'date_mod'         => $refreshed_at,
            ],
            ['id' => $api->getID()]
        );

        $api->fields['access_token']     = $encrypted;
        $api->fields['token_expires_at'] = $expires_at;
        $api->fields['last_refresh']     = $refreshed_at;
        $api->fields['last_failure']     = null;
        $api->fields['last_error']       = null;
        $api->fields['date_mod']         = $refreshed_at;

        return $token;
    }

    /**
     * Refresh the tokens that need to be refreshed for all the active APIs.
     *
     * @param int $lookahead See needsRefresh()
     *
     * @return array{total: int, ok: int, ko: int}
     */
    public static function refreshDueTokens(int $lookahead = 0): array
    {
        global $DB;

        $result = ['total' => 0, 'ok' => 0, 'ko' => 0];

        $iterator = $DB->request([
            'SELECT' => 'id',
            'FROM'   => BearerApi::getTable(),
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => 'id ASC',
        ]);

        foreach ($iterator as $row) {
            $api = new BearerApi();
            if (!$api->getFromDB((int) $row['id'])) {
                continue;
            }

            if (!self::needsRefresh($api, $lookahead)) {
                continue;
            }

            $result['total']++;
            if (self::refreshToken($api) !== null) {
                $result['ok']++;
            } else {
                $result['ko']++;
            }
        }

        return $result;
    }

    /**
     * Prepare a queued webhook about to be created, when the target webhook uses an active
     * token provider of this plugin: inject the bearer token header, and declare the JSON payload.
     *
     * Called by the `pre_item_add` plugin hook, the `headers` entry of the input can be
     * modified before the queue entry is inserted in database. The target URL is the one of the
     * webhook configuration, set by GLPI.
     *
     * @param CommonDBTM $item The QueuedWebhook being added
     *
     * @return void
     */
    public static function prepareQueuedWebhook(CommonDBTM $item): void
    {
        if (!$item instanceof QueuedWebhook || !isset($item->input['webhooks_id'])) {
            return;
        }

        $webhooks_id = (int) $item->input['webhooks_id'];

        // Provider chosen on the webhook form, if it is active and outside the trash. Webhooks
        // using the native authentication of GLPI have no provider.
        $api = BearerApi::getForWebhook($webhooks_id);
        if ($api === null) {
            return;
        }

        $headers = self::decodeHeaders($item->input['headers'] ?? null);

        self::addJsonContentType($headers, (string) ($item->input['body'] ?? ''));

        $header_name = self::getHeaderName($api);
        $header_key  = self::findHeader($headers, $header_name);

        // Never overwrite a header explicitly set on the webhook.
        if ($header_key === null || empty($headers[$header_key])) {
            // This code runs while the user saves the item that raised the webhook: after a recent
            // failure, do not wait for the token server again.
            $token = self::getValidToken($api, true);
            if ($token !== null) {
                $headers[$header_key ?? $header_name] = self::formatHeaderValue($api, $token);
            } else {
                Toolbox::logInFile(
                    'webhookbearerapi',
                    sprintf(
                        'Unable to provide a bearer token for webhook %d (provider %d "%s"): %s' . "\n",
                        $webhooks_id,
                        (int) $api->getID(),
                        $api->getName(),
                        $api->fields['last_error'] ?? 'unknown error'
                    )
                );
            }
        }

        $item->input['headers'] = json_encode($headers);
    }

    /**
     * Keep the queue of the webhooks using the given API in line with their configuration and
     * with the cached token of the API (see syncWebhookQueue()).
     *
     * @param BearerApi $api
     *
     * @return array{updated: int, cleaned: int}
     */
    public static function syncQueuedWebhooks(BearerApi $api): array
    {
        $result = ['updated' => 0, 'cleaned' => 0];

        foreach (BearerApi::getWebhookIds((int) $api->getID()) as $webhooks_id) {
            $webhook = new Webhook();
            if (!$webhook->getFromDB($webhooks_id)) {
                continue;
            }

            $sync = self::syncWebhookQueue($webhook, $api);
            $result['updated'] += $sync['updated'];
            $result['cleaned'] += $sync['cleaned'];
        }

        return $result;
    }

    /**
     * Keep the queue of a webhook in line with its configuration and with the cached token of its
     * provider (used when the webhook configuration changes).
     *
     * @param int $webhooks_id
     *
     * @return array{updated: int, cleaned: int}
     */
    public static function syncWebhook(int $webhooks_id): array
    {
        $api     = BearerApi::getForWebhook($webhooks_id, false);
        $webhook = new Webhook();
        if ($api === null || !$webhook->getFromDB($webhooks_id)) {
            return ['updated' => 0, 'cleaned' => 0];
        }

        return self::syncWebhookQueue($webhook, $api);
    }

    /**
     * Keep the webhook queue in line with the cached tokens of all the APIs.
     *
     * @return array{updated: int, cleaned: int}
     */
    public static function syncAllQueuedWebhooks(): array
    {
        global $DB;

        $result = ['updated' => 0, 'cleaned' => 0];

        $iterator = $DB->request([
            'SELECT' => 'id',
            'FROM'   => BearerApi::getTable(),
            'ORDER'  => 'id ASC',
        ]);

        foreach ($iterator as $row) {
            $api = new BearerApi();
            if (!$api->getFromDB((int) $row['id'])) {
                continue;
            }

            $sync = self::syncQueuedWebhooks($api);
            $result['updated'] += $sync['updated'];
            $result['cleaned'] += $sync['cleaned'];
        }

        return $result;
    }

    /**
     * Remove the token header of the given API from all the queue entries of a webhook, including
     * the ones still to be sent (used when the webhook stops using the API, or when the API is
     * purged).
     *
     * @param BearerApi   $api
     * @param int         $webhooks_id Webhook to clean
     * @param string|null $header_name Header to remove, defaults to the header of the API
     *
     * @return int Number of cleaned queue entries
     */
    public static function removeTokensFromQueue(BearerApi $api, int $webhooks_id, ?string $header_name = null): int
    {
        $header_name = $header_name !== null ? self::normalizeHeaderName($header_name) : self::getHeaderName($api);

        $webhook = new Webhook();
        if ($webhooks_id <= 0 || !$webhook->getFromDB($webhooks_id) || self::webhookDefinesHeader($webhook, $header_name)) {
            return 0;
        }

        return self::removeQueuedHeaders($webhook, $header_name, true);
    }

    /**
     * Remove the token headers of all the APIs from the webhook queue (used on uninstall).
     *
     * @return int Number of cleaned queue entries
     */
    public static function removeAllTokensFromQueue(): int
    {
        global $DB;

        $config_table = BearerApi::getConfigTable();
        $fk           = BearerApi::getForeignKeyField();

        // Pairs [webhook id, provider id]. The plugin may be uninstalled before its update: the
        // tables can still have the structure of version 1.0 (link stored on the providers).
        if ($DB->tableExists($config_table) && $DB->fieldExists($config_table, $fk, false)) {
            $iterator = $DB->request([
                'SELECT' => ['webhooks_id', $fk . ' AS provider_id'],
                'FROM'   => $config_table,
                'WHERE'  => [$fk => ['>', 0]],
            ]);
        } elseif ($DB->fieldExists(BearerApi::getTable(), 'webhooks_id', false)) {
            $iterator = $DB->request([
                'SELECT' => ['webhooks_id', 'id AS provider_id'],
                'FROM'   => BearerApi::getTable(),
                'WHERE'  => ['webhooks_id' => ['>', 0]],
            ]);
        } else {
            return 0;
        }

        $count = 0;
        foreach ($iterator as $row) {
            $api = new BearerApi();
            if ($api->getFromDB((int) $row['provider_id'])) {
                $count += self::removeTokensFromQueue($api, (int) $row['webhooks_id']);
            }
        }

        return $count;
    }

    /**
     * Name of the header carrying the token.
     *
     * @param BearerApi $api
     *
     * @return string
     */
    public static function getHeaderName(BearerApi $api): string
    {
        return self::normalizeHeaderName((string) ($api->fields['header_name'] ?? ''));
    }

    /**
     * Keep the queue of a webhook in line with its configuration and with the cached token of its
     * provider.
     *
     * GLPI copies the target URL and the headers of the webhook into each queue entry when the
     * event occurs, then sends the entry later and retries the failed ones (and the manual
     * "resend" action) with these copies. So:
     * - the entries that may still be sent (pending or to retry) get the current target URL of
     *   the webhook, the current token, and the JSON content type;
     * - the token is removed from the entries that will not be sent again, so that it does not
     *   stay readable in the queue.
     *
     * @param Webhook   $webhook
     * @param BearerApi $api     Provider used by the webhook
     *
     * @return array{updated: int, cleaned: int}
     */
    private static function syncWebhookQueue(Webhook $webhook, BearerApi $api): array
    {
        // An inactive provider (or one in the trash) no longer handles the webhook.
        if ((int) $api->fields['is_active'] !== 1 || $api->isDeleted()) {
            return ['updated' => 0, 'cleaned' => 0];
        }

        $header_name = self::getHeaderName($api);

        // A header with the same name defined on the webhook itself belongs to the webhook: the
        // queued values cannot be told apart from the ones of this plugin, they are left untouched.
        $owns_header  = !self::webhookDefinesHeader($webhook, $header_name);
        $header_value = null;
        if ($owns_header && !self::isExpired($api)) {
            $token = self::decryptToken($api);
            if ($token !== null) {
                $header_value = self::formatHeaderValue($api, $token);
            }
        }

        return [
            'updated' => self::updatePendingEntries($webhook, $header_name, $header_value),
            'cleaned' => $owns_header ? self::removeQueuedHeaders($webhook, $header_name, false) : 0,
        ];
    }

    /**
     * Update the queue entries of the webhook that may still be sent: target URL of the webhook
     * configuration, token header, JSON content type.
     *
     * @param Webhook     $webhook
     * @param string      $header_name
     * @param string|null $header_value Token header value, null to leave the token header untouched
     *
     * @return int Number of updated queue entries
     */
    private static function updatePendingEntries(Webhook $webhook, string $header_name, ?string $header_value): int
    {
        global $DB;

        $count    = 0;
        $iterator = $DB->request([
            'SELECT' => ['id', 'url', 'headers', 'body'],
            'FROM'   => QueuedWebhook::getTable(),
            'WHERE'  => array_merge(
                ['webhooks_id' => $webhook->getID()],
                self::getPendingCriteria($webhook)
            ),
        ]);

        foreach ($iterator as $row) {
            $changes = [];

            $headers         = self::decodeHeaders($row['headers']);
            $headers_changed = self::addJsonContentType($headers, (string) $row['body']);
            if ($header_value !== null) {
                $key = self::findHeader($headers, $header_name) ?? $header_name;
                if (($headers[$key] ?? null) !== $header_value) {
                    $headers[$key]   = $header_value;
                    $headers_changed = true;
                }
            }
            if ($headers_changed) {
                $changes['headers'] = json_encode($headers);
            }

            $url = self::getTargetUrl($webhook, (string) $row['body']);
            if ($url !== null && $url !== (string) $row['url']) {
                $changes['url'] = $url;
            }

            if ($changes !== []) {
                $DB->update(QueuedWebhook::getTable(), $changes, ['id' => $row['id']]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Current target URL of the webhook for a queue entry.
     *
     * The URL of a webhook may be a template using the data of the event (e.g.
     * `https://example.com/api/{{ item.id }}`). These data are only kept in the queue entry when
     * the webhook uses the default payload (the payload is then the data themselves).
     *
     * @param Webhook $webhook
     * @param string  $body    Payload of the queue entry
     *
     * @return string|null The URL, or null when it cannot be computed (template URL with a custom
     *                     payload): the URL of the queue entry is then kept
     */
    private static function getTargetUrl(Webhook $webhook, string $body): ?string
    {
        $url = trim((string) $webhook->fields['url']);
        if ($url === '') {
            return null;
        }

        if (!str_contains($url, '{{') && !str_contains($url, '{%')) {
            return $url;
        }

        $uses_default_payload = (int) ($webhook->fields['use_default_payload'] ?? 0) === 1
            || empty($webhook->fields['payload']);
        $data = $uses_default_payload ? json_decode($body, true) : null;
        if (!is_array($data) || !array_key_exists('item', $data)) {
            return null;
        }

        try {
            $rendered = trim(TemplateManager::render($url, $data, false));
        } catch (\Throwable $e) {
            return null;
        }

        return $rendered !== '' ? $rendered : null;
    }

    /**
     * Declare the JSON payload of a queued webhook.
     *
     * GLPI sends the payload without `Content-Type` header, and the HTTP client then declares it as
     * form data (`application/x-www-form-urlencoded`): JSON APIs, such as the API of GLPI, ignore
     * such a body. A `Content-Type` header defined on the webhook is kept.
     *
     * @param array  $headers Headers of the queue entry, updated in place
     * @param string $body    Payload of the queue entry
     *
     * @return bool Whether the header has been added
     */
    private static function addJsonContentType(array &$headers, string $body): bool
    {
        $key = self::findHeader($headers, 'Content-Type');
        if ($key !== null && !empty($headers[$key])) {
            return false;
        }

        $body = trim($body);
        if ($body === '' || !in_array($body[0], ['{', '['], true)) {
            return false;
        }
        json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        $headers[$key ?? 'Content-Type'] = 'application/json';

        return true;
    }

    /**
     * Tell whether the webhook defines itself a (non empty) header with the given name.
     *
     * @param Webhook $webhook
     * @param string  $header_name
     *
     * @return bool
     */
    private static function webhookDefinesHeader(Webhook $webhook, string $header_name): bool
    {
        $custom_headers = $webhook->fields['custom_headers'] ?? [];
        $custom_headers = is_array($custom_headers) ? $custom_headers : [];
        $custom_key     = self::findHeader($custom_headers, $header_name);

        return $custom_key !== null && !empty($custom_headers[$custom_key]);
    }

    /**
     * Remove the given header from the queue entries of the webhook.
     *
     * @param Webhook $webhook
     * @param string  $header_name
     * @param bool    $all         Also clean the entries that may still be sent
     *
     * @return int Number of cleaned queue entries
     */
    private static function removeQueuedHeaders(Webhook $webhook, string $header_name, bool $all): int
    {
        global $DB;

        $where = [
            'webhooks_id' => $webhook->getID(),
            // Only load the entries that may contain the header (the name is checked again below).
            'headers'     => ['LIKE', '%' . self::escapeLike($header_name) . '%'],
        ];
        if (!$all) {
            // Entries that will not be sent again by the queue: sent successfully, deleted, or out
            // of retries (opposite of the criteria of QueuedWebhook::getPendings()).
            $where[] = [
                'OR' => [
                    ['is_deleted' => 1],
                    ['last_status_code' => ['<', 400]],
                    ['sent_try' => ['>', (int) $webhook->fields['sent_try']]],
                ],
            ];
        }

        $count    = 0;
        $iterator = $DB->request([
            'SELECT' => ['id', 'headers'],
            'FROM'   => QueuedWebhook::getTable(),
            'WHERE'  => $where,
        ]);

        foreach ($iterator as $row) {
            $headers = self::decodeHeaders($row['headers']);
            $key     = self::findHeader($headers, $header_name);
            if ($key === null) {
                continue;
            }

            unset($headers[$key]);
            $DB->update(QueuedWebhook::getTable(), ['headers' => json_encode($headers)], ['id' => $row['id']]);
            $count++;
        }

        return $count;
    }

    /**
     * Criteria of the queue entries of a webhook that may still be sent, mirroring
     * QueuedWebhook::getPendings() (whatever their sending date).
     *
     * @param Webhook $webhook
     *
     * @return array
     */
    private static function getPendingCriteria(Webhook $webhook): array
    {
        return [
            'is_deleted' => 0,
            [
                'OR' => [
                    ['sent_try' => null],
                    ['sent_try' => ['<=', (int) $webhook->fields['sent_try']]],
                ],
            ],
            [
                'OR' => [
                    ['last_status_code' => null],
                    ['last_status_code' => ['>=', 400]],
                ],
            ],
        ];
    }

    /**
     * Build the options of the token request from the API configuration.
     *
     * @param BearerApi $api
     *
     * @return array{0: array, 1: string|null} The request options and the error, if any
     */
    private static function buildRequestOptions(BearerApi $api): array
    {
        $key    = new GLPIKey();
        $params = ['grant_type' => (string) $api->fields['grant_type']];
        $headers = ['Accept' => 'application/json'];

        $client_id     = (string) $api->fields['client_id'];
        $client_secret = $api->fields['client_secret'] !== null && $api->fields['client_secret'] !== ''
            ? $key->decrypt((string) $api->fields['client_secret'])
            : '';

        if ((string) $api->fields['auth_method'] === BearerApi::AUTH_BASIC) {
            $headers['Authorization'] = 'Basic ' . base64_encode($client_id . ':' . $client_secret);
        } else {
            if ($client_id !== '') {
                $params['client_id'] = $client_id;
            }
            if ($client_secret !== '') {
                $params['client_secret'] = $client_secret;
            }
        }

        if ((string) $api->fields['grant_type'] === BearerApi::GRANT_PASSWORD) {
            if (empty($api->fields['username']) || empty($api->fields['password'])) {
                return [
                    [],
                    __('The username and password are required for the "Password" grant type.', 'webhookbearerapi'),
                ];
            }
            $params['username'] = (string) $api->fields['username'];
            $params['password'] = $key->decrypt((string) $api->fields['password']);
        }

        $scope = trim((string) $api->fields['scope']);
        if ($scope !== '') {
            $params['scope'] = $scope;
        }

        if (!empty($api->fields['extra_params'])) {
            $extra = json_decode((string) $api->fields['extra_params'], true);
            if (is_array($extra)) {
                $params = array_merge($params, $extra);
            }
        }

        $options = ['headers' => $headers];
        if ((string) $api->fields['body_format'] === BearerApi::FORMAT_JSON) {
            $options['json'] = $params;
        } else {
            $options['body'] = $params;
        }

        return [$options, null];
    }

    /**
     * Store and log a token retrieval error.
     *
     * @param BearerApi $api
     * @param string    $message
     *
     * @return void
     */
    private static function saveError(BearerApi $api, string $message): void
    {
        global $DB;

        Toolbox::logInFile(
            'webhookbearerapi',
            sprintf('Token retrieval error for provider %d "%s": %s' . "\n", (int) $api->getID(), $api->getName(), $message)
        );

        $message   = Toolbox::substr($message, 0, 1000);
        $failed_at = date('Y-m-d H:i:s');

        $DB->update(
            BearerApi::getTable(),
            [
                'last_error'   => $message,
                'last_failure' => $failed_at,
            ],
            ['id' => $api->getID()]
        );

        $api->fields['last_error']   = $message;
        $api->fields['last_failure'] = $failed_at;
    }

    /**
     * Tell whether the last token request failed less than FAILURE_BACKOFF seconds ago.
     *
     * @param BearerApi $api
     *
     * @return bool
     */
    private static function isInBackoff(BearerApi $api): bool
    {
        $failed_at = self::toTimestamp($api->fields['last_failure'] ?? null);

        return $failed_at !== null && time() - $failed_at < self::FAILURE_BACKOFF;
    }

    /**
     * Value of the header carrying the token: the token, preceded by the configured prefix.
     *
     * @param BearerApi $api
     * @param string    $token
     *
     * @return string
     */
    private static function formatHeaderValue(BearerApi $api, string $token): string
    {
        $prefix = trim((string) $api->fields['token_prefix']);

        return $prefix !== '' ? sprintf('%s %s', $prefix, $token) : $token;
    }

    /**
     * @param string $header_name
     *
     * @return string The trimmed header name, `Authorization` when empty
     */
    private static function normalizeHeaderName(string $header_name): string
    {
        return trim($header_name) ?: 'Authorization';
    }

    /**
     * Find a header in a list of headers. The HTTP header names are case-insensitive.
     *
     * @param array  $headers
     * @param string $header_name
     *
     * @return string|null The key of the header in the list, or null when it is not found
     */
    private static function findHeader(array $headers, string $header_name): ?string
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $header_name) === 0) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @param mixed $json The JSON encoded headers of a queue entry
     *
     * @return array
     */
    private static function decodeHeaders(mixed $json): array
    {
        $headers = json_decode((string) $json, true);

        return is_array($headers) ? $headers : [];
    }

    /**
     * Escape the wildcards of a value used in a LIKE pattern.
     *
     * @param string $value
     *
     * @return string
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param mixed $date A date read from the database
     *
     * @return int|null The Unix timestamp of the date, or null when it is empty or invalid
     */
    private static function toTimestamp(mixed $date): ?int
    {
        if (!is_string($date) || $date === '') {
            return null;
        }

        $timestamp = strtotime($date);

        return $timestamp !== false ? $timestamp : null;
    }

    /**
     * Extract a value from a decoded JSON payload using a dot separated path
     * (e.g. "access_token" or "data.token").
     *
     * @param array  $data
     * @param string $path
     *
     * @return mixed
     */
    private static function extract(array $data, string $path)
    {
        $value = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
