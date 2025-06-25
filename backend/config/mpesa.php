<?php

return [
    /*
    |--------------------------------------------------------------------------
    | M-Pesa Environment
    |--------------------------------------------------------------------------
    |
    | Set to 'sandbox' for testing and 'production' for live transactions
    |
    */
    'env' => env('MPESA_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | M-Pesa API Base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('MPESA_BASE_URL', 'https://sandbox.safaricom.co.ke'),

    /*
    |--------------------------------------------------------------------------
    | Consumer Key and Secret
    |--------------------------------------------------------------------------
    */
    'consumer_key' => env('MPESA_CONSUMER_KEY'),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Business Shortcode and Passkey
    |--------------------------------------------------------------------------
    */
    'business_shortcode' => env('MPESA_BUSINESS_SHORTCODE'),
    'passkey' => env('MPESA_PASSKEY'),

    /*
    |--------------------------------------------------------------------------
    | Callback URL
    |--------------------------------------------------------------------------
    */
    'callback_url' => env('MPESA_CALLBACK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Initiator Details
    |--------------------------------------------------------------------------
    */
    'initiator_name' => env('MPESA_INITIATOR_NAME'),
    'initiator_password' => env('MPESA_INITIATOR_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Default Transaction References
    |--------------------------------------------------------------------------
    */
    'account_reference' => env('MPESA_ACCOUNT_REFERENCE', 'COMPANY_NAME'),
    'transaction_desc' => env('MPESA_TRANSACTION_DESC', 'Payment for services'),

    /*
    |--------------------------------------------------------------------------
    | Queue Timeout URL (for async operations)
    |--------------------------------------------------------------------------
    */
    'queue_timeout_url' => env('MPESA_QUEUE_TIMEOUT_URL'),

    /*
    |--------------------------------------------------------------------------
    | Public Key Path for Security Credential
    |--------------------------------------------------------------------------
    */
    'public_key_path' => env('MPESA_PUBLIC_KEY_PATH', storage_path('app/mpesa_public_key.cer')),
];