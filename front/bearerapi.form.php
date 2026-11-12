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

include(__DIR__ . '/../../../inc/includes.php');

Session::checkRight('config', READ);

$api = new BearerApi();

if (isset($_POST['refresh'])) {
    // Ask for a new token right now, using the settings already saved.
    $id = (int) ($_POST['id'] ?? -1);
    if ($api->canEdit($id) && $api->getFromDB($id)) {
        if (TokenManager::refreshToken($api) !== null) {
            Session::addMessageAfterRedirect(__('The bearer token has been refreshed.', 'webhookbearerapi'));
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
    Html::redirect(BearerApi::getFormURL() . '?id=' . $id);
}

if (isset($_POST['add'])) {
    $api->check(-1, CREATE, $_POST);

    if ($newID = $api->add($_POST)) {
        Html::redirect(BearerApi::getFormURL() . '?id=' . $newID);
    }
} else if (isset($_POST['update'])) {
    $api->check($_POST['id'], UPDATE);

    $api->update($_POST);
    Html::back();
} else if (isset($_POST['delete'])) {
    $api->check($_POST['id'], DELETE);
    $api->delete($_POST);
    Html::back();
} else if (isset($_POST['restore'])) {
    $api->check($_POST['id'], DELETE);
    $api->restore($_POST);
    Html::back();
} else if (isset($_POST['purge'])) {
    $api->check($_POST['id'], PURGE);
    $api->delete(['id' => (int) $_POST['id']], true);
    Html::back();
}

Html::header(
    BearerApi::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'config',
    BearerApi::class
);

$api->display($_GET);

Html::footer();
