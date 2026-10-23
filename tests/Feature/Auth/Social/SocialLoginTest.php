<?php

use App\Models\AuditLog;
use App\Models\SocialIdentity;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use PragmaRX\Google2FA\Google2FA;

require_once __DIR__.'/SocialTestHelpers.php';

const SOCIAL_REJECT_GOOGLE = 'No hemos podido iniciar sesión con Google. Entra con tu contraseña y vincula Google desde tu perfil.';

beforeEach(fn () => enableSocialProviders());

test('CA-10: sin credenciales el proveedor esta desactivado y redirect y callback dan 404', function () {
    config(['services.google_login.client_id' => '', 'services.facebook_login.client_secret' => null]);

    $this->get('/auth/google/redirect')->assertNotFound();
    $this->get('/auth/google/callback')->assertNotFound();
    $this->get('/auth/facebook/redirect')->assertNotFound();
    $this->get('/auth/facebook/callback')->assertNotFound();
});

test('un proveedor desconocido da 404', function () {
    $this->get('/auth/github/redirect')->assertNotFound();
});

test('el redirect va al proveedor y deja la intencion de login en sesion', function () {
    Socialite::fake('google-login');

    $this->get('/auth/google/redirect')->assertRedirect('https://socialite.fake/google-login/authorize');
    expect(session('social.intent'))->toBe('login');
});

test('CA-01: identidad vinculada entra sin 2FA y audita auth.social.login OK', function () {
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g-1', 'linked_at' => now()]);
    socialProviderReturns('google', fakeSocialUser('g-1', 'otro@example.com', true));

    $this->get('/auth/google/callback')->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    $row = AuditLog::where('action', 'auth.social.login')->firstOrFail();
    expect($row->outcome)->toBe('ok')->and($row->channel)->toBe('web')->and($row->user_id)->toBe($user->id);
    expect(SocialIdentity::first()->last_login_at)->not->toBeNull();
});

test('CA-02: Google verificado vincula solo por email y audita social.linked', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    socialProviderReturns('google', fakeSocialUser('g-2', 'Ana@Example.com', true));

    $this->get('/auth/google/callback')->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    $identity = SocialIdentity::firstOrFail();
    expect($identity->user_id)->toBe($user->id)->and($identity->provider)->toBe('google')->and($identity->provider_user_id)->toBe('g-2');
    expect(AuditLog::where('action', 'social.linked')->where('outcome', 'ok')->count())->toBe(1);
});

test('CA-03: Google con email_verified falso, ausente o no booleano se rechaza sin identidad ni sesion', function (mixed $verified) {
    User::factory()->create(['email' => 'ana@example.com']);
    socialProviderReturns('google', fakeSocialUser('g-3', 'ana@example.com', $verified));

    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors(['email' => SOCIAL_REJECT_GOOGLE]);

    $this->assertGuest();
    expect(SocialIdentity::count())->toBe(0);
    expect(AuditLog::where('action', 'auth.social.login')->where('outcome', 'denied')->count())->toBe(1);
})->with([[false], [null], ['false'], ['1']]);

test('CA-03: Google sin email se rechaza', function () {
    User::factory()->create();
    socialProviderReturns('google', fakeSocialUser('g-4', null, true));

    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors(['email' => SOCIAL_REJECT_GOOGLE]);

    $this->assertGuest();
    expect(SocialIdentity::count())->toBe(0);
});

test('Google verificado pero con la cuenta local sin verificar no vincula', function () {
    User::factory()->unverified()->create(['email' => 'ana@example.com']);
    socialProviderReturns('google', fakeSocialUser('g-5', 'ana@example.com', true));

    $this->get('/auth/google/callback')->assertRedirect('/login');

    $this->assertGuest();
    expect(SocialIdentity::count())->toBe(0);
});

test('CA-04: sin cuenta local no se crea usuario, con REGISTRATION_ENABLED true o false', function (bool $registration) {
    config(['app.registration_enabled' => $registration]);
    $before = User::count();
    socialProviderReturns('google', fakeSocialUser('g-6', 'nadie@example.com', true));

    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors(['email' => SOCIAL_REJECT_GOOGLE]);

    expect(User::count())->toBe($before)->and(SocialIdentity::count())->toBe(0);
    $this->assertGuest();
})->with([[true], [false]]);

test('el mensaje es identico exista o no el email (no enumera)', function () {
    User::factory()->unverified()->create(['email' => 'existe@example.com']);

    socialProviderReturns('google', fakeSocialUser('g-7', 'existe@example.com', true));
    $this->get('/auth/google/callback')->assertSessionHasErrors('email');
    $first = session('errors')->first('email');

    $this->flushSession();
    socialProviderReturns('google', fakeSocialUser('g-8', 'noexiste@example.com', true));
    $this->get('/auth/google/callback')->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toBe($first);
});

test('CA-05: Facebook sin identidad vinculada se rechaza aunque el email coincida', function () {
    User::factory()->create(['email' => 'ana@example.com']);
    socialProviderReturns('facebook', fakeSocialUser('fb-1', 'ana@example.com'));

    $this->get('/auth/facebook/callback')->assertRedirect('/login')->assertSessionHasErrors(['email' => 'No hemos podido iniciar sesión con Facebook. Entra con tu contraseña y vincula Facebook desde tu perfil.']);

    $this->assertGuest();
    expect(SocialIdentity::count())->toBe(0);
});

test('Facebook con identidad vinculada entra', function () {
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'facebook', 'provider_user_id' => 'fb-2', 'linked_at' => now()]);
    socialProviderReturns('facebook', fakeSocialUser('fb-2', null));

    $this->get('/auth/facebook/callback')->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('CA-06: con 2FA no hay sesion, va al reto y tras el codigo llega a intended', function () {
    $user = socialUserWith2fa();
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g-9', 'linked_at' => now()]);
    socialProviderReturns('google', fakeSocialUser('g-9', $user->email, true));

    $this->withSession(['url.intended' => url('/brands')])->get('/auth/google/callback')->assertRedirect(route('two-factor.challenge'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id)->and(session('login.remember'))->toBeFalse();

    $g = new Google2FA;
    $this->post('/two-factor-challenge', ['code' => $g->oathTotp($user->two_factor_secret, $g->getTimestamp())])->assertRedirect(url('/brands'));

    $this->assertAuthenticatedAs($user);
});

test('la identidad bloqueada no entra', function () {
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g-10', 'linked_at' => now(), 'blocked_at' => now()]);
    socialProviderReturns('google', fakeSocialUser('g-10', $user->email, true));

    $this->get('/auth/google/callback')->assertRedirect('/login');

    $this->assertGuest();
});
