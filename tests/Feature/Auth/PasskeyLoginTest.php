<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePasskeys;

beforeEach(function () {
    FakePasskeys::bind();
    RateLimiter::clear('passkey-login:127.0.0.1');
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

test('PasskeyLogin: las rutas son guest y usan su limitador propio de 10 por minuto', function () {
    foreach (['passkeys.login.options', 'passkeys.login'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('guest')->toContain('throttle:passkey-login');
    }

    // El limitador es de 10/min y por IP.
    $limit = RateLimiter::limiter('passkey-login')(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));
    expect($limit->maxAttempts)->toBe(10)->and($limit->key)->toBe('passkey-login:203.0.113.9');
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
        ->and($rows[1]->user_id)->toBe($user2->id)->and($rows[1]->detail)->toBe('verification_failed')
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

test('PasskeyLogin CA-21: la peticion 11 en un minuto desde la misma IP recibe 429', function () {
    for ($i = 1; $i <= 10; $i++) {
        $this->getJson('/passkeys/login/options')->assertOk();
    }

    $this->getJson('/passkeys/login/options')->assertStatus(429);
    // El contador es comun a las dos URL.
    $this->postJson('/passkeys/login', ['credential' => FakePasskeys::credential('x')])->assertStatus(429);
});

test('PasskeyLogin: el login con contrasena no consume el limitador de passkeys', function () {
    for ($i = 1; $i <= 10; $i++) {
        $this->getJson('/passkeys/login/options')->assertOk();
    }
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
});
