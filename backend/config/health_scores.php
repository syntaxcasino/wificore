<?php

return [
    'stale_router_minutes' => 15,
    'vpn_stale_minutes' => 10,
    'payment_overdue_minutes' => 30,
    'session_overdue_minutes' => 15,
    'weights' => [
        'offline_router' => 14,
        'stale_router' => 6,
        'vpn_stale_router' => 10,
        'pending_payment' => 4,
        'failed_payment' => 6,
        'expired_session' => 5,
        'provisioning_backlog' => 2,
    ],
];
