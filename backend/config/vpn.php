<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VPN Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for WireGuard VPN server that allows remote routers
    | to connect back to the management system.
    |
    */

    'server_endpoint' => env('VPN_SERVER_ENDPOINT', 'vpn.example.com:51820'),
    
    'server_public_ip' => env('VPN_SERVER_PUBLIC_IP', null),
    
    'listen_port' => env('VPN_LISTEN_PORT', 51820),
    
    'interface_name' => env('VPN_INTERFACE_NAME', 'wg0'),
    
    /*
    |--------------------------------------------------------------------------
    | Subnet Allocation
    |--------------------------------------------------------------------------
    |
    | Configuration for tenant subnet allocation.
    | Each tenant gets a unique /16 subnet from 10.0.0.0/8 range.
    |
    */

    'subnet' => [
        'base' => '10.0.0.0/8',
        'tenant_prefix' => 16, // Each tenant gets /16 (65,534 IPs)
        'start_octet' => 100, // Start from 10.100.0.0
        'end_octet' => 254, // End at 10.254.0.0
    ],

    /*
    |--------------------------------------------------------------------------
    | WireGuard Configuration
    |--------------------------------------------------------------------------
    |
    | Default WireGuard settings for client connections.
    |
    */

    'wireguard' => [
        'keepalive_interval' => env('VPN_KEEPALIVE_INTERVAL', 25),
        'allowed_ips' => ['0.0.0.0/0'], // Route all traffic through VPN
        'dns_servers' => ['8.8.8.8', '8.8.4.4'],
        'mtu' => 1420,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Security settings for VPN connections.
    |
    */

    'security' => [
        'use_preshared_key' => true, // Enable preshared keys for additional security
        'rotate_keys_days' => 90, // Rotate keys every 90 days
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    |
    | Settings for monitoring VPN connections.
    |
    */

    'monitoring' => [
        'check_interval' => 60, // Check connection status every 60 seconds
        'inactive_threshold' => 180, // Mark as inactive after 3 minutes without handshake
    ],

];
