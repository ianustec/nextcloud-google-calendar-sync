<?php

declare(strict_types=1);

return [
    'routes' => [
        [
            'name' => 'AdminSettings#save',
            'url' => '/api/admin/settings',
            'verb' => 'POST',
        ],
        [
            'name' => 'AdminSettings#testConnection',
            'url' => '/api/admin/test',
            'verb' => 'POST',
        ],
        [
            'name' => 'AdminSettings#syncNow',
            'url' => '/api/admin/sync-now',
            'verb' => 'POST',
        ],
        [
            'name' => 'AdminSettings#listUsers',
            'url' => '/api/admin/users',
            'verb' => 'GET',
        ],
        [
            'name' => 'AdminSettings#syncSingleUser',
            'url' => '/api/admin/sync-user',
            'verb' => 'POST',
        ],
    ],
];
