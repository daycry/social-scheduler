<?php

use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('(proveedor, id) es unico, pero el mismo id en otro proveedor vale', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    SocialIdentity::create(['user_id' => $a->id, 'provider' => 'google', 'provider_user_id' => '123']);

    SocialIdentity::create(['user_id' => $a->id, 'provider' => 'facebook', 'provider_user_id' => '123']);
    expect(SocialIdentity::count())->toBe(2);

    expect(fn () => SocialIdentity::create(['user_id' => $b->id, 'provider' => 'google', 'provider_user_id' => '123']))
        ->toThrow(QueryException::class);
});

test('borrar el usuario borra sus identidades en cascada', function () {
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g1']);

    $user->delete();

    expect(SocialIdentity::count())->toBe(0);
});

test('blocked_at marca la identidad como bloqueada', function () {
    $user = User::factory()->create();
    $identity = $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g1']);

    expect($identity->isBlocked())->toBeFalse();

    $identity->update(['blocked_at' => now()]);

    expect($identity->fresh()->isBlocked())->toBeTrue();
});
