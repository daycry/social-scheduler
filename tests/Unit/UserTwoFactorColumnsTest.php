<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('la tabla users tiene las columnas de 2FA', function () {
    foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_used_at'] as $col) {
        expect(Schema::hasColumn('users', $col))->toBeTrue();
    }
});

test('el secreto y los codigos no salen en toArray ni en JSON', function () {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_recovery_codes' => ['hash1']])->save();

    expect($user->fresh()->toArray())->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes']);
    expect($user->fresh()->toJson())->not->toContain('JBSWY3DPEHPK3PXP');
});

test('el secreto se guarda cifrado en BD', function () {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();

    $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
    expect($raw)->not->toBe('JBSWY3DPEHPK3PXP')->and($raw)->not->toContain('JBSWY3DPEHPK3PXP');
    expect($user->fresh()->two_factor_secret)->toBe('JBSWY3DPEHPK3PXP');
});

test('hasTwoFactorEnabled exige secreto y confirmacion', function () {
    $user = User::factory()->create();
    expect($user->hasTwoFactorEnabled())->toBeFalse();
    $user->forceFill(['two_factor_secret' => 'X'])->save();
    expect($user->hasTwoFactorEnabled())->toBeFalse();
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    expect($user->hasTwoFactorEnabled())->toBeTrue();
});
