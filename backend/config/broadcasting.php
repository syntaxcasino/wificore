<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | Laravel's broadcasting allows you to broadcast events to different
    | frontend technologies. Here you may configure the default broadcaster
    | that will be used by the framework.
    |
    */

    'default' => env('BROADCAST_DRIVER', 'pusher'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over websockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'null' => [
            'driver' => 'null',
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY', 'app-key'),
            'secret' => env('PUSHER_APP_SECRET', 'app-secret'),
            'app_id' => env('PUSHER_APP_ID', 'wifi-hotspot-app'),
            'options' => [
                'host' => env('PUSHER_HOST', 'soketi'),
                'port' => (int) env('PUSHER_PORT', 6001),
                'scheme' => env('PUSHER_SCHEME', 'http'),
                'encrypted' => false,
                'useTLS' => false,
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                'wsHost' => env('PUSHER_HOST', 'soketi'),
                'wsPort' => (int) env('PUSHER_PORT', 6001),
                'wssPort' => (int) env('PUSHER_PORT', 6001),
                'forceTLS' => false,
                'enabledTransports' => ['ws', 'wss'],
                'authEndpoint' => '/api/broadcasting/auth',
            ],
        ],

    ],

];
