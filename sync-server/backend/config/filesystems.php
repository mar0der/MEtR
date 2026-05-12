<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        'updates' => [
            'driver' => 'local',
            'root' => storage_path('app/updates'),
            'url' => env('APP_URL').'/updates',
            'visibility' => 'public',
            'throw' => false,
        ],
    ],
];
