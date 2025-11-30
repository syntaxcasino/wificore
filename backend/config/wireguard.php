<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WireGuard Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the WireGuard VPN server that connects remote
    | MikroTik routers to the central RADIUS server.
    |
    */

    'server_public_ip' => env('WIREGUARD_SERVER_PUBLIC_IP', ''),
    
    'server_port' => env('WIREGUARD_SERVER_PORT', 51820),
    
    'server_vpn_ip' => env('WIREGUARD_SERVER_VPN_IP', '10.10.10.1'),
    
    'vpn_network' => env('WIREGUARD_VPN_NETWORK', '10.10.10.0/24'),
    
    'config_path' => env('WIREGUARD_CONFIG_PATH', '/etc/wireguard/wg0.conf'),
    
    'server_public_key_path' => env('WIREGUARD_SERVER_PUBLIC_KEY_PATH', '/etc/wireguard/server_public.key'),
    
    /*
    |--------------------------------------------------------------------------
    | RADIUS Configuration
    |--------------------------------------------------------------------------
    */
    
    'radius' => [
        'server_ip' => env('RADIUS_SERVER_IP', '10.10.10.1'),
        'auth_port' => env('RADIUS_AUTH_PORT', 1812),
        'acct_port' => env('RADIUS_ACCT_PORT', 1813),
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Router VPN Configuration
    |--------------------------------------------------------------------------
    */
    
    'router' => [
        'listen_port' => env('WIREGUARD_ROUTER_PORT', 13231),
        'persistent_keepalive' => env('WIREGUARD_KEEPALIVE', 25),
    ],

];
