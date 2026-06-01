<?php

declare(strict_types=1);

return [
    'default_template' => env('ROUTER_TEMPLATE_DEFAULT', 'mikrotik-default'),
    'templates' => [
        'mikrotik-default' => [
            'id' => 'mikrotik-default',
            'name' => 'MikroTik Default ISP',
            'category' => 'general',
            'description' => 'Baseline MikroTik ISP profile with PPP, firewall, queues, and monitoring hooks.',
            'tags' => ['mikrotik', 'pppoe', 'hotspot', 'firewall'],
            'supported_vendors' => ['mikrotik'],
            'execution_template_type' => 'hybrid',
        ],
        'multi-wan-failover' => [
            'id' => 'multi-wan-failover',
            'name' => 'Multi-WAN Failover',
            'category' => 'wan',
            'description' => 'Template for primary/backup WAN failover with health checks and safe rollback.',
            'tags' => ['multi-wan', 'failover', 'health-check'],
            'supported_vendors' => ['mikrotik', 'ubiquiti', 'tp-link', 'huawei', 'cisco', 'juniper'],
            'execution_template_type' => 'multi-wan-failover',
        ],
        'pcc-balanced' => [
            'id' => 'pcc-balanced',
            'name' => 'PCC Load Balanced WAN',
            'category' => 'wan',
            'description' => 'Per-connection classifier balancing template for higher throughput sites.',
            'tags' => ['pcc', 'load-balance', 'multi-wan'],
            'supported_vendors' => ['mikrotik'],
            'execution_template_type' => 'pcc-balanced',
        ],
        'wireguard-backup' => [
            'id' => 'wireguard-backup',
            'name' => 'WireGuard Backup Tunnel',
            'category' => 'resilience',
            'description' => 'Backup tunnel template for resilient management and failover connectivity.',
            'tags' => ['wireguard', 'backup', 'resilience'],
            'supported_vendors' => ['mikrotik'],
            'execution_template_type' => 'hybrid',
        ],
        'hotel-hotspot' => [
            'id' => 'hotel-hotspot',
            'name' => 'Hotel Hotspot',
            'category' => 'hotspot',
            'description' => 'Guest access hotspot template with captive portal and bandwidth controls.',
            'tags' => ['hotspot', 'guest', 'captive-portal'],
            'supported_vendors' => ['mikrotik'],
            'execution_template_type' => 'hotspot',
        ],
    ],
];
