<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\LoginFlow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FakePasskeys;

require_once __DIR__.'/Social/SocialTestHelpers.php';

function pendingChallengeVia(User $user, ?string $via): void
{
    Route::middleware('web')->get('/_test/pending-challenge', function (Request $request) use ($user, $via) {
        return $via === null
            ? app(LoginFlow::class)->complete($request, $user, false)
            : app(LoginFlow::class)->complete($request, $user, false, $via);
    });

    test()->get('/_test/pending-challenge')->assertRedirect(route('two-factor.challenge'));
}

function validCode(User $user): string
{
    $g = new Google2FA;

    return $g->oathTotp($user->two_factor_secret, $g->getTimestamp());
}

test('LoginFlow guarda login.via junto a login.* (password por defecto)', function () {
    $user = socialUserWith2fa();
    pendingChallengeVia($user, null);

    expect(session('login.via'))->toBe('password');
});

test('el detail de challenge_passed lleva via segun el primer factor', function (string $via) {
    $user = socialUserWith2fa();
    pendingChallengeVia($user, $via);

    $this->post('/two-factor-challenge', ['code' => validCode($user)])->assertRedirect(route('dashboard', absolute: false));

    expect(AuditLog::where('action', 'auth.2fa.challenge_passed')->sole()->detail)->toBe('totp;via='.$via);
    expect(session()->has('login.via'))->toBeFalse();
})->with(['password', 'google', 'facebook']);

test('el detail de challenge_failed lleva via', function () {
    $user = socialUserWith2fa();
    pendingChallengeVia($user, 'google');

    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

    expect(AuditLog::where('action', 'auth.2fa.challenge_failed')->sole()->detail)->toBe('totp;via=google');
});

test('un via desconocido en sesion se audita como password', function () {
    $user = socialUserWith2fa();
    pendingChallengeVia($user, 'google');
    $this->withSession(['login.via' => '<script>', 'login.id' => $user->id, 'login.at' => now()->timestamp]);

    $this->post('/two-factor-challenge', ['code' => validCode($user)]);

    expect(AuditLog::where('action', 'auth.2fa.challenge_passed')->sole()->detail)->toBe('totp;via=password');
});

test('expired usa el texto neutro y olvida login.via', function () {
    $user = socialUserWith2fa();
    pendingChallengeVia($user, 'google');
    $this->travel(10)->minutes();

    $this->get('/two-factor-challenge')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'La verificación caducó. Vuelve a iniciar sesión.');

    expect(session()->has('login.via'))->toBeFalse()->and(session()->has('login.id'))->toBeFalse();
});

test('la passkey limpia login.via junto a login.* de un login a medias', function () {
    FakePasskeys::bind();
    $user = User::factory()->create();
    FakePasskeys::seed($user, 'Mia', 'cred-via');
    $this->withSession(['login.id' => 999, 'login.remember' => true, 'login.at' => time(), 'login.via' => 'google']);

    $this->getJson('/passkeys/login/options')->assertOk();
    $this->postJson('/passkeys/login', ['credential' => FakePasskeys::credential('cred-via')])->assertOk();

    expect(session()->has('login.via'))->toBeFalse()->and(session()->has('login.id'))->toBeFalse();
    $this->assertAuthenticatedAs($user);
});
