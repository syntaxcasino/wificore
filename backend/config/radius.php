<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RADIUS Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for FreeRADIUS authentication and accounting.
    | VPN_SERVER_IP takes precedence when routers connect via WireGuard VPN,
    | as RADIUS traffic is forwarded through the VPN tunnel via DNAT rules.
    |
    */

    'server_ip' => env('VPN_SERVER_IP', env('RADIUS_SERVER_IP', env('RADIUS_SERVER_HOST', 'wificore-freeradius'))),

    'secret' => env('RADIUS_SECRET', 'testing123'),

    'auth_port' => (int) env('RADIUS_AUTH_PORT', 1812),

    'acct_port' => (int) env('RADIUS_ACCT_PORT', 1813),

    'timeout' => (int) env('RADIUS_TIMEOUT', 3),

];
