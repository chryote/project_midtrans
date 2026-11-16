<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Midtrans Merchant Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for Midtrans Payment Gateway Core API (VT-Direct).
    |
    */

    'server_key' => env('MIDTRANS_SERVER_KEY', ''),
    'client_key' => env('MIDTRANS_CLIENT_KEY', ''),
    'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
    'is_sanitized' => env('MIDTRANS_IS_SANITIZED', true),
    'is_3ds' => env('MIDTRANS_IS_3DS', true),
    'signature_secret_key' => env('MIDTRANS_SIGNATURE_SECRET_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Concurrency & Lock Configuration
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for Redis distributed locks during webhook processing.
    |
    */
    'webhook_lock_timeout' => env('MIDTRANS_WEBHOOK_LOCK_TIMEOUT', 10),
];
