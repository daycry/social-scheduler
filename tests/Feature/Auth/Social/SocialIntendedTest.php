<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FakePasskeys;

require_once __DIR__.'/SocialTestHelpers.php';

beforeEach(fn () => enableSocialProviders());

function linkedGoogleUser(string $sub = 'g-1', ?User $user = null): User
{
    $user ??= User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => $sub, 'linked_at' => now()]);

    return $user;
}

test('url.intended sobrevive al viaje al proveedor, tambien hacia /oauth/authorize', function () {
    $user = linkedGoogleUser();
    $authorize = '/oauth/authorize?response_type=code&client_id=abc&state=xyz';

    // Sin sesion, auth guarda el destino y manda a /login.
    $this->get($authorize)->assertRedirect('/login');
    $intended = session('url.intended');
    expect($intended)->toContain('/oauth/authorize?');

    // El viaje al proveedor (redirect) y la vuelta (GET con cookie SameSite=lax) no lo pierden.
    Socialite::fake('google-login');
    $this->get('/auth/google/redirect')->assertRedirect();
    expect(session('url.intended'))->toBe($intended);

    socialProviderReturns('google', fakeSocialUser('g-1', $user->email, true));
    $this->get('/auth/google/callback?code=x&state=y')->assertRedirect($intended);
    $this->assertAuthenticatedAs($user);
});

test('url.intended sobrevive tambien al reto 2FA del login social', function () {
    $user = linkedGoogleUser('g-2', socialUserWith2fa());
    socialProviderReturns('google', fakeSocialUser('g-2', $user->email, true));

    $this->withSession(['url.intended' => url('/oauth/authorize?client_id=abc')])->get('/auth/google/callback')->assertRedirect(route('two-factor.challenge'));
    expect(session('url.intended'))->toBe(url('/oauth/authorize?client_id=abc'));

    $g = new Google2FA;
    $this->post('/two-factor-challenge', ['code' => $g->oathTotp($user->two_factor_secret, $g->getTimestamp())])->assertRedirect(url('/oauth/authorize?client_id=abc'));
});

test('la cookie de sesion es SameSite=lax y el callback es GET: vuelve del proveedor con la sesion', function () {
    expect(config('session.same_site'))->toBe('lax');
    expect(Route::getRoutes()->getByName('social.callback')->methods())->toContain('GET')->not->toContain('POST');
});

test('el login social no crea sesion recordada (remember=false)', function () {
    linkedGoogleUser('g-3');
    socialProviderReturns('google', fakeSocialUser('g-3'));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('dashboard', absolute: false));
    $names = collect($response->headers->getCookies())->map->getName()->all();
    expect(collect($names)->contains(fn ($n) => str_starts_with($n, 'remember_web_')))->toBeFalse();
});

test('con 2FA, login.remember queda en false', function () {
    linkedGoogleUser('g-4', socialUserWith2fa());
    socialProviderReturns('google', fakeSocialUser('g-4'));

    $this->get('/auth/google/callback')->assertRedirect(route('two-factor.challenge'));

    expect(session('login.remember'))->toBeFalse()->and(session('login.via'))->toBe('google');
});

test('la contrasena sigue igual: remember crea la cookie y no hay login.via sin 2FA', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $names = collect($response->headers->getCookies())->map->getName()->all();
    expect(collect($names)->contains(fn ($n) => str_starts_with($n, 'remember_web_')))->toBeTrue();
    expect(session()->has('login.via'))->toBeFalse();
});

test('la contrasena con 2FA sigue llevando al reto con via=password', function () {
    $user = socialUserWith2fa();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));

    expect(session('login.via'))->toBe('password');
    $this->assertGuest();
});

test('la passkey sigue entrando sin reto aunque haya un login social a medias', function () {
    FakePasskeys::bind();
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-social');
    $this->withSession(['login.id' => 999, 'login.via' => 'google', 'login.at' => time()]);

    $this->getJson('/passkeys/login/options')->assertOk();
    $this->postJson('/passkeys/login', ['credential' => FakePasskeys::credential('cred-social')])->assertOk();

    $this->assertAuthenticatedAs($user);
    expect(session()->has('login.via'))->toBeFalse();
});
