<?php

use App\Models\AuditLog;
use App\Models\SocialIdentity;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

require_once __DIR__.'/SocialTestHelpers.php';

beforeEach(fn () => enableSocialProviders());

/** Sesion iniciada con la contrasena confirmada hace un momento. */
function confirmedSession(User $user): void
{
    test()->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
}

test('vincular y desvincular exigen password.confirm', function () {
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'facebook', 'provider_user_id' => 'fb-1', 'linked_at' => now()]);

    $this->actingAs($user)->post('/user/social/facebook/link')->assertRedirect(route('password.confirm'));
    $this->actingAs($user)->delete('/user/social/facebook')->assertRedirect(route('password.confirm'));

    expect(SocialIdentity::where('blocked_at', null)->count())->toBe(1);
});

test('vincular y desvincular exigen sesion', function () {
    $this->post('/user/social/facebook/link')->assertRedirect('/login');
    $this->delete('/user/social/facebook')->assertRedirect('/login');
});

test('vincular con el proveedor desactivado da 404', function () {
    config(['services.facebook_login.client_id' => '']);
    confirmedSession(User::factory()->create());

    $this->post('/user/social/facebook/link')->assertNotFound();
});

test('vincular deja social.intent=link y va al proveedor; por Inertia fuerza la navegacion completa', function () {
    $user = User::factory()->create();
    Socialite::fake('facebook-login');
    confirmedSession($user);

    $this->post('/user/social/facebook/link')->assertRedirect('https://socialite.fake/facebook-login/authorize');
    expect(session('social.intent'))->toBe('link');

    $this->post('/user/social/facebook/link', [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://socialite.fake/facebook-login/authorize');
});

test('CA-07: con la contrasena confirmada, el callback vincula Facebook y aparece en el perfil', function () {
    $user = User::factory()->create();
    confirmedSession($user);
    socialProviderReturns('facebook', fakeSocialUser('fb-10', 'ana@example.com'));

    $this->withSession(['social.intent' => 'link'])->get('/auth/facebook/callback?code=x&state=y')
        ->assertRedirect(route('profile.edit', absolute: false).'#')
        ->assertSessionHas('status', 'Facebook vinculado a tu cuenta.');

    $identity = SocialIdentity::firstOrFail();
    expect($identity->user_id)->toBe($user->id)->and($identity->provider)->toBe('facebook')->and($identity->email)->toBe('ana@example.com');
    expect(AuditLog::where('action', 'social.linked')->where('outcome', 'ok')->where('user_id', $user->id)->count())->toBe(1);

    $this->get('/profile')->assertInertia(fn ($page) => $page
        ->where('socialAccounts.1.provider', 'facebook')
        ->where('socialAccounts.1.linked', true)
        ->where('socialAccounts.1.email', 'ana@example.com')
        ->where('socialAccounts.0.linked', false));
});

test('CA-07: una identidad que ya es de otro usuario da error y no cambia nada', function () {
    $owner = User::factory()->create();
    $owner->socialIdentities()->create(['provider' => 'facebook', 'provider_user_id' => 'fb-20', 'linked_at' => now()]);
    $user = User::factory()->create();
    confirmedSession($user);
    socialProviderReturns('facebook', fakeSocialUser('fb-20'));

    $this->withSession(['social.intent' => 'link'])->get('/auth/facebook/callback')
        ->assertSessionHasErrors(['social' => 'Esa cuenta de Facebook no se puede vincular: ya está en uso.']);

    expect(SocialIdentity::count())->toBe(1)->and(SocialIdentity::first()->user_id)->toBe($owner->id);
    expect($user->socialIdentities()->count())->toBe(0);
});

test('un segundo Google activo distinto no se vincula', function () {
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g-1', 'linked_at' => now()]);
    confirmedSession($user);
    socialProviderReturns('google', fakeSocialUser('g-2', 'x@example.com', true));

    $this->withSession(['social.intent' => 'link'])->get('/auth/google/callback')->assertSessionHasErrors('social');

    expect($user->socialIdentities()->count())->toBe(1);
});

test('el callback de vincular sin sesion no vincula', function () {
    socialProviderReturns('facebook', fakeSocialUser('fb-30'));

    $this->withSession(['social.intent' => 'link'])->get('/auth/facebook/callback')->assertRedirect('/login#');

    expect(SocialIdentity::count())->toBe(0);
    $this->assertGuest();
});

test('cancelar o state invalido al vincular vuelve al perfil, sin 500', function () {
    $user = User::factory()->create();
    confirmedSession($user);

    $this->withSession(['social.intent' => 'link'])->get('/auth/google/callback?error=access_denied')
        ->assertRedirect(route('profile.edit', absolute: false))
        ->assertSessionHasErrors(['social' => 'Cancelaste el inicio de sesión con Google.']);
});

test('CA-08: desvincular bloquea la identidad, audita social.unlinked y el login con ella no entra', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g-40', 'linked_at' => now()]);
    confirmedSession($user);

    $this->from('/profile')->delete('/user/social/google')->assertRedirect('/profile')->assertSessionHas('status', 'Google desvinculado.');

    $identity = SocialIdentity::firstOrFail();
    expect($identity->isBlocked())->toBeTrue();
    expect(AuditLog::where('action', 'social.unlinked')->where('user_id', $user->id)->count())->toBe(1);

    auth()->logout();
    socialProviderReturns('google', fakeSocialUser('g-40', 'ana@example.com', true));
    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('CA-08: tras desvincular, Google con otra cuenta y el mismo email tampoco se vincula solo', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g-41', 'linked_at' => now(), 'blocked_at' => now()]);
    socialProviderReturns('google', fakeSocialUser('g-nueva', 'ana@example.com', true));

    $this->get('/auth/google/callback')->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(SocialIdentity::where('provider_user_id', 'g-nueva')->exists())->toBeFalse();
});

test('CA-08: vincularla a mano la desbloquea y vuelve a entrar', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g-42', 'linked_at' => now(), 'blocked_at' => now()]);
    confirmedSession($user);
    socialProviderReturns('google', fakeSocialUser('g-42', 'ana@example.com', true));

    $this->withSession(['social.intent' => 'link'])->get('/auth/google/callback')->assertSessionHas('status', 'Google vinculado a tu cuenta.');

    expect(SocialIdentity::sole()->isBlocked())->toBeFalse();

    auth()->logout();
    $this->flushSession();
    $this->get('/auth/google/callback')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

test('desvincular sin identidad da error y no audita', function () {
    confirmedSession(User::factory()->create());

    $this->from('/profile')->delete('/user/social/facebook')->assertSessionHasErrors('social');

    expect(AuditLog::where('action', 'social.unlinked')->count())->toBe(0);
});

test('no se puede desvincular la identidad de otro usuario', function () {
    $owner = User::factory()->create();
    $owner->socialIdentities()->create(['provider' => 'facebook', 'provider_user_id' => 'fb-50', 'linked_at' => now()]);
    confirmedSession(User::factory()->create());

    $this->from('/profile')->delete('/user/social/facebook')->assertSessionHasErrors('social');

    expect(SocialIdentity::sole()->isBlocked())->toBeFalse();
});

test('la auditoria de vincular y desvincular no lleva secretos ni codigos', function () {
    $user = User::factory()->create();
    confirmedSession($user);
    socialProviderReturns('facebook', fakeSocialUser('fb-60', 'a@example.com'));

    $this->withSession(['social.intent' => 'link'])->get('/auth/facebook/callback?code=SECRETCODE&state=SECRETSTATE');
    $this->from('/profile')->delete('/user/social/facebook');

    $dump = AuditLog::all()->toJson();
    expect($dump)->not->toContain('SECRETCODE')->and($dump)->not->toContain('SECRETSTATE')->and($dump)->not->toContain('test-meta-secret');
});
