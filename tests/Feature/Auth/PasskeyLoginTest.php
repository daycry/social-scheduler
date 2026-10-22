<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Passkeys\PasskeyGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePasskeys;

beforeEach(function () {
    FakePasskeys::bind();
    RateLimiter::clear('passkey-login:127.0.0.1');
    RateLimiter::clear('passkey-login-options:127.0.0.1');
});

/** Pide las opciones (guarda el reto en sesion) y envia la assertion de la credencial dada. */
function passkeyLogin(string $credentialId, array $extra = []): TestResponse
{
    test()->getJson('/passkeys/login/options')->assertOk();

    return test()->postJson('/passkeys/login', ['credential' => FakePasskeys::credential($credentialId)] + $extra);
}

function userWithTwoFactor(): User
{
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

    return $user;
}

test('PasskeyLogin: las rutas son guest y cada una usa su limitador por IP (envio 10/min, opciones 30/min)', function () {
    foreach (['passkeys.login.options' => 'passkey-login-options', 'passkeys.login' => 'passkey-login'] as $name => $limiter) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('guest')->toContain("throttle:{$limiter}");
    }

    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);
    $send = RateLimiter::limiter('passkey-login')($request);
    $options = RateLimiter::limiter('passkey-login-options')($request);
    expect($send->maxAttempts)->toBe(10)->and($send->key)->toBe('passkey-login:203.0.113.9')
        ->and($options->maxAttempts)->toBe(30)->and($options->key)->toBe('passkey-login-options:203.0.113.9');
});

test('PasskeyLogin CA-17: entra al dashboard sin contrasena ni reto 2FA y audita auth.passkey.login', function () {
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-a');

    $sessionBefore = session()->getId();
    $response = passkeyLogin('cred-a')->assertOk()->assertJsonPath('redirect', url('/dashboard'));

    expect($response->headers->has('Location'))->toBeFalse();
    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($sessionBefore);

    $row = AuditLog::where('action', 'auth.passkey.login')->sole();
    expect($row->user_id)->toBe($user->id)->and($row->channel)->toBe('web')->and($row->outcome)->toBe('ok');
    expect($user->passkeys()->first()->last_used_at)->not->toBeNull();
    $this->get('/dashboard')->assertOk();
});

test('PasskeyLogin CA-17: con 2FA activo tampoco pide el reto', function () {
    $user = userWithTwoFactor();
    FakePasskeys::seed($user, 'Mia', 'cred-2fa');

    passkeyLogin('cred-2fa')->assertOk()->assertJsonPath('redirect', url('/dashboard'));

    $this->assertAuthenticatedAs($user);
    $this->get('/dashboard')->assertOk();
});

test('PasskeyLogin: respeta la URL pretendida y limpia un reto 2FA pendiente de un login a medias', function () {
    $user = userWithTwoFactor();
    FakePasskeys::seed($user, 'Mia', 'cred-i');
    $this->withSession(['url.intended' => url('/profile'), 'login.id' => 999, 'login.remember' => true, 'login.at' => time()]);

    passkeyLogin('cred-i', ['remember' => true])->assertOk()->assertJsonPath('redirect', url('/profile'));

    expect(session()->has('login.id'))->toBeFalse()->and(session()->has('login.at'))->toBeFalse();
    $this->assertAuthenticatedAs($user);
});

test('PasskeyLogin CA-18: passkey borrada o desconocida no entra, con el mismo mensaje, y se audita sin revelar la cuenta', function () {
    $user = User::factory()->create();
    $passkey = FakePasskeys::seed($user, 'Mia', 'cred-b');
    $passkey->delete();

    $unknown = passkeyLogin('cred-b')->assertStatus(422);
    $this->assertGuest();

    $user2 = User::factory()->create();
    FakePasskeys::seed($user2, 'Otra', 'cred-c');
    FakePasskeys::$verifyFailure = 'crypto';
    $known = passkeyLogin('cred-c')->assertStatus(422);
    $this->assertGuest();

    // Mismo cuerpo exacto exista o no la cuenta: no es un oraculo.
    expect($known->json())->toBe($unknown->json())->and($known->json())->toHaveKey('message');

    $rows = AuditLog::where('action', 'auth.passkey.login_failed')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->user_id)->toBeNull()->and($rows[0]->detail)->toBe('unknown_credential')
        // P14: una firma que no verifica no se atribuye al dueño de la credencial presentada.
        ->and($rows[1]->user_id)->toBeNull()->and($rows[1]->detail)->toBe('verification_failed')
        ->and($rows->every(fn ($r) => $r->outcome === 'denied' && $r->channel === 'web'))->toBeTrue();
    expect(AuditLog::where('action', 'auth.passkey.login')->count())->toBe(0);
});

test('PasskeyLogin: un contador de firmas que retrocede se rechaza y se audita como sign_count_regression', function () {
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Clonada', 'cred-d');
    FakePasskeys::$verifyFailure = 'counter';

    passkeyLogin('cred-d')->assertStatus(422);

    $this->assertGuest();
    $row = AuditLog::where('action', 'auth.passkey.login_failed')->sole();
    expect($row->detail)->toBe('sign_count_regression')->and($row->user_id)->toBe($user->id);
});

test('PasskeyLogin: sin pedir antes las opciones, o con el reto ya usado, no entra', function () {
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-e');

    $this->postJson('/passkeys/login', ['credential' => FakePasskeys::credential('cred-e')])->assertStatus(422);
    $this->assertGuest();

    passkeyLogin('cred-e')->assertOk();
    $this->post('/logout');
    // El reto de la sesion anterior no se puede reutilizar.
    $this->postJson('/passkeys/login', ['credential' => FakePasskeys::credential('cred-e')])->assertStatus(422);
    $this->assertGuest();
});

test('PasskeyLogin: una respuesta mal formada es un 422 generico', function () {
    $this->getJson('/passkeys/login/options')->assertOk();
    $this->postJson('/passkeys/login', ['credential' => ['id' => 'x']])->assertStatus(422);
    $this->postJson('/passkeys/login', [])->assertStatus(422);
    $this->assertGuest();
});

test('PasskeyLogin: las opciones piden verificacion de usuario y no filtran credenciales', function () {
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-f');

    $this->getJson('/passkeys/login/options')->assertOk()
        ->assertJsonPath('options.userVerification', 'required')
        ->assertJsonPath('options.rpId', config('passkeys.relying_party_id'))
        ->assertJsonMissingPath('options.allowCredentials.0');
});

test('PasskeyLogin: un usuario con sesion no usa estas rutas (guest)', function () {
    $this->actingAs(User::factory()->create())->getJson('/passkeys/login/options')->assertRedirect();
});

test('PasskeyLogin CA-21: el envio numero 11 en un minuto desde la misma IP recibe 429', function () {
    $this->getJson('/passkeys/login/options')->assertOk();

    for ($i = 1; $i <= 10; $i++) {
        $this->postJson('/passkeys/login', ['credential' => FakePasskeys::credential('x')])->assertStatus(422);
    }

    $this->postJson('/passkeys/login', ['credential' => FakePasskeys::credential('x')])->assertStatus(429);
});

test('PasskeyLogin P2: el autofill (GET de opciones) no gasta el cupo del envio, y tiene el suyo de 30/min', function () {
    for ($i = 1; $i <= 30; $i++) {
        $this->getJson('/passkeys/login/options')->assertOk();
    }
    $this->getJson('/passkeys/login/options')->assertStatus(429);

    // El envio sigue disponible (no 429): su contador es otro.
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-p2');
    $this->postJson('/passkeys/login', ['credential' => FakePasskeys::credential('cred-p2')])->assertOk();
});

test('PasskeyLogin P3: autofill y boton piden opciones a la vez y ninguno invalida al otro (reto por ceremonia)', function () {
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-p3');

    $autofill = $this->getJson('/passkeys/login/options')->json('options.challenge');
    $button = $this->getJson('/passkeys/login/options')->json('options.challenge');
    expect($autofill)->not->toBe($button);

    // El boton entra primero con su reto; el del autofill sigue vigente en la sesion (aunque esta se regenera al entrar).
    expect(array_keys(session('passkey.verification_options')))->toBe([$autofill, $button]);
    $this->postJson('/passkeys/login', ['credential' => FakePasskeys::credential('cred-p3', challenge: $button)])->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('PasskeyLogin P3: el reto del autofill sigue valiendo despues de consumir el del boton', function () {
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-p3b');
    $gateway = app(PasskeyGateway::class);

    $autofill = $gateway->loginOptions()['challenge'];
    $button = $gateway->loginOptions()['challenge'];
    $gateway->authenticate(FakePasskeys::credential('cred-p3b', challenge: $button));

    expect(array_keys(session('passkey.verification_options')))->toBe([$autofill]);
    $gateway->authenticate(FakePasskeys::credential('cred-p3b', challenge: $autofill));
});

test('PasskeyLogin P3: la sesion solo guarda un punado de ceremonias vigentes', function () {
    for ($i = 1; $i <= 8; $i++) {
        $this->getJson('/passkeys/login/options')->assertOk();
    }

    expect(session('passkey.verification_options'))->toHaveCount(5);
});

test('PasskeyLogin P8: un reto caducado en el servidor no vale aunque la sesion siga viva', function () {
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-p8');
    $this->getJson('/passkeys/login/options')->assertOk();
    $credential = FakePasskeys::credential('cred-p8');

    $this->travel(301)->seconds();
    $this->postJson('/passkeys/login', ['credential' => $credential])->assertStatus(422);
    $this->assertGuest();
    expect(AuditLog::where('action', 'auth.passkey.login_failed')->sole()->detail)->toBe('session_expired');

    // Dentro del plazo si entra.
    $this->travelBack();
    $this->getJson('/passkeys/login/options')->assertOk();
    $this->travel(290)->seconds();
    $this->postJson('/passkeys/login', ['credential' => FakePasskeys::credential('cred-p8')])->assertOk();
});

test('PasskeyLogin P6: una attestation enviada al login se audita como invalid_response, no como credencial desconocida', function () {
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-p6');
    $this->getJson('/passkeys/login/options')->assertOk();
    $attestation = FakePasskeys::credential('cred-p6', 'attestation', challenge: array_key_first(session('passkey.verification_options')));

    $this->postJson('/passkeys/login', ['credential' => $attestation])->assertStatus(422);

    $row = AuditLog::where('action', 'auth.passkey.login_failed')->sole();
    expect($row->detail)->toBe('invalid_response')->and($row->user_id)->toBeNull();
});

test('PasskeyLogin P1: un error inesperado queda en el log sin material de la credencial y no culpa al dispositivo', function () {
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-p1');
    FakePasskeys::$verifyFailure = 'server';
    Log::spy();

    passkeyLogin('cred-p1')->assertStatus(500)
        ->assertJsonPath('code', 'server_error')
        ->assertJsonPath('message', 'No se pudo completar la passkey por un problema del servidor; inténtalo más tarde.');

    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => str_contains($message, 'Passkeys')
        && $context['exception'] === RuntimeException::class
        && ! str_contains(json_encode($context), 'cred-p1'));
    $this->assertGuest();
});

test('PasskeyLogin: el login con contrasena no consume el limitador de passkeys', function () {
    $this->getJson('/passkeys/login/options')->assertOk();
    for ($i = 1; $i <= 10; $i++) {
        $this->postJson('/passkeys/login', ['credential' => FakePasskeys::credential('x')])->assertStatus(422);
    }
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
});

test('PasskeyLogin N3: las rutas que leen y escriben los retos en sesion se serializan por sesion (block)', function () {
    foreach (['passkeys.login.options', 'passkeys.login', 'passkeys.options', 'passkeys.store'] as $name) {
        $route = app('router')->getRoutes()->getByName($name);
        expect($route->locksFor())->toBe(5)->and($route->waitsFor())->toBe(5);
    }
});
