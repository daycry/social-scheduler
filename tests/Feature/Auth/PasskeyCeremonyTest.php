<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\CeremonyStep\CheckCounter;
use Webauthn\Counter\ThrowExceptionIfInvalid;
use Webauthn\CredentialRecord;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/*
 * T-12: ceremonia WebAuthn REAL (firma, reto, origen, RP ID, contador) con un vector grabado una sola vez con el
 * autenticador virtual de Chromium (tests/e2e/record-passkey-vector.ts, README en tests/Fixtures/passkeys). Nada de
 * estas pruebas sustituye las Actions del paquete: pasan por PasskeyGateway y los controladores reales.
 * El vector se grabo contra el origen http://localhost:8080 con RP ID `localhost`.
 */

/** @return array{origin: string, rpId: string, counters: list<int>, registration: array, assertions: list<array>} */
function passkeyVector(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/passkeys/vector.json')), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $vector = passkeyVector();
    config(['passkeys.relying_party_id' => $vector['rpId'], 'passkeys.allowed_origins' => [$vector['origin']]]);
});

/** Deja en la sesion las opciones de alta tal y como las guardaria PasskeyGateway. */
function seedRegistrationOptions(array $options): void
{
    session()->put('passkey.registration_options', WebAuthn::toJson(WebAuthn::fromJson(json_encode($options), PublicKeyCredentialCreationOptions::class)));
}

function seedVerificationOptions(array $options): void
{
    session()->put('passkey.verification_options', WebAuthn::toJson(WebAuthn::fromJson(json_encode($options), PublicKeyCredentialRequestOptions::class)));
}

/** Alta real con el vector para $user; devuelve la respuesta. */
function registerVectorPasskey(User $user, string $name = 'Vector'): TestResponse
{
    $vector = passkeyVector();
    test()->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    seedRegistrationOptions($vector['registration']['options']);

    return test()->postJson('/user/passkeys', ['name' => $name, 'credential' => $vector['registration']['credential']]);
}

/** Login real con la assertion $index del vector (opciones y respuesta de la misma ceremonia salvo $optionsFrom). */
function loginWithVector(int $index, ?int $optionsFrom = null): TestResponse
{
    $vector = passkeyVector();
    seedVerificationOptions($vector['assertions'][$optionsFrom ?? $index]['options']);

    return test()->postJson('/passkeys/login', ['credential' => $vector['assertions'][$index]['credential']]);
}

/** Usuario con la passkey del vector ya registrada, y la sesion limpia para entrar como invitado. */
function userWithVectorPasskey(): User
{
    $user = User::factory()->create();
    registerVectorPasskey($user)->assertCreated();
    test()->post('/logout');
    test()->flushSession();
    test()->assertGuest();

    return $user;
}

test('PasskeyCeremony: el alta con la attestation real guarda la passkey y su contador inicial', function () {
    $user = User::factory()->create();

    registerVectorPasskey($user, 'Autenticador virtual')->assertCreated()->assertJsonPath('name', 'Autenticador virtual');

    $passkey = $user->passkeys()->sole();
    expect($passkey->credential_id)->toBe(passkeyVector()['registration']['credential']['id'])
        ->and($passkey->credential['counter'])->toBe(1)
        ->and(AuditLog::where('action', 'auth.passkey.registered')->count())->toBe(1);
});

test('PasskeyCeremony: una assertion valida del vector autentica al usuario de la fixture', function () {
    $user = userWithVectorPasskey();

    loginWithVector(0)->assertOk()->assertJsonPath('redirect', url('/dashboard'));

    $this->assertAuthenticatedAs($user);
    $passkey = $user->passkeys()->sole();
    expect($passkey->credential['counter'])->toBe(passkeyVector()['counters'][0])
        ->and($passkey->last_used_at)->not->toBeNull();
    expect(AuditLog::where('action', 'auth.passkey.login')->where('user_id', $user->id)->count())->toBe(1);
});

test('PasskeyCeremony: las assertions con contador creciente siguen entrando', function () {
    $user = userWithVectorPasskey();

    foreach ([0, 1, 2] as $i) {
        loginWithVector($i)->assertOk();
        $this->post('/logout');
        $this->flushSession();
    }

    expect($user->passkeys()->sole()->credential['counter'])->toBe(passkeyVector()['counters'][2]);
});

test('PasskeyCeremony: un reto distinto al de la assertion se rechaza', function () {
    $user = userWithVectorPasskey();

    // Opciones de otra ceremonia (otro reto) con la respuesta de la assertion 0.
    loginWithVector(0, optionsFrom: 1)->assertStatus(422);

    $this->assertGuest();
    $row = AuditLog::where('action', 'auth.passkey.login_failed')->sole();
    expect($row->detail)->toBe('verification_failed')->and($row->user_id)->toBe($user->id);
    expect($user->passkeys()->sole()->credential['counter'])->toBe(1);
});

test('PasskeyCeremony: un origen distinto al permitido se rechaza', function () {
    userWithVectorPasskey();
    config(['passkeys.allowed_origins' => ['http://localhost:9999']]);

    loginWithVector(0)->assertStatus(422);

    $this->assertGuest();
    expect(AuditLog::where('action', 'auth.passkey.login_failed')->sole()->detail)->toBe('verification_failed');
});

test('PasskeyCeremony: un RP ID distinto se rechaza', function () {
    userWithVectorPasskey();
    $vector = passkeyVector();

    // El servidor pidio la ceremonia para otro dominio (RP ID), pero la assertion es de `localhost`.
    seedVerificationOptions([...$vector['assertions'][0]['options'], 'rpId' => 'metricool.test']);
    $this->postJson('/passkeys/login', ['credential' => $vector['assertions'][0]['credential']])->assertStatus(422);

    $this->assertGuest();
    expect(AuditLog::where('action', 'auth.passkey.login_failed')->sole()->detail)->toBe('verification_failed');
});

test('PasskeyCeremony: el alta con otro origen, otro RP ID o otro reto se rechaza y no guarda nada', function () {
    $user = User::factory()->create();
    $vector = passkeyVector();

    config(['passkeys.allowed_origins' => ['http://localhost:9999']]);
    registerVectorPasskey($user)->assertStatus(422);

    config(['passkeys.allowed_origins' => [$vector['origin']]]);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    seedRegistrationOptions([...$vector['registration']['options'], 'rp' => ['name' => 'metricool.test', 'id' => 'metricool.test']]);
    $this->postJson('/user/passkeys', ['name' => 'X', 'credential' => $vector['registration']['credential']])->assertStatus(422);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    seedRegistrationOptions([...$vector['registration']['options'], 'challenge' => $vector['assertions'][0]['options']['challenge']]);
    $this->postJson('/user/passkeys', ['name' => 'X', 'credential' => $vector['registration']['credential']])->assertStatus(422);

    expect($user->passkeys()->count())->toBe(0);
});

test('PasskeyCeremony: el mismo autenticador no se registra en otra cuenta', function () {
    userWithVectorPasskey();
    $intruder = User::factory()->create();

    registerVectorPasskey($intruder)->assertStatus(422);

    expect($intruder->passkeys()->count())->toBe(0);
});

test('PasskeyCeremony: repetir una assertion ya usada (mismo contador) se rechaza y se audita sign_count_regression', function () {
    $user = userWithVectorPasskey();
    loginWithVector(0)->assertOk();
    $this->post('/logout');
    $this->flushSession();

    loginWithVector(0)->assertStatus(422);

    $this->assertGuest();
    $row = AuditLog::where('action', 'auth.passkey.login_failed')->sole();
    expect($row->detail)->toBe('sign_count_regression')->and($row->user_id)->toBe($user->id)->and($row->outcome)->toBe('denied');
});

test('PasskeyCeremony: un contador que retrocede con valores distintos de 0 se rechaza y no cambia el guardado', function () {
    $user = userWithVectorPasskey();
    loginWithVector(2)->assertOk(); // contador 4
    $this->post('/logout');
    $this->flushSession();

    loginWithVector(1)->assertStatus(422); // contador 3 < 4

    $this->assertGuest();
    expect(AuditLog::where('action', 'auth.passkey.login_failed')->sole()->detail)->toBe('sign_count_regression')
        ->and($user->passkeys()->sole()->credential['counter'])->toBe(passkeyVector()['counters'][2]);
});

test('PasskeyCeremony: la regla del contador tolera 0 -> 0 (passkeys sincronizadas) y rechaza el resto de retrocesos', function () {
    $vector = passkeyVector();
    $user = userWithVectorPasskey();
    $stored = $user->passkeys()->sole()->credential;

    // Assertion real con el contador de authenticatorData forzado a `$signCount` (la firma no se comprueba en este paso).
    $assertionWithCounter = function (int $signCount) use ($vector): AuthenticatorAssertionResponse {
        $credential = $vector['assertions'][0]['credential'];
        $data = base64_decode(strtr($credential['response']['authenticatorData'], '-_', '+/').'==');
        $credential['response']['authenticatorData'] = base64_encode(substr($data, 0, 33).pack('N', $signCount).substr($data, 37));

        return WebAuthn::fromJson(json_encode($credential), PublicKeyCredential::class)->response;
    };
    $record = fn (int $counter) => WebAuthn::fromJson(json_encode([...$stored, 'counter' => $counter]), CredentialRecord::class);
    $options = WebAuthn::fromJson(json_encode($vector['assertions'][0]['options']), PublicKeyCredentialRequestOptions::class);
    $step = new CheckCounter(new ThrowExceptionIfInvalid);
    $check = fn (int $storedCounter, int $responseCounter) => $step->process($record($storedCounter), $assertionWithCounter($responseCounter), $options, null, 'localhost');

    // 0 -> 0: sin comprobacion de contador.
    $check(0, 0);
    // Avanza normalmente.
    $check(0, 1);
    $check(5, 6);
    // Retrocesos / repeticiones con algun valor distinto de 0.
    foreach ([[5, 5], [5, 4], [5, 0]] as [$storedCounter, $responseCounter]) {
        expect(fn () => $check($storedCounter, $responseCounter))->toThrow(CounterException::class);
    }
});

test('PasskeyCeremony: una passkey borrada deja de servir aunque la firma sea valida', function () {
    $user = userWithVectorPasskey();
    $user->passkeys()->sole()->delete();

    loginWithVector(0)->assertStatus(422);

    $this->assertGuest();
    $row = AuditLog::where('action', 'auth.passkey.login_failed')->sole();
    expect($row->detail)->toBe('unknown_credential')->and($row->user_id)->toBeNull();
});

test('PasskeyCeremony: el vector solo contiene datos publicos de una credencial de prueba', function () {
    $raw = file_get_contents(base_path('tests/Fixtures/passkeys/vector.json'));

    expect($raw)->not->toMatch('/private|secret|pkcs8|BEGIN [A-Z ]*KEY/i');
    $vector = passkeyVector();
    expect($vector['origin'])->toBe('http://localhost:8080')->and($vector['rpId'])->toBe('localhost')
        ->and($vector['assertions'])->toHaveCount(3)
        ->and($vector['registration']['options']['user']['name'])->toBe('vector@example.test');
});
