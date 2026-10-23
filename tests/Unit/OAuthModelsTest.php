<?php

use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeOAuthClient(): OAuthClient
{
    return OAuthClient::create(['name' => 'Cliente de prueba', 'redirect_uris' => ['http://127.0.0.1:5000/callback']]);
}

test('las tablas y la columna oauth_client_id existen', function () {
    expect(Schema::hasTable('oauth_clients'))->toBeTrue()
        ->and(Schema::hasTable('oauth_auth_codes'))->toBeTrue()
        ->and(Schema::hasColumn('personal_access_tokens', 'oauth_client_id'))->toBeTrue();
});

test('el cliente tiene UUID y redirect_uris como array', function () {
    $client = makeOAuthClient()->fresh();

    expect($client->id)->toBeString()->and(strlen($client->id))->toBe(36)
        ->and($client->redirect_uris)->toBe(['http://127.0.0.1:5000/callback']);
});

test('Sanctum usa el modelo propio de token', function () {
    expect(Sanctum::$personalAccessTokenModel)->toBe(PersonalAccessToken::class);

    $user = User::factory()->create();
    $manual = $user->createToken('manual', ['posts:read']);

    expect($manual->accessToken)->toBeInstanceOf(PersonalAccessToken::class)
        ->and($manual->accessToken->oauth_client_id)->toBeNull()
        ->and($manual->accessToken->oauthClient)->toBeNull()
        ->and($manual->plainTextToken)->toContain('|msk_');
});

test('un token OAuth enlaza su cliente y al borrar el cliente el token sobrevive con NULL', function () {
    $client = makeOAuthClient();
    $user = User::factory()->create();
    $token = $user->createToken('OAuth', ['posts:read'])->accessToken;
    $token->forceFill(['oauth_client_id' => $client->id])->save();

    expect($token->fresh()->oauthClient->is($client))->toBeTrue()
        ->and($client->tokens)->toHaveCount(1);

    $client->delete();

    $token = PersonalAccessToken::find($token->id);
    expect($token)->not->toBeNull()->and($token->oauth_client_id)->toBeNull();
});

test('el codigo de autorizacion se relaciona con cliente, usuario y token y castea abilities', function () {
    $client = makeOAuthClient();
    $user = User::factory()->create();
    $code = OAuthAuthCode::create([
        'code_hash' => hash('sha256', 'codigo'),
        'client_id' => $client->id,
        'user_id' => $user->id,
        'redirect_uri' => 'http://127.0.0.1:5000/callback',
        'code_challenge' => 'abc',
        'abilities' => ['posts:read'],
        'expires_at' => now()->addMinutes(5),
    ])->fresh();

    expect($code->client->is($client))->toBeTrue()
        ->and($code->user->is($user))->toBeTrue()
        ->and($code->abilities)->toBe(['posts:read'])
        ->and($code->used_at)->toBeNull()
        ->and($code->toArray())->not->toHaveKey('code_hash');
});
