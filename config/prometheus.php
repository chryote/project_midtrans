<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Prometheus Metrics Enabled
    |--------------------------------------------------------------------------
    |
    | Determine whether Prometheus metrics collection and exposition are enabled.
    |
    */
    'enabled' => env('PROMETHEUS_METRICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Namespace
    |--------------------------------------------------------------------------
    |
    | Namespace prefix for all metrics exported by this application.
    |
    */
    'namespace' => env('PROMETHEUS_NAMESPACE', 'laravel_app'),

    /*
    |--------------------------------------------------------------------------
    | Storage Adapter
    |--------------------------------------------------------------------------
    |
    | Supported adapters: "redis", "memory"
    | In production, "redis" allows multiple PHP-FPM workers to aggregate metrics.
    |
    */
    'storage_adapter' => env('PROMETHEUS_STORAGE_ADAPTER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection for Metrics
    |--------------------------------------------------------------------------
    */
    'redis' => [
        'host' => env('REDIS_HOST', 'redis'),
        'port' => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', null),
        'timeout' => 0.5,
        'read_timeout' => '10',
        'persistent_connections' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Latency Histogram Buckets (in seconds)
    |--------------------------------------------------------------------------
    */
    'buckets' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0],
];
