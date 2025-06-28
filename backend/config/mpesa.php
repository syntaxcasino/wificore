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
    'base_url' => env('MPESA_ENV') === 'production' 
        ? 'https://api.safaricom.co.ke' 
        : 'https://sandbox.safaricom.co.ke',

    /*
    |--------------------------------------------------------------------------
    | Authentication Credentials
    |--------------------------------------------------------------------------
    | Required for both sandbox and production
    */
    'consumer_key' => env('MPESA_CONSUMER_KEY', 'YourSandboxConsumerKey'),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET', 'YourSandboxConsumerSecret'),

    /*
    |--------------------------------------------------------------------------
    | Business Details
    |--------------------------------------------------------------------------
    | Use 174379 for sandbox testing
    */
    'shortcode' => env('MPESA_BUSINESS_SHORTCODE', '174379'),
    'business_shortcode' => env('MPESA_BUSINESS_SHORTCODE', '174379'),
    'passkey' => env('MPESA_PASSKEY', 'YourSandboxPasskey'),
    'security_credential' => env('MPESA_SECURITY_CREDENTIAL'),

    /*
    |--------------------------------------------------------------------------
    | Transaction Configuration
    |--------------------------------------------------------------------------
    */
    'account_reference' => env('MPESA_ACCOUNT_REFERENCE', 'COMPANY_NAME'),
    'transaction_type' => env('MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
    'transaction_desc' => env('MPESA_TRANSACTION_DESC', 'Payment for services'),

    /*
    |--------------------------------------------------------------------------
    | Callback URLs
    |--------------------------------------------------------------------------
    */
    'callback_url' => env('MPESA_CALLBACK_URL', env('APP_URL').'/api/mpesa/callback'),
    'queue_timeout_url' => env('MPESA_QUEUE_TIMEOUT_URL', env('APP_URL').'/api/mpesa/timeout'),

    /*
    |--------------------------------------------------------------------------
    | API Performance Settings
    |--------------------------------------------------------------------------
    */
    'timeout' => env('MPESA_TIMEOUT', 30), // seconds
    'connect_timeout' => env('MPESA_CONNECT_TIMEOUT', 10),
    'verify_ssl' => env('MPESA_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Initiator Details (For B2B/B2C transactions)
    |--------------------------------------------------------------------------
    */
    'initiator_name' => env('MPESA_INITIATOR_NAME'),
    'initiator_password' => env('MPESA_INITIATOR_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    */
    'public_key_path' => env('MPESA_PUBLIC_KEY_PATH', storage_path('app/mpesa_public_key.cer')),
];