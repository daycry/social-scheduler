<?php

use App\Models\AuditLog;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

require_once __DIR__.'/SocialTestHelpers.php';

const LEAK_CODE = 'AUTHCODE-SECRET-123';
const LEAK_STATE = 'STATE-SECRET-456';
const LEAK_TOKEN = 'ACCESSTOKEN-SECRET-789';
const LEAK_REFRESH = 'REFRESHTOKEN-SECRET-012';

beforeEach(function () {
    enableSocialProviders();
    $this->logPath = storage_path('framework/testing/social-leak-'.uniqid().'.log');
    @mkdir(dirname($this->logPath), 0777, true);
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logPath]);
});

afterEach(fn () => @unlink($this->logPath));

/** Usuario del proveedor con token y refresh token (nunca deben acabar en auditoria ni en log). */
function leakyUser(string $id, ?string $email, bool|string|null $verified = true): SocialiteUser
{
    $user = fakeSocialUser($id, $email, $verified);
    $user->token = LEAK_TOKEN;
    $user->refreshToken = LEAK_REFRESH;

    return $user;
}

function assertNoSocialLeak(string $logPath): void
{
    $haystack = AuditLog::all()->toJson().(is_file($logPath) ? file_get_contents($logPath) : '');

    foreach ([LEAK_CODE, LEAK_STATE, LEAK_TOKEN, LEAK_REFRESH, 'test-google-secret', 'test-meta-secret'] as $secret) {
        expect($haystack)->not->toContain($secret);
    }
}

test('ninguna fila de audit_logs ni linea de log contiene code, state, token ni secreto', function () {
    $callback = '/auth/google/callback?code='.LEAK_CODE.'&state='.LEAK_STATE;
    $user = User::factory()->create(['email' => 'ana@example.com']);

    // 1) vinculacion automatica + login OK
    socialProviderReturns('google', leakyUser('g-1', 'ana@example.com'));
    $this->get($callback)->assertRedirect();
    auth()->logout();

    // 2) rechazo (sin cuenta) y rechazo (sin verificar)
    socialProviderReturns('google', leakyUser('g-2', 'nadie@example.com'));
    $this->get($callback);
    socialProviderReturns('google', leakyUser('g-3', 'ana@example.com', false));
    $this->get($callback);

    // 3) cancelar, state invalido y proveedor caido (el mensaje de Guzzle lleva el code)
    $this->get('/auth/google/callback?error=access_denied&state='.LEAK_STATE);
    Socialite::fake('google-login', fn () => throw new InvalidStateException);
    $this->get($callback);
    Socialite::fake('google-login', fn () => throw new RuntimeException('GET https://x/?code='.LEAK_CODE.' token='.LEAK_TOKEN));
    $this->get($callback);

    // 4) 2FA con via
    $twoFa = socialUserWith2fa();
    $twoFa->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g-4', 'linked_at' => now()]);
    socialProviderReturns('google', leakyUser('g-4', $twoFa->email));
    $this->get($callback)->assertRedirect(route('two-factor.challenge'));
    $this->post('/two-factor-challenge', ['code' => '000000']);

    // 5) vincular y desvincular a mano
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time(), 'social.intent' => 'link']);
    socialProviderReturns('facebook', leakyUser('fb-1', 'ana@example.com'));
    $this->get('/auth/facebook/callback?code='.LEAK_CODE.'&state='.LEAK_STATE);
    $this->from('/profile')->delete('/user/social/facebook');

    expect(AuditLog::whereIn('action', ['auth.social.login', 'social.linked', 'social.unlinked'])->count())->toBeGreaterThanOrEqual(6);
    assertNoSocialLeak($this->logPath);
});

test('la auditoria del login social no guarda el email del proveedor', function () {
    User::factory()->create(['email' => 'secreto-ana@example.com']);
    socialProviderReturns('google', leakyUser('g-9', 'secreto-ana@example.com', false));

    $this->get('/auth/google/callback');

    expect(AuditLog::all()->toJson())->not->toContain('secreto-ana@example.com');
});
