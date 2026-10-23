<?php

use App\Models\AuditLog;
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

test('link: vincula, es idempotente, desbloquea la propia y rechaza la ajena o un segundo activo', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $resolver = app(SocialIdentityResolver::class);

    expect($resolver->link($user, 'facebook', socialUser('fb1', 'a@example.com')))->toBe(SocialIdentityResolver::LINKED);
    expect($resolver->link($user, 'facebook', socialUser('fb1', null)))->toBe(SocialIdentityResolver::ALREADY);
    expect($resolver->link($other, 'facebook', socialUser('fb1', null)))->toBe(SocialIdentityResolver::TAKEN);
    expect($resolver->link($user, 'facebook', socialUser('fb2', null)))->toBe(SocialIdentityResolver::OTHER_IDENTITY);
    expect($resolver->link($user, 'facebook', socialUser('', null)))->toBe(SocialIdentityResolver::NO_ID);

    expect($resolver->unlink($user, 'facebook'))->toBeTrue()->and($resolver->unlink($user, 'facebook'))->toBeFalse();
    expect($resolver->link($user, 'facebook', socialUser('fb1', null)))->toBe(SocialIdentityResolver::RELINKED);
    expect(SocialIdentity::where('provider_user_id', 'fb1')->sole()->isBlocked())->toBeFalse();
});

test('C1: el email se compara byte a byte; ß/ss y ä/a no vinculan la cuenta equivocada', function (string $local, string $google) {
    $user = User::factory()->create(['email' => $local]);

    $r = resolveSocial('google', socialUser('gc1', $google, true));

    expect($r->accepted())->toBeFalse()->and($r->reason)->toBe('no_account');
    expect($user->socialIdentities()->count())->toBe(0);
})->with([
    'ss frente a ß' => ['alice@strasse.de', 'alice@straße.de'],
    'ß frente a ss' => ['alice@straße.de', 'alice@strasse.de'],
    'a frente a ä' => ['alice@example.com', 'alice@exämple.com'],
    'ä frente a a' => ['alice@exämple.com', 'alice@example.com'],
]);

test('C1: un email no ASCII vincula solo con la cuenta identica (sin mayusculas)', function () {
    $user = User::factory()->create(['email' => 'alice@exämple.com']);

    $r = resolveSocial('google', socialUser('gc2', 'ALICE@exämple.com', true));

    expect($r->accepted())->toBeTrue()->and($r->user->is($user))->toBeTrue();
});

test('B1: G1, desvincular, G2 y revincular G1 no deja dos activas', function () {
    $user = User::factory()->create();
    $resolver = app(SocialIdentityResolver::class);

    expect($resolver->link($user, 'facebook', socialUser('G1', null)))->toBe(SocialIdentityResolver::LINKED);
    $resolver->unlink($user, 'facebook');
    expect($resolver->link($user, 'facebook', socialUser('G2', null)))->toBe(SocialIdentityResolver::LINKED);
    expect($resolver->link($user, 'facebook', socialUser('G1', null)))->toBe(SocialIdentityResolver::OTHER_IDENTITY);

    expect($user->socialIdentities()->where('provider', 'facebook')->whereNull('blocked_at')->count())->toBe(1);
});

test('B1: unlink bloquea todas las identidades activas del proveedor', function () {
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'facebook', 'provider_user_id' => 'a']);
    $user->socialIdentities()->create(['provider' => 'facebook', 'provider_user_id' => 'b']);

    expect(app(SocialIdentityResolver::class)->unlink($user, 'facebook'))->toBeTrue();
    expect($user->socialIdentities()->whereNull('blocked_at')->count())->toBe(0);
});

test('B2: una identidad desvinculada en otra cuenta se transfiere al vincularla a mano y se audita', function () {
    $old = User::factory()->create();
    $new = User::factory()->create();
    $resolver = app(SocialIdentityResolver::class);
    $resolver->link($old, 'facebook', socialUser('fbx', null));
    $resolver->unlink($old, 'facebook');

    expect($resolver->link($new, 'facebook', socialUser('fbx', null)))->toBe(SocialIdentityResolver::LINKED);

    $row = SocialIdentity::where('provider_user_id', 'fbx')->sole();
    expect($row->user_id)->toBe($new->id)->and($row->isBlocked())->toBeFalse();
    expect($old->socialIdentities()->count())->toBe(0);
    expect(AuditLog::where('action', 'social.linked')->where('user_id', $new->id)->where('detail', 'like', '%transferida_de%')->count())->toBe(1);
});

test('B6: el fallo por id vacio al vincular se audita como DENIED del usuario autenticado', function () {
    $user = User::factory()->create();

    app(SocialIdentityResolver::class)->link($user, 'facebook', socialUser('', null));

    expect(AuditLog::where('action', 'social.linked')->where('user_id', $user->id)->where('outcome', 'denied')->count())->toBe(1);
});

// El filtro en PHP es la defensa real en MySQL (collation `_ci`); aqui se le dan las filas que MySQL devolveria de mas.
test('pickExactEmail descarta lo que una collation _ci da por igual (CWE-176)', function (string $local, string $google, bool $match) {
    $user = new User(['email' => $local]);

    expect(SocialIdentityResolver::pickExactEmail([$user], $google))->toBe($match ? $user : null);
})->with([
    'ß frente a ss' => ['alice@straße.de', 'alice@strasse.de', false],
    'ss frente a ß' => ['alice@strasse.de', 'alice@straße.de', false],
    'ä frente a a' => ['alice@exämple.com', 'alice@example.com', false],
    'a frente a ä' => ['alice@example.com', 'alice@exämple.com', false],
    'mayusculas' => ['Alice@Example.com', 'alice@example.com', true],
    'identico no ASCII' => ['alice@exämple.com', 'ALICE@EXÄMPLE.COM', true],
]);
