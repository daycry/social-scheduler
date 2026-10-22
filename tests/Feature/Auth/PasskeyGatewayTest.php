<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Passkeys\PasskeyFailure;
use App\Services\Passkeys\PasskeyGateway;
use Tests\Support\FakePasskeys;

beforeEach(fn () => FakePasskeys::bind());

/** Ejecuta $fn y devuelve el PasskeyFailure que lanza. */
function passkeyFailure(Closure $fn): PasskeyFailure
{
    try {
        $fn();
    } catch (PasskeyFailure $e) {
        return $e;
    }

    throw new RuntimeException('debia fallar con PasskeyFailure');
}

test('PasskeyGateway: las opciones de alta piden credencial discoverable con verificacion de usuario y guardan el reto en sesion', function () {
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Primera', 'cred-1');

    $options = app(PasskeyGateway::class)->registrationOptions($user);

    expect($options['rp']['id'])->toBe(config('passkeys.relying_party_id'))
        ->and($options['rp']['name'])->toBe(config('passkeys.relying_party_id'))
        ->and($options['authenticatorSelection']['residentKey'])->toBe('required')
        ->and($options['authenticatorSelection']['userVerification'])->toBe('required')
        ->and($options['excludeCredentials'])->toHaveCount(1)
        ->and(session()->has('passkey.registration_options'))->toBeTrue();
});

test('PasskeyGateway: las opciones de login no llevan usuario y el reto es de un solo uso', function () {
    $gateway = app(PasskeyGateway::class);
    $options = $gateway->loginOptions();

    expect($options['userVerification'])->toBe('required')
        ->and($options['rpId'])->toBe(config('passkeys.relying_party_id'))
        ->and($options['allowCredentials'] ?? [])->toBe([]);

    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-a');

    $gateway->authenticate(FakePasskeys::credential('cred-a'));

    // Segundo uso con el mismo reto: la sesion ya no lo tiene.
    expect(passkeyFailure(fn () => $gateway->authenticate(FakePasskeys::credential('cred-a')))->reason)
        ->toBe(PasskeyFailure::SESSION_EXPIRED);
});

test('PasskeyGateway: registrar guarda la passkey del usuario y audita sin material de la credencial', function () {
    $user = User::factory()->create();
    $gateway = app(PasskeyGateway::class);
    $gateway->registrationOptions($user);

    $passkey = $gateway->register($user, 'Mi móvil', FakePasskeys::credential('cred-nueva', 'attestation'));

    expect($user->passkeys()->count())->toBe(1)
        ->and($passkey->name)->toBe('Mi móvil');

    $row = AuditLog::where('action', 'auth.passkey.registered')->sole();
    expect($row->channel)->toBe('web')
        ->and($row->user_id)->toBe($user->id)
        ->and($row->resource_id)->toBe($passkey->id)
        ->and($row->outcome)->toBe('ok');
    foreach ($row->getAttributes() as $value) {
        expect((string) $value)->not->toContain($passkey->credential_id);
    }
});

test('PasskeyGateway: registrar el mismo autenticador dos veces se rechaza', function () {
    $user = User::factory()->create();
    $gateway = app(PasskeyGateway::class);

    $gateway->registrationOptions($user);
    $gateway->register($user, 'A', FakePasskeys::credential('misma', 'attestation'));

    $gateway->registrationOptions($user);
    $e = passkeyFailure(fn () => $gateway->register($user, 'B', FakePasskeys::credential('misma', 'attestation')));

    expect($e->reason)->toBe(PasskeyFailure::REGISTRATION_REJECTED)
        ->and($user->passkeys()->count())->toBe(1);
});

test('PasskeyGateway: sin opciones en sesion o con una respuesta mal formada falla con un motivo estable', function () {
    $user = User::factory()->create();
    $gateway = app(PasskeyGateway::class);

    expect(passkeyFailure(fn () => $gateway->register($user, 'A', FakePasskeys::credential('x', 'attestation')))->reason)
        ->toBe(PasskeyFailure::SESSION_EXPIRED);

    $gateway->loginOptions();
    expect(passkeyFailure(fn () => $gateway->authenticate(['id' => 'x']))->reason)
        ->toBe(PasskeyFailure::INVALID_FORMAT);
});

test('PasskeyGateway: autenticar devuelve la passkey con su usuario y audita el login', function () {
    $user = User::factory()->create();
    $passkey = FakePasskeys::seed($user, 'Mia', 'cred-b');
    $gateway = app(PasskeyGateway::class);
    $gateway->loginOptions();

    $result = $gateway->authenticate(FakePasskeys::credential('cred-b'));

    expect($result->is($passkey))->toBeTrue()
        ->and($result->user->is($user))->toBeTrue()
        ->and($result->last_used_at)->not->toBeNull();

    $row = AuditLog::where('action', 'auth.passkey.login')->sole();
    expect($row->channel)->toBe('web')->and($row->user_id)->toBe($user->id);
});

test('PasskeyGateway: los fallos se clasifican y llevan el dueno solo para la auditoria', function () {
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-c');
    $gateway = app(PasskeyGateway::class);

    $fail = function (string $credentialId, ?string $mode = null) use ($gateway): PasskeyFailure {
        FakePasskeys::$verifyFailure = $mode;
        $gateway->loginOptions();

        return passkeyFailure(fn () => $gateway->authenticate(FakePasskeys::credential($credentialId)));
    };

    $unknown = $fail('no-existe');
    expect($unknown->reason)->toBe(PasskeyFailure::UNKNOWN_CREDENTIAL)->and($unknown->userId)->toBeNull();

    $counter = $fail('cred-c', 'counter');
    expect($counter->reason)->toBe(PasskeyFailure::SIGN_COUNT_REGRESSION)->and($counter->userId)->toBe($user->id);

    $crypto = $fail('cred-c', 'crypto');
    expect($crypto->reason)->toBe(PasskeyFailure::VERIFICATION_FAILED);

    // El mensaje al cliente es el mismo en todos los casos.
    expect($unknown->getMessage())->toBe($counter->getMessage())->toBe($crypto->getMessage());
});

test('PasskeyGateway: borrar quita la passkey y audita auth.passkey.deleted', function () {
    $user = User::factory()->create();
    $passkey = FakePasskeys::seed($user);

    app(PasskeyGateway::class)->delete($user, $passkey);

    expect($user->passkeys()->count())->toBe(0);
    $row = AuditLog::where('action', 'auth.passkey.deleted')->sole();
    expect($row->channel)->toBe('web')->and($row->resource_id)->toBe($passkey->id);
});

test('PasskeyGateway: solo el gateway, el modelo, el provider y el listener importan el paquete en app/', function () {
    $allowed = ['Services/Passkeys/PasskeyGateway.php', 'Listeners/AuditPasskeyEvents.php', 'Providers/AppServiceProvider.php', 'Models/User.php'];
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
        if (preg_match('/^use (Laravel\\\\Passkeys|Webauthn)\\\\/m', file_get_contents($file->getPathname())) && ! in_array($relative, $allowed, true)) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});
