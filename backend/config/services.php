<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configure WhatsApp messaging service. Supports multiple providers:
    | - twilio: Twilio WhatsApp API
    | - africas_talking: Africa's Talking WhatsApp API
    | - whatsapp_business: Direct WhatsApp Business API
    |
    */

    'whatsapp' => [
        'provider' => env('WHATSAPP_PROVIDER', 'twilio'), // twilio, africas_talking, whatsapp_business
        'api_key' => env('WHATSAPP_API_KEY'),
        'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v17.0'),
        'from_number' => env('WHATSAPP_FROM_NUMBER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Twilio Configuration (for WhatsApp and SMS)
    |--------------------------------------------------------------------------
    */

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from_number' => env('TWILIO_FROM_NUMBER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Africa's Talking Configuration
    |--------------------------------------------------------------------------
    */

    'africas_talking' => [
        'username' => env('AFRICAS_TALKING_USERNAME'),
        'api_key' => env('AFRICAS_TALKING_API_KEY'),
        'from' => env('AFRICAS_TALKING_FROM'),
    ],

];
