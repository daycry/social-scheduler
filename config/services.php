<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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
    | Proveedores OAuth de redes sociales (T-12..T-14). Las redirect URIs que hay que registrar en
    | cada app son /connections/{meta|google|tiktok}/callback sobre APP_URL (ver .env.example).
    */
    'meta' => [
        'client_id' => env('META_APP_ID'),
        'client_secret' => env('META_APP_SECRET'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    ],

    // Servidor MCP (T-28/T-30): peticiones por minuto por API key y por IP.
    'mcp' => [
        'per_minute' => env('MCP_RATE_PER_MINUTE', 60),
        'per_minute_ip' => env('MCP_RATE_PER_MINUTE_IP', 120),
        // Origenes (ademas de APP_URL) que pueden llamar al MCP desde un navegador; sin Origin siempre se permite.
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('MCP_ALLOWED_ORIGINS', ''))))),
        // Maximo de vida de una key en dias (la fecha elegida por el usuario manda dentro de este tope) y default.
        'key_max_days' => (int) env('MCP_KEY_MAX_DAYS', 730),
        'key_default_days' => (int) env('MCP_KEY_DEFAULT_DAYS', 365),
    ],

];
