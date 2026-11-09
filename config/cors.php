<?php

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))
)));

$allowedMethods = array_values(array_filter(array_map(
    static fn (string $method): string => trim($method),
    explode(',', (string) env('CORS_ALLOWED_METHODS', '*'))
)));

$allowedHeaders = array_values(array_filter(array_map(
    static fn (string $header): string => trim($header),
    explode(',', (string) env('CORS_ALLOWED_HEADERS', '*'))
)));

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Change the allowed origins in your .env file. Comma-separated values
    | are supported, e.g. http://localhost:3000,https://app.example.com
    |
    */

    'paths' => ['*'],

    'allowed_methods' => $allowedMethods === [] ? ['*'] : $allowedMethods,

    'allowed_origins' => $allowedOrigins === [
        
    ] ? ['*'] : $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => $allowedHeaders === [] ? ['*'] : $allowedHeaders,

    'exposed_headers' => [],

    'max_age' => (int) env('CORS_MAX_AGE', 0),

    'supports_credentials' => filter_var(env('CORS_SUPPORTS_CREDENTIALS', false), FILTER_VALIDATE_BOOL),
];
