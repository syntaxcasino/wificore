<?php

return [
    'snapshot_ttl_minutes' => env('ROUTER_COMPLIANCE_SNAPSHOT_TTL_MINUTES', 30),
    'minimum_score' => env('ROUTER_COMPLIANCE_MINIMUM_SCORE', 85),
    'weights' => [
        'ssh' => 15,
        'api' => 15,
        'firewall' => 20,
        'ntp' => 15,
        'dns' => 10,
        'backup_schedule' => 15,
        'baseline_snapshot' => 10,
    ],
];
