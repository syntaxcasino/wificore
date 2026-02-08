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
    | URL used by Telegraf (and any future Laravel-side writers) to push
    | metrics into VictoriaMetrics via Prometheus remote-write protocol.
    |
    */
    'write_url' => env('VICTORIA_METRICS_WRITE_URL', 'http://wificore-nginx/internal/vm/api/v1/write'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'http_timeout' => (int) env('VICTORIA_METRICS_HTTP_TIMEOUT', 5),

];
