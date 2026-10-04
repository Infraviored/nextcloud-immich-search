<?php
declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index',          'url' => '/',                          'verb' => 'GET'],
        // Deep links into the single-page app (reloads land on the same page)
        ['name' => 'page#index',          'url' => '/people',                    'verb' => 'GET', 'postfix' => 'people'],
        ['name' => 'page#index',          'url' => '/people/{id}',               'verb' => 'GET', 'postfix' => 'person'],

        ['name' => 'api#search',          'url' => '/api/search',                'verb' => 'GET'],
        ['name' => 'api#thumbnail',       'url' => '/api/assets/{id}/thumbnail', 'verb' => 'GET'],

        ['name' => 'people#index',        'url' => '/api/people',                'verb' => 'GET'],
        ['name' => 'people#face',         'url' => '/api/people/{id}/face',      'verb' => 'GET'],
        ['name' => 'people#update',       'url' => '/api/people/{id}',           'verb' => 'PUT'],
        ['name' => 'people#merge',        'url' => '/api/people/{id}/merge',     'verb' => 'POST'],

        ['name' => 'files#transfer',      'url' => '/api/files/transfer',        'verb' => 'POST'],

        ['name' => 'settings#save',       'url' => '/api/settings',              'verb' => 'PUT'],
        ['name' => 'settings#test',       'url' => '/api/settings/test',         'verb' => 'POST'],
        ['name' => 'settings#reindex',    'url' => '/api/settings/reindex',      'verb' => 'POST'],
    ],
];
