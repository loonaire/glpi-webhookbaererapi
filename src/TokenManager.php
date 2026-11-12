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
use GLPIKey;
use Glpi\Toolbox\HttpClient;
use QueuedWebhook;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Toolbox;

/**
 * Retrieves, caches and injects the bearer tokens delivered by the configured APIs.
 */
class TokenManager
{
    /**
     * Load the cached token of the given API, refreshing it first if it is missing or
     * about to expire (within the configured margin).
     *
     * @param BearerApi $api
     *
     * @return string|null The bearer token, or null on failure
     */
    public static function getValidToken(BearerApi $api): ?string
    {
        if (self::needsRefresh($api)) {
            if (self::refreshToken($api) === null) {
                return null;
            }
            // Reload the freshly stored token.
            $api->getFromDB($api->getID());
        }

        return self::decryptToken($api);
    }

    /**
     * Tell whether the cached token is missing or will expire within the refresh margin.
     *
     * @param BearerApi $api
     *
     * @return bool
     */
    public static function needsRefresh(BearerApi $api): bool
    {
        if (empty($api->fields['access_token']) || empty($api->fields['token_expires_at'])) {
            return true;
        }

        $margin = max(0, (int) $api->fields['refresh_margin']);

        return strtotime((string) $api->fields['token_expires_at']) - time() <= $margin;
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
            $client   = new HttpClient(context: BearerApi::class);
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

        $expires_in_field = trim((string) $api->fields['expires_in_field']) ?: 'expires_in';
        $expires_in       = self::extract($data, $expires_in_field);
        if (is_numeric($expires_in)) {
            $expires_in = (int) $expires_in;
        } else {
            $expires_in = (int) $api->fields['default_expires_in'];
        }
        // Never cache a token for less than one minute.
        $expires_in   = max(60, $expires_in);
        $expires_at   = date('Y-m-d H:i:s', time() + $expires_in);
        $encrypted    = (new GLPIKey())->encrypt($token);

        $DB->update(
            BearerApi::getTable(),
            [
                'access_token'     => $encrypted,
                'token_expires_at' => $expires_at,
                'last_refresh'     => date('Y-m-d H:i:s'),
                'date_mod'         => date('Y-m-d H:i:s'),
                'last_error'       => null,
            ],
            ['id' => $api->getID()]
        );

        $api->fields['access_token']     = $encrypted;
        $api->fields['token_expires_at'] = $expires_at;
        $api->fields['last_refresh']     = date('Y-m-d H:i:s');
        $api->fields['last_error']       = null;

        return $token;
    }

    /**
     * Refresh the tokens that need to be refreshed for all the active APIs.
     *
     * @return array{total: int, ok: int, ko: int}
     */
    public static function refreshDueTokens(): array
    {
        global $DB;

        $result = ['total' => 0, 'ok' => 0, 'ko' => 0];

        $iterator = $DB->request([
            'FROM' => BearerApi::getTable(),
            'WHERE' => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER' => 'id ASC',
        ]);

        foreach ($iterator as $row) {
            $api = new BearerApi();
            if (!$api->getFromDB((int) $row['id'])) {
                continue;
            }

            if (!self::needsRefresh($api)) {
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
     * Prepare a queued webhook about to be created: inject the bearer token header when
     * the target webhook is attached to an active API of this plugin.
     *
     * Called by the `pre_item_add` plugin hook, the `headers` entry of the input can be
     * modified before the queue entry is inserted in database.
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

        // Webhooks explicitly configured to use the native OAuth 2.0 authentication of
        // GLPI must keep the token provided by the core, even when a provider exists in
        // this plugin.
        if (BearerApi::getAuthSource((int) $item->input['webhooks_id']) === BearerApi::SOURCE_NATIVE) {
            return;
        }

        $api = BearerApi::getForWebhook((int) $item->input['webhooks_id']);
        if ($api === null || (int) $api->fields['is_active'] !== 1) {
            return;
        }

        $headers = json_decode((string) ($item->input['headers'] ?? ''), true);
        if (!is_array($headers)) {
            $headers = [];
        }

        $header_name = trim((string) $api->fields['header_name']) ?: 'Authorization';

        // Never overwrite an header explicitly set on the webhook.
        if (!empty($headers[$header_name])) {
            return;
        }

        $token = self::getValidToken($api);
        if ($token === null) {
            Toolbox::logInFile(
                'webhookbearerapi',
                sprintf(
                    'Unable to provide a bearer token for webhook %d (provider %d "%s"): %s' . "\n",
                    (int) $item->input['webhooks_id'],
                    (int) $api->getID(),
                    $api->getName(),
                    $api->fields['last_error'] ?? 'unknown error'
                )
            );
            return;
        }

        $prefix = trim((string) $api->fields['token_prefix']);

        $headers[$header_name] = $prefix !== ''
            ? sprintf('%s %s', $prefix, $token)
            : $token;

        $item->input['headers'] = json_encode($headers);
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

        $message = Toolbox::substr($message, 0, 1000);

        $DB->update(
            BearerApi::getTable(),
            ['last_error' => $message],
            ['id' => $api->getID()]
        );

        $api->fields['last_error'] = $message;
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
