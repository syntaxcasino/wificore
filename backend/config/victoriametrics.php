<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VictoriaMetrics Query URL
    |--------------------------------------------------------------------------
    |
    | Base URL used by VictoriaMetricsClient to query metrics.
    | If empty, the client derives it from the write URL.
    |
    */
    'query_url' => env('VICTORIA_METRICS_QUERY_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | VictoriaMetrics Write URL
    |--------------------------------------------------------------------------
    |
    | URL used by Telegraf to push metrics into VictoriaMetrics.
    | Points directly to the VM container (bypasses Nginx).
    | Note: Telegraf's InfluxDB output automatically appends /write to this URL.
    |
    */
    'write_url' => env('VICTORIA_METRICS_WRITE_URL', 'http://wificore-victoriametrics:8428/api/v1'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'http_timeout' => (int) env('VICTORIA_METRICS_HTTP_TIMEOUT', 5),

];
