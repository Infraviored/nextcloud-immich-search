<?php
declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index',            'url' => '/',                              'verb' => 'GET'],
        ['name' => 'api#search',            'url' => '/api/search',                    'verb' => 'GET'],
        ['name' => 'api#people',            'url' => '/api/people',                    'verb' => 'GET'],
        ['name' => 'api#thumbnail',         'url' => '/api/assets/{id}/thumbnail',     'verb' => 'GET'],
        ['name' => 'api#personThumbnail',   'url' => '/api/people/{id}/thumbnail',     'verb' => 'GET'],
        ['name' => 'settings#save',         'url' => '/api/settings',                  'verb' => 'PUT'],
        ['name' => 'settings#test',         'url' => '/api/settings/test',             'verb' => 'POST'],
    ],
];
