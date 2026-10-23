<?php

use App\Models\AuditLog;
use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

require_once __DIR__.'/SocialTestHelpers.php';

beforeEach(fn () => enableSocialProviders());

/** El driver lanza esta excepcion al canjear el code. */
function socialDriverThrows(string $provider, Throwable $e): void
{
    Socialite::fake($provider.'-login', fn () => throw $e);
}

test('CA-09: el usuario cancela en el proveedor y vuelve a /login con mensaje legible', function () {
    $this->get('/auth/google/callback?error=access_denied')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'Cancelaste el inicio de sesión con Google.']);

    $this->get('/auth/facebook/callback?error=access_denied&error_reason=user_denied')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'Cancelaste el inicio de sesión con Facebook.']);

    $this->assertGuest();
    expect(AuditLog::where('action', 'auth.social.login')->where('detail', 'like', '%cancelled%')->count())->toBe(2);
});

test('CA-09: state invalido o caducado vuelve a /login sin 500', function () {
    socialDriverThrows('google', new InvalidStateException);

    $this->get('/auth/google/callback?code=abc&state=malo')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'La sesión con Google caducó. Vuelve a intentarlo.']);

    $this->assertGuest();
    expect(AuditLog::where('action', 'auth.social.login')->sole()->detail)->toContain('invalid_state');
});

test('CA-09: proveedor caido vuelve a /login y no registra el mensaje de la excepcion (lleva el code)', function () {
    Log::spy();
    socialDriverThrows('facebook', new RuntimeException('Client error: GET https://graph.facebook.com/?code=SECRETCODE'));

    $this->get('/auth/facebook/callback?code=SECRETCODE&state=x')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx = []) => $m === 'social.login.provider_error' && ! str_contains(json_encode($ctx), 'SECRETCODE'))->once();
    expect(AuditLog::where('detail', 'like', '%SECRETCODE%')->count())->toBe(0);
});

test('un fallo no crea identidad ni usuario', function () {
    $before = User::count();
    socialDriverThrows('google', new InvalidStateException);

    $this->get('/auth/google/callback?code=abc');

    expect(SocialIdentity::count())->toBe(0)->and(User::count())->toBe($before);
});

test('CA-11: la peticion 21 del callback en un minuto responde 429', function () {
    for ($i = 1; $i <= 20; $i++) {
        $this->get('/auth/google/callback?error=access_denied')->assertRedirect('/login');
    }

    $this->get('/auth/google/callback?error=access_denied')->assertStatus(429);
});
