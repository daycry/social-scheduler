<?php

use App\Models\User;
use App\Services\TwoFactor\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function totpUser(): array
{
    $svc = app(TotpService::class);
    $secret = $svc->generateSecret();
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => $secret])->save();

    return [$svc, $user, $secret, new Google2FA];
}

test('acepta el codigo actual y los de +-1 paso, y rechaza los de fuera de ventana', function () {
    [$svc, $user, $secret, $g] = totpUser();

    expect($svc->verify($user, $g->getCurrentOtp($secret)))->toBeTrue();

    // Un usuario nuevo por caso, para que el antirreplay no interfiera.
    foreach ([[-1, true], [1, true], [-3, false], [3, false]] as [$steps, $ok]) {
        $u = User::factory()->create();
        $u->forceFill(['two_factor_secret' => $secret])->save();
        $code = $g->oathTotp($secret, $g->getTimestamp() + $steps);
        expect($svc->verify($u, $code))->toBe($ok, "pasos {$steps}");
    }
});

test('un codigo aceptado una vez se rechaza la segunda (antirreplay en columna)', function () {
    [$svc, $user, $secret, $g] = totpUser();
    $code = $g->getCurrentOtp($secret);

    expect($svc->verify($user, $code))->toBeTrue();
    expect($svc->verify($user->fresh(), $code))->toBeFalse();
    expect(DB::table('users')->where('id', $user->id)->value('two_factor_last_used_at'))->not->toBeNull();
    // Tampoco sirve el paso anterior tras haber usado el actual.
    expect($svc->verify($user->fresh(), $g->oathTotp($secret, $g->getTimestamp() - 1)))->toBeFalse();
});

test('rechaza codigos mal formados o sin secreto', function () {
    [$svc, $user] = totpUser();
    expect($svc->verify($user, 'abc123'))->toBeFalse()
        ->and($svc->verify($user, ''))->toBeFalse()
        ->and($svc->verify($user, '12345'))->toBeFalse();
    expect($svc->verify(User::factory()->create(), '123456'))->toBeFalse();
});

test('genera 8 codigos y en BD solo hay hashes', function () {
    [$svc, $user] = totpUser();
    $plain = $svc->generateRecoveryCodes($user);

    expect($plain)->toHaveCount(8)->and(array_unique($plain))->toHaveCount(8);
    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');
    foreach ($plain as $c) {
        expect($raw)->not->toContain($c);
    }
    expect(json_decode($raw, true))->toHaveCount(8);
    expect($svc->remainingRecoveryCodes($user->fresh()))->toBe(8);
});

test('consumir un codigo de recuperacion lo invalida y devuelve los restantes', function () {
    [$svc, $user] = totpUser();
    $plain = $svc->generateRecoveryCodes($user);

    expect($svc->consumeRecoveryCode($user->fresh(), strtoupper($plain[0])))->toBe(7);
    expect($svc->consumeRecoveryCode($user->fresh(), $plain[0]))->toBeNull();
    expect($svc->consumeRecoveryCode($user->fresh(), $plain[1]))->toBe(6);
    expect($svc->consumeRecoveryCode($user->fresh(), 'nope-nope1'))->toBeNull();
    expect($svc->consumeRecoveryCode($user->fresh(), ''))->toBeNull();
});

test('regenerar invalida los codigos anteriores', function () {
    [$svc, $user] = totpUser();
    $old = $svc->generateRecoveryCodes($user);
    $svc->generateRecoveryCodes($user->fresh());

    expect($svc->consumeRecoveryCode($user->fresh(), $old[0]))->toBeNull();
});

test('el QR es un SVG con la URI otpauth y no se loguea nada', function () {
    Log::spy();
    [$svc, $user, $secret, $g] = totpUser();

    expect($svc->qrSvg($user, $secret))->toContain('<svg');
    expect($svc->otpAuthUrl($user, $secret))->toStartWith('otpauth://totp/')->toContain($secret);

    $svc->verify($user, $g->getCurrentOtp($secret));
    $svc->consumeRecoveryCode($user, $svc->generateRecoveryCodes($user)[0]);

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('debug');
});
