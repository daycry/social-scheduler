<?php

use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\Auth\SocialIdentityResolver;
use App\Services\Auth\SocialResolution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function socialUser(string $id, ?string $email, mixed $verified = null): SocialiteUser
{
    $raw = ['sub' => $id, 'id' => $id];
    if ($email !== null) {
        $raw['email'] = $email;
    }
    if ($verified !== null) {
        $raw['email_verified'] = $verified;
    }

    return (new SocialiteUser)->setRaw($raw)->map(['id' => $id, 'email' => $email]);
}

function resolveSocial(string $provider, SocialiteUser $user): SocialResolution
{
    return app(SocialIdentityResolver::class)->resolve($provider, $user);
}

test('identidad existente no bloqueada devuelve su usuario, aunque el email del proveedor cambie', function () {
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g1']);

    $r = resolveSocial('google', socialUser('g1', 'distinto@example.com', false));

    expect($r->accepted())->toBeTrue()->and($r->user->is($user))->toBeTrue();
});

test('identidad bloqueada rechaza', function () {
    $user = User::factory()->create(['email' => 'a@example.com']);
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g1', 'blocked_at' => now()]);

    $r = resolveSocial('google', socialUser('g1', 'a@example.com', true));

    expect($r->accepted())->toBeFalse()->and($r->reason)->toBe('blocked');
});

test('Google verificado + cuenta verificada vincula; el email se compara sin mayusculas', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $r = resolveSocial('google', socialUser('g2', 'ANA@example.com', true));

    expect($r->accepted())->toBeTrue()->and($r->reason)->toBe('linked_auto');
    expect($user->socialIdentities()->where('provider_user_id', 'g2')->exists())->toBeTrue();
});

test('Google acepta la cadena "true" y rechaza cualquier otro valor', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    expect(resolveSocial('google', socialUser('g3', 'ana@example.com', 'true'))->accepted())->toBeTrue();

    User::factory()->create(['email' => 'luis@example.com']);
    foreach ([false, 'false', 1, 'yes', null] as $i => $v) {
        expect(resolveSocial('google', socialUser('gx'.$i, 'luis@example.com', $v))->accepted())->toBeFalse();
    }
});

test('Google: sin email, sin cuenta o cuenta local sin verificar rechazan con su motivo', function () {
    User::factory()->unverified()->create(['email' => 'sinverificar@example.com']);

    expect(resolveSocial('google', socialUser('g4', null, true))->reason)->toBe('no_email');
    expect(resolveSocial('google', socialUser('g5', 'nadie@example.com', true))->reason)->toBe('no_account');
    expect(resolveSocial('google', socialUser('g6', 'sinverificar@example.com', true))->reason)->toBe('local_unverified');
    expect(SocialIdentity::count())->toBe(0);
});

test('Google: un usuario con identidad desvinculada o con otra cuenta de Google no se re-vincula solo', function () {
    $blocked = User::factory()->create(['email' => 'bloq@example.com']);
    $blocked->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'old', 'blocked_at' => now()]);
    $other = User::factory()->create(['email' => 'otra@example.com']);
    $other->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'first']);

    expect(resolveSocial('google', socialUser('new1', 'bloq@example.com', true))->reason)->toBe('blocked_for_user');
    expect(resolveSocial('google', socialUser('new2', 'otra@example.com', true))->reason)->toBe('other_identity');
    expect(SocialIdentity::where('provider_user_id', 'like', 'new%')->count())->toBe(0);
});

test('Facebook sin identidad nunca vincula, ni con email coincidente y verificado', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $r = resolveSocial('facebook', socialUser('fb1', 'ana@example.com', true));

    expect($r->accepted())->toBeFalse()->and($r->reason)->toBe('unlinked');
    expect(SocialIdentity::count())->toBe(0);
});

test('un id vacio rechaza', function () {
    expect(resolveSocial('google', socialUser('', 'a@example.com', true))->reason)->toBe('no_id');
});
