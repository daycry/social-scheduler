<?php

use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

uses(TestCase::class);

/** Evalua config/passkeys.php con un entorno concreto, sin tocar el estado global de la app. */
function passkeyConfig(array $env, string $appUrl = 'https://metricool.test'): array
{
    $saved = [];
    foreach (['WEBAUTHN_RP_ID', 'WEBAUTHN_ORIGINS', 'PASSKEYS_USER_HANDLE_SECRET'] as $key) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }
    foreach ($env as $key => $value) {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("$key=$value");
    }
    $previousUrl = config('app.url');
    $previousKey = config('app.key');
    config(['app.url' => $appUrl, 'app.key' => 'base64:clave-de-app']);

    try {
        return require base_path('config/passkeys.php');
    } finally {
        config(['app.url' => $previousUrl, 'app.key' => $previousKey]);
        foreach ($saved as $key => [$e, $s, $g]) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if ($e !== null) {
                $_ENV[$key] = $e;
            }
            if ($s !== null) {
                $_SERVER[$key] = $s;
            }
            if ($g !== false) {
                putenv("$key=$g");
            }
        }
    }
}

it('deriva el RP ID y los origenes de APP_URL por defecto', function () {
    $c = passkeyConfig([], 'https://metricool.test');

    expect($c['relying_party_id'])->toBe('metricool.test')
        ->and($c['allowed_origins'])->toBe(['https://metricool.test']);
});

it('el RP ID por defecto no incluye el puerto ni el esquema', function () {
    $c = passkeyConfig([], 'http://localhost:8080');

    expect($c['relying_party_id'])->toBe('localhost')
        ->and($c['allowed_origins'])->toBe(['http://localhost:8080']);
});

it('WEBAUTHN_RP_ID y WEBAUTHN_ORIGINS mandan sobre APP_URL y los origenes son una lista CSV', function () {
    $c = passkeyConfig([
        'WEBAUTHN_RP_ID' => 'ejemplo.com',
        'WEBAUTHN_ORIGINS' => 'https://ejemplo.com, https://www.ejemplo.com ,',
    ]);

    expect($c['relying_party_id'])->toBe('ejemplo.com')
        ->and($c['allowed_origins'])->toBe(['https://ejemplo.com', 'https://www.ejemplo.com']);
});

it('el secreto del user handle es propio y cae en APP_KEY solo si falta', function () {
    expect(passkeyConfig(['PASSKEYS_USER_HANDLE_SECRET' => 'secreto-propio'])['user_handle_secret'])->toBe('secreto-propio')
        ->and(passkeyConfig([])['user_handle_secret'])->toBe('base64:clave-de-app');
});

it('el paquete usa la config de la app y sus rutas estan ignoradas', function () {
    expect(Passkeys::relyingPartyId())->toBe(config('passkeys.relying_party_id'))
        ->and(Passkeys::shouldRegisterRoutes())->toBeFalse()
        ->and(config('passkeys.guard'))->toBe('web');
});
