<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactor\TotpService;

function twoFactorUser(): User
{
    $svc = app(TotpService::class);
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => $svc->generateSecret(), 'two_factor_confirmed_at' => now()])->save();
    $svc->generateRecoveryCodes($user);

    return $user->fresh();
}

test('CA-12: el comando desactiva el 2FA, lo audita y el usuario entra solo con contraseña', function () {
    $user = twoFactorUser();

    $this->artisan('user:two-factor-reset', ['email' => $user->email, '--force' => true])->assertSuccessful();

    $fresh = $user->fresh();
    expect($fresh->hasTwoFactorEnabled())->toBeFalse()
        ->and($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull();
    expect(AuditLog::where('action', 'auth.2fa.reset_by_operator')->where('user_id', $user->id)->count())->toBe(1);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($fresh);
});

test('el comando falla con mensaje claro si el email no existe', function () {
    $this->artisan('user:two-factor-reset', ['email' => 'nadie@example.com', '--force' => true])
        ->expectsOutput('No existe ningún usuario con ese correo.')
        ->assertFailed();
    expect(AuditLog::where('action', 'auth.2fa.reset_by_operator')->count())->toBe(0);
});

test('sin --force pide confirmación y si se rechaza no cambia nada', function () {
    $user = twoFactorUser();

    $this->artisan('user:two-factor-reset', ['email' => $user->email])
        ->expectsConfirmation("¿Desactivar el doble factor de {$user->email}?", 'no')
        ->assertFailed();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('un usuario sin 2FA no genera auditoría', function () {
    $user = User::factory()->create();

    $this->artisan('user:two-factor-reset', ['email' => $user->email, '--force' => true])->assertSuccessful();
    expect(AuditLog::count())->toBe(0);
});
