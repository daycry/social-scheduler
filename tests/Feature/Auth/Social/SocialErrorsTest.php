<?php

use App\Models\AuditLog;
use App\Models\SocialIdentity;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
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
        ->assertSessionHasErrors(['email' => 'Has cancelado el inicio de sesión con Google.']);

    $this->get('/auth/facebook/callback?error=access_denied&error_reason=user_denied')
        ->assertRedirect('/login#')
        ->assertSessionHasErrors(['email' => 'Has cancelado el inicio de sesión con Facebook.']);

    $this->assertGuest();
    expect(AuditLog::where('action', 'auth.social.login')->where('detail', 'like', '%cancelled%')->count())->toBe(2);
});

test('CA-09: state invalido o caducado vuelve a /login sin 500', function () {
    socialDriverThrows('google', new InvalidStateException);

    $this->get('/auth/google/callback?code=abc&state=malo')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'La sesión de inicio ha caducado, inténtalo de nuevo.']);

    $this->assertGuest();
    expect(AuditLog::where('action', 'auth.social.login')->sole()->detail)->toContain('invalid_state');
});

test('CA-09: proveedor caido vuelve a /login y no registra el mensaje de la excepcion (lleva el code)', function () {
    Log::spy();
    socialDriverThrows('facebook', new RuntimeException('Client error: GET https://graph.facebook.com/?code=SECRETCODE'));

    $this->get('/auth/facebook/callback?code=SECRETCODE&state=x')
        ->assertRedirect('/login#')
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

test('B4: con el driver real, un state distinto del de la sesion no inicia sesion ni vincula', function () {
    enableSocialProviders();
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g-77', 'linked_at' => now()]);
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['access_token' => 'tok', 'token_type' => 'bearer'])),
        new Response(200, [], json_encode(['sub' => 'g-77', 'email' => 'ana@example.com', 'email_verified' => true])),
    ]))]);
    // Se construye al atender la peticion (con su sesion real) y con el cliente HTTP simulado.
    Socialite::extend('google-login', fn () => Socialite::buildProvider(GoogleProvider::class, (array) config('services.google_login'))->setHttpClient($client));

    $this->withSession(['state' => 'el-de-la-sesion'])->get('/auth/google/callback?code=abc&state=otro')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'La sesión de inicio ha caducado, inténtalo de nuevo.']);

    $this->assertGuest();
    expect(SocialIdentity::count())->toBe(1);
});

test('B6: un fallo en modo vincular se audita como social.linked con el usuario autenticado', function () {
    $user = User::factory()->create();
    test()->actingAs($user)->withSession(['auth.password_confirmed_at' => time(), 'social.intent' => 'link']);

    $this->get('/auth/google/callback?error=access_denied')->assertRedirect(route('profile.edit', absolute: false));

    $log = AuditLog::where('action', 'social.linked')->sole();
    expect($log->user_id)->toBe($user->id)->and($log->outcome)->toBe('denied')->and($log->detail)->toContain('cancelled');
});
