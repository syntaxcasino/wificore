<?php

return [
    /*
    |----------------------------------------------------------------------
    |----------------------------------------------------------------------
    |
    | This configuration file contains the settings for connecting to the
    | Mikrotik router using the RouterOS API.
    |
    |
    */
    'host' => env('MIKROTIK_HOST', '192.168.100.1'),
    'user' => env('MIKROTIK_USER', 'admin'),
    'password' => env('MIKROTIK_PASSWORD', ''),
    'port' => env('MIKROTIK_PORT', 8728),
];