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

    // IP advertised to MikroTik routers for PPPoE/hotspot RADIUS (via WireGuard VPN)
    'vpn_server_ip' => env('VPN_SERVER_IP', env('RADIUS_SERVER_IP', '10.8.0.1')),

    // IP used by the PHP backend for AAA auth calls — always direct Docker hostname,
    // never via VPN (VPN has latency/packet-loss that causes 2-5s timeouts)
    'server_ip' => env('RADIUS_BACKEND_HOST', env('RADIUS_SERVER_HOST', 'wificore-freeradius')),

    'secret' => env('RADIUS_SECRET', 'testing123'),

    'auth_port' => (int) env('RADIUS_AUTH_PORT', 1812),

    'acct_port' => (int) env('RADIUS_ACCT_PORT', 1813),

    'timeout' => (int) env('RADIUS_TIMEOUT', 2),

    'allow_cleartext' => (bool) env('RADIUS_ALLOW_CLEARTEXT', true),

];
