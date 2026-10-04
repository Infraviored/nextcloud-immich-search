<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index',          'url' => '/',                          'verb' => 'GET'],
		// Deep links into the single-page app (reloads land on the same page)
		['name' => 'page#index',          'url' => '/people',                    'verb' => 'GET', 'postfix' => 'people'],
		['name' => 'page#index',          'url' => '/people/{id}',               'verb' => 'GET', 'postfix' => 'person'],
		['name' => 'page#index',          'url' => '/settings',                  'verb' => 'GET', 'postfix' => 'settings'],
		['name' => 'page#index',          'url' => '/settings/{section}',        'verb' => 'GET', 'postfix' => 'settingsSection'],
		['name' => 'page#index', 'url' => '/timeline', 'verb' => 'GET', 'postfix' => 'timeline'],
		['name' => 'page#index', 'url' => '/map', 'verb' => 'GET', 'postfix' => 'map'],
		['name' => 'page#index', 'url' => '/folders', 'verb' => 'GET', 'postfix' => 'folders'],
		['name' => 'page#index', 'url' => '/duplicates', 'verb' => 'GET', 'postfix' => 'duplicates'],

		['name' => 'api#search',          'url' => '/api/search',                'verb' => 'GET'],
		['name' => 'api#thumbnail',       'url' => '/api/assets/{id}/thumbnail', 'verb' => 'GET'],

		['name' => 'browse#timeline', 'url' => '/api/timeline', 'verb' => 'GET'],
		['name' => 'browse#memories', 'url' => '/api/memories', 'verb' => 'GET'],
		['name' => 'browse#duplicates', 'url' => '/api/duplicates', 'verb' => 'GET'],
		['name' => 'browse#dismissDuplicate', 'url' => '/api/duplicates/{id}', 'verb' => 'DELETE'],
		['name' => 'browse#folder', 'url' => '/api/folder', 'verb' => 'GET'],
		['name' => 'browse#items', 'url' => '/api/items', 'verb' => 'GET'],
		['name' => 'browse#map', 'url' => '/api/map', 'verb' => 'GET'],
		['name' => 'browse#tile', 'url' => '/api/tiles/{z}/{x}/{y}', 'verb' => 'GET'],

		['name' => 'people#index',        'url' => '/api/people',                'verb' => 'GET'],
		['name' => 'people#face',         'url' => '/api/people/{id}/face',      'verb' => 'GET'],
		['name' => 'people#update',       'url' => '/api/people/{id}',           'verb' => 'PUT'],
		['name' => 'people#merge',        'url' => '/api/people/{id}/merge',     'verb' => 'POST'],

		['name' => 'files#transfer',      'url' => '/api/files/transfer',        'verb' => 'POST'],
		['name' => 'files#undo', 'url' => '/api/files/undo', 'verb' => 'POST'],
		['name' => 'files#favorite', 'url' => '/api/files/favorite', 'verb' => 'POST'],
		['name' => 'files#delete', 'url' => '/api/files/delete', 'verb' => 'POST'],
		['name' => 'files#tags', 'url' => '/api/tags', 'verb' => 'GET'],
		['name' => 'files#tag', 'url' => '/api/files/tag', 'verb' => 'POST'],
		['name' => 'files#zip', 'url' => '/api/files/zip', 'verb' => 'GET'],

		['name' => 'settings#save',       'url' => '/api/settings',              'verb' => 'PUT'],
		['name' => 'settings#test',       'url' => '/api/settings/test',         'verb' => 'POST'],
		['name' => 'prefs#get', 'url' => '/api/prefs', 'verb' => 'GET'],
		['name' => 'prefs#save', 'url' => '/api/prefs', 'verb' => 'PUT'],
		['name' => 'prefs#status', 'url' => '/api/status', 'verb' => 'GET'],

		['name' => 'admin#settings', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#saveSettings', 'url' => '/api/admin/settings', 'verb' => 'PUT'],
		['name' => 'admin#libraries', 'url' => '/api/admin/libraries', 'verb' => 'GET'],
		['name' => 'admin#createLibrary', 'url' => '/api/admin/libraries', 'verb' => 'POST'],
		['name' => 'admin#folderSuggestions', 'url' => '/api/admin/folder-suggestions', 'verb' => 'GET'],
		['name' => 'admin#queueMissing', 'url' => '/api/admin/queue-missing', 'verb' => 'POST'],
		['name' => 'admin#scan', 'url' => '/api/admin/scan', 'verb' => 'POST'],
		['name' => 'admin#prune', 'url' => '/api/admin/prune', 'verb' => 'POST'],

		['name' => 'settings#reindex',    'url' => '/api/settings/reindex',      'verb' => 'POST'],
	],
];
