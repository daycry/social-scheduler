<?php

/*
| Passkeys (WebAuthn) — paquete laravel/passkeys ~0.2.1 (ADR-003). Solo `App\Services\Passkeys\PasskeyGateway`
| y el modelo User tocan el paquete. Las rutas del paquete NO se cargan (Passkeys::ignoreRoutes()): las propias
| viven en routes/auth.php. El nombre del RP es el RP ID, sin variable propia (decision 1 del diseno).
|
| El RP ID es estable por entorno: si cambia (dominio, subdominio), todas las passkeys registradas dejan de valer.
*/

$appUrl = (string) config('app.url');

return [

    // Dominio de la app, sin esquema ni puerto (p. ej. "metricool.test" o "localhost"). Por defecto, el host de APP_URL.
    'relying_party_id' => env('WEBAUTHN_RP_ID') ?: parse_url($appUrl, PHP_URL_HOST),

    // Origenes completos (esquema + host + puerto) que pueden completar la ceremonia; lista separada por comas.
    // Por defecto, APP_URL.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) (env('WEBAUTHN_ORIGINS') ?: $appUrl)),
    ))),

    // Secreto del que sale el user handle de cada usuario al REGISTRAR una passkey. Si esta vacio cae en APP_KEY
    // (comportamiento del paquete) y en produccion se avisa en el log y en `php artisan about`. Rotarlo (o rotar
    // APP_KEY) NO invalida las passkeys ya registradas, porque el handle se guarda en su registro; solo cambia el
    // handle de las nuevas.
    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET') ?: config('app.key'),

    // ¿Esta definido PASSKEYS_USER_HANDLE_SECRET? (para el aviso de produccion; no es un secreto).
    'user_handle_secret_explicit' => filled(env('PASSKEYS_USER_HANDLE_SECRET')),

    // Segundos que vale un reto en la sesion del servidor (el timeout de las opciones, 60 s, mas margen).
    'challenge_ttl' => 300,

    // Milisegundos que tiene el usuario para completar el dialogo del navegador.
    'timeout' => 60000,

    'guard' => 'web',

    // No se usan: las rutas del paquete estan ignoradas. Se dejan por si algun dia se cargan.
    'middleware' => ['web'],

    'management_middleware' => ['password.confirm'],

    'throttle' => 'throttle:6,1',

    'redirect' => '/',

];
