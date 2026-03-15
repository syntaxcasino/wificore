<?php

return [
    'snmp_community' => env('TELEGRAF_SNMP_COMMUNITY', 'traidnet-monitor'),
    'snmpv3_user' => env('TELEGRAF_SNMPV3_USER', 'snmpmonitor'),
    'snmpv3_auth_password' => env('TELEGRAF_SNMPV3_AUTH_PASSWORD', ''),
    'snmpv3_priv_password' => env('TELEGRAF_SNMPV3_PRIV_PASSWORD', ''),
    'fast_interval' => env('TELEGRAF_FAST_INTERVAL', '3s'),
    'slow_interval' => env('TELEGRAF_SLOW_INTERVAL', '30s'),
    'shard_index' => env('TELEGRAF_SHARD_INDEX', 0),
    'shard_count' => env('TELEGRAF_SHARD_COUNT', 1),
];
