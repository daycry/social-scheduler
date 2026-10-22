<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactor\TotpService;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

function settingsUser(bool $enabled = false): array
{
    $svc = app(TotpService::class);
    $user = User::factory()->create();
    $secret = $svc->generateSecret();
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => $enabled ? now() : null])->save();
    $codes = $enabled ? $svc->generateRecoveryCodes($user) : [];

    return [$user->fresh(), $secret, $codes];
}

function currentOtp(string $secret): string
{
    $g = new Google2FA;

    return $g->oathTotp($secret, $g->getTimestamp());
}

function confirmed()
{
    return test()->withSession(['auth.password_confirmed_at' => time()]);
}

test('las rutas de gestion exigen auth y password.confirm', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'user/two-factor'));

    expect($routes)->toHaveCount(4);
    foreach ($routes as $r) {
        expect($r->gatherMiddleware())->toContain('auth')->toContain('password.confirm');
    }
});

test('sin confirmar la contraseña se redirige a confirmarla', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/user/two-factor')->assertRedirect(route('password.confirm'));
    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('activar genera un secreto pendiente con QR, sin activar aun', function () {
    $user = User::factory()->create();

    $res = confirmed()->actingAs($user)->post('/user/two-factor');

    $res->assertSessionHas('twoFactorSetup', fn ($s) => strlen($s['secret']) === 32 && str_contains($s['qrSvg'], '<svg'));
    $fresh = $user->fresh();
    expect($fresh->two_factor_secret)->not->toBeNull()->and($fresh->hasTwoFactorEnabled())->toBeFalse();
});

test('CA-01: confirmar con TOTP valido activa, da 8 codigos una vez, rota remember_token y audita', function () {
    [$user, $secret] = settingsUser();
    $tokenBefore = $user->remember_token;

    $res = confirmed()->actingAs($user)->post('/user/two-factor/confirm', ['code' => currentOtp($secret)]);

    $res->assertSessionHasNoErrors();
    $res->assertSessionHas('recoveryCodes', fn ($c) => count($c) === 8);
    $fresh = $user->fresh();
    expect($fresh->hasTwoFactorEnabled())->toBeTrue();
    expect($fresh->remember_token)->not->toBe($tokenBefore);
    expect(DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes'))->not->toContain($res->getSession()->get('recoveryCodes')[0]);
    expect(AuditLog::where('action', 'auth.2fa.enabled')->where('user_id', $user->id)->where('channel', 'web')->count())->toBe(1);
});

test('CA-01: los codigos no se pueden volver a mostrar', function () {
    [$user, $secret] = settingsUser();
    confirmed()->actingAs($user)->post('/user/two-factor/confirm', ['code' => currentOtp($secret)]);

    $this->get('/profile')->assertSessionMissing('recoveryCodes');
});

test('CA-02: TOTP incorrecto da error en el campo y conserva el secreto pendiente', function () {
    [$user, $secret] = settingsUser();

    $res = confirmed()->actingAs($user)->post('/user/two-factor/confirm', ['code' => '000000']);

    $res->assertSessionHasErrors('code');
    $res->assertSessionHas('twoFactorSetup', fn ($s) => $s['secret'] === $secret);
    $fresh = $user->fresh();
    expect($fresh->hasTwoFactorEnabled())->toBeFalse()->and($fresh->two_factor_secret)->toBe($secret);
    expect(AuditLog::where('action', 'auth.2fa.enabled')->count())->toBe(0);
});

test('CA-03: desactivar sin contraseña o sin codigo valido se rechaza', function () {
    [$user, $secret] = settingsUser(true);

    confirmed()->actingAs($user)->delete('/user/two-factor', ['password' => 'mal', 'code' => currentOtp($secret)])
        ->assertSessionHasErrors('password');
    confirmed()->actingAs($user)->delete('/user/two-factor', ['password' => 'password', 'code' => '000000'])
        ->assertSessionHasErrors('code');
    confirmed()->actingAs($user)->delete('/user/two-factor', ['password' => 'password'])
        ->assertSessionHasErrors('code');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('CA-03: con contraseña y TOTP se desactiva, se borran secreto y codigos y se audita', function () {
    [$user, $secret] = settingsUser(true);

    confirmed()->actingAs($user)->delete('/user/two-factor', ['password' => 'password', 'code' => currentOtp($secret)])
        ->assertSessionHasNoErrors();

    $row = DB::table('users')->where('id', $user->id)->first();
    expect($row->two_factor_secret)->toBeNull()->and($row->two_factor_recovery_codes)->toBeNull()->and($row->two_factor_confirmed_at)->toBeNull();
    expect(AuditLog::where('action', 'auth.2fa.disabled')->count())->toBe(1);
});

test('CA-03: tambien se puede desactivar con un codigo de recuperacion', function () {
    [$user, , $codes] = settingsUser(true);

    confirmed()->actingAs($user)->delete('/user/two-factor', ['password' => 'password', 'recovery_code' => $codes[0]])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('CA-10: regenerar da 8 codigos nuevos, los viejos dejan de valer y se audita', function () {
    [$user, , $old] = settingsUser(true);

    $res = confirmed()->actingAs($user)->post('/user/two-factor/recovery-codes');

    $res->assertSessionHas('recoveryCodes', fn ($c) => count($c) === 8 && $c !== $old);
    expect(app(TotpService::class)->consumeRecoveryCode($user->fresh(), $old[0]))->toBeNull();
    expect(AuditLog::where('action', 'auth.2fa.recovery_regenerated')->count())->toBe(1);
});

test('regenerar sin 2FA activo no hace nada', function () {
    $user = User::factory()->create();

    confirmed()->actingAs($user)->post('/user/two-factor/recovery-codes')->assertSessionMissing('recoveryCodes');
});
