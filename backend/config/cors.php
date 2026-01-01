<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    | SECURITY: Wildcard origins are disabled. Only configured domains are allowed.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter([
        env('FRONTEND_URL'),
        env('APP_URL'),
        'http://localhost:5173',  // Local development
        'http://localhost:3000',  // Alternative local dev
    ]),

    'allowed_origins_patterns' => [
        '/^https?:\/\/.*\.ngrok-free\.app$/',  // Development tunnels
        '/^https?:\/\/.*\.localhost(:\d+)?$/', // Local subdomains
    ],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin', 'X-CSRF-TOKEN'],

    'exposed_headers' => ['X-Total-Count', 'X-Page-Count'],

    'max_age' => 3600,

    'supports_credentials' => true,

];        
