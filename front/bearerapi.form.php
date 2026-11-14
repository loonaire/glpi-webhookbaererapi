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

// Since GLPI 11, the legacy front files are loaded by GLPI itself, with the session and the
// configuration already initialized: `inc/includes.php` must no longer be included.

Session::checkRight('config', READ);

$api = new BearerApi();

if (isset($_POST['refresh'])) {
    // Ask for a new token right now, using the settings already saved.
    $id = (int) ($_POST['id'] ?? -1);
    if ($api->canEdit($id) && $api->getFromDB($id)) {
        if (TokenManager::refreshToken($api) !== null) {
            // Give the new token to the queued webhooks still to be sent.
            TokenManager::syncQueuedWebhooks($api);
            Session::addMessageAfterRedirect(__s('The bearer token has been refreshed.', 'webhookbearerapi'));
        } else {
            Session::addMessageAfterRedirect(
                htmlescape(sprintf(
                    __('Unable to refresh the bearer token: %s', 'webhookbearerapi'),
                    $api->fields['last_error'] ?? ''
                )),
                false,
                ERROR
            );
        }
    }
    Html::redirect(BearerApi::getFormURLWithID($id));
}

if (isset($_POST['add'])) {
    $api->check(-1, CREATE, $_POST);

    if ($newID = $api->add($_POST)) {
        Html::redirect(BearerApi::getFormURLWithID($newID));
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $api->check($_POST['id'], UPDATE);
    $api->update($_POST);
    Html::back();
} elseif (isset($_POST['delete'])) {
    $api->check($_POST['id'], DELETE);
    $api->delete($_POST);
    $api->redirectToList();
} elseif (isset($_POST['restore'])) {
    $api->check($_POST['id'], DELETE);
    $api->restore($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $api->check($_POST['id'], PURGE);
    $api->delete(['id' => (int) $_POST['id']], true);
    $api->redirectToList();
}

Html::header(
    BearerApi::getTypeName(Session::getPluralNumber()),
    '',
    'config',
    BearerApi::class
);

$api->display($_GET);

Html::footer();
