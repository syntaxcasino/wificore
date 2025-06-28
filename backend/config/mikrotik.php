<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Mikrotik Router Configuration
    |--------------------------------------------------------------------------
    */
    'host' => env('MIKROTIK_HOST', '192.168.88.1'),
    'user' => env('MIKROTIK_USER', 'admin'),
    'pass' => env('MIKROTIK_PASSWORD', 'admin'),
    'port' => env('MIKROTIK_PORT', 8728),
    
    /*
    |--------------------------------------------------------------------------
    | Connection Settings
    |--------------------------------------------------------------------------
    */
    'timeout' => env('MIKROTIK_TIMEOUT', 10), // seconds
    'attempts' => env('MIKROTIK_ATTEMPTS', 3), // connection attempts
    'delay' => env('MIKROTIK_DELAY', 1), // seconds between attempts
    
    /*
    |--------------------------------------------------------------------------
    | Default Hotspot Profile
    |--------------------------------------------------------------------------
    */
    'default_profile' => env('MIKROTIK_DEFAULT_PROFILE', 'default'),
    
    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    */
    'cache_ttl' => env('MIKROTIK_CACHE_TTL', 300), // seconds

    /*
    |--------------------------------------------------------------------------
    | Voucher Settings
    |--------------------------------------------------------------------------
    */
    'voucher_length' => env('MIKROTIK_VOUCHER_LENGTH', 8),
    'voucher_prefix' => env('MIKROTIK_VOUCHER_PREFIX', ''),
    'voucher_suffix' => env('MIKROTIK_VOUCHER_SUFFIX', ''),
];