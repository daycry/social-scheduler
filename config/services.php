<?php

// Duraciones del acceso OAuth del MCP: la lista ofrecida respeta el tope de MCP_KEY_MAX_DAYS y el default es la opcion
// mas cercana que no supera MCP_OAUTH_TOKEN_DAYS (si todas lo superan, la menor ofrecida).
$oauthMaxDays = max(1, (int) env('MCP_KEY_MAX_DAYS', 730));
$oauthDayOptions = array_values(array_filter([30, 90, 180, 365], fn (int $d) => $d <= $oauthMaxDays)) ?: [$oauthMaxDays];
$oauthWantedDays = max(1, (int) env('MCP_OAUTH_TOKEN_DAYS', 90));
$oauthDefaultDays = max(array_filter($oauthDayOptions, fn (int $d) => $d <= $oauthWantedDays) ?: [min($oauthDayOptions)]);

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
        // Google Picker (C-04b, media desde Drive). Sin la clave, el boton «Google Drive» no se muestra. El `aud` del token debe ser `client_id`.
        'picker_api_key' => env('GOOGLE_PICKER_API_KEY'),
        'picker_app_id' => env('GOOGLE_PICKER_APP_ID'),
    ],

    /*
    | Threads (C-02, D2): la app de Meta tiene DOS pares de ID y secreto; aqui van los de Threads (Configuracion > Basica > «Threads App ID»).
    | Sin `THREADS_APP_ID` la red no se ofrece. Redirect URI: ${APP_URL}/connections/threads/callback.
    */
    'threads' => [
        'client_id' => env('THREADS_APP_ID'),
        'client_secret' => env('THREADS_APP_SECRET'),
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    ],

    /*
    | Login social (ADR-004): apps/clientes PROPIOS del login, separados de los de los conectores de redes (google/meta).
    | Con client_id vacio el proveedor queda desactivado (boton oculto, ruta 404). Redirect URIs a registrar:
    |   ${APP_URL}/auth/google/callback  y  ${APP_URL}/auth/facebook/callback
    */
    'google_login' => [
        'client_id' => env('GOOGLE_LOGIN_CLIENT_ID'),
        'client_secret' => env('GOOGLE_LOGIN_CLIENT_SECRET'),
        'redirect' => '/auth/google/callback',
    ],

    'facebook_login' => [
        'client_id' => env('META_LOGIN_APP_ID'),
        'client_secret' => env('META_LOGIN_APP_SECRET'),
        'redirect' => '/auth/facebook/callback',
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
        // Servidor de autorizacion OAuth 2.1 del MCP (ADR-004): clientes publicos, code + PKCE S256, sin refresh.
        'oauth' => [
            // Duraciones que el usuario puede elegir en el consentimiento (dias), siempre <= key_max_days.
            'token_day_options' => $oauthDayOptions,
            // Vida por defecto del token OAuth (dias): la opcion mas cercana por debajo de MCP_OAUTH_TOKEN_DAYS (90) que ofrece la lista.
            'token_days' => $oauthDefaultDays,
            // Vida del codigo de autorizacion, en segundos.
            'code_ttl' => (int) env('MCP_OAUTH_CODE_TTL', 300),
            // Tope global de registros DCR por hora (ademas de 10/h por IP, o por /64 en IPv6).
            'register_global_per_hour' => (int) env('MCP_OAUTH_REGISTER_GLOBAL_PER_HOUR', 200),
            // Prefijos https:// extra permitidos como redirect_uri (CSV); loopback y los callbacks de Claude ya van por defecto.
            'redirect_allowed' => array_values(array_filter(array_map('trim', explode(',', (string) env('MCP_OAUTH_REDIRECT_ALLOWED', ''))))),
        ],
    ],

];
