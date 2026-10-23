<?php

use App\Models\OAuthAuthCode;
use App\Models\PersonalAccessToken;
use App\Models\User;

require_once __DIR__.'/Helpers.php';

test('OAuthNegatives: el token endpoint solo acepta POST', function () {
    $this->get('/oauth/token')->assertStatus(405);
    $this->get('/oauth/register')->assertStatus(405);
});

test('OAuthNegatives: scope distinto de mcp:use en authorize -> invalid_scope', function () {
    $user = User::factory()->create();
    $client = oauthClient();

    $r = $this->actingAs($user)->get(authorizeUrl(authorizeParams($client, ['scope' => 'mcp:use admin'])));

    expect(locationQuery($r)['error'])->toBe('invalid_scope');
    $this->actingAs($user)->get(authorizeUrl(authorizeParams($client, ['scope' => 'mcp:use'])))->assertOk();
});

test('OAuthNegatives: el state se devuelve intacto, tambien en errores', function () {
    $user = User::factory()->create();
    $client = oauthClient();

    $r = $this->actingAs($user)->get(authorizeUrl(authorizeParams($client, ['code_challenge' => null, 'state' => 'a b&c=d'])));

    expect(locationQuery($r)['state'])->toBe('a b&c=d');
});

test('OAuthNegatives: parametros con forma de array no provocan 500', function (array $q) {
    $user = User::factory()->create();

    $r = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($q));

    expect($r->getStatusCode())->toBeLessThan(500);
})->with([
    'client_id array' => [['client_id' => ['a'], 'redirect_uri' => 'http://127.0.0.1/cb']],
    'redirect array' => [['client_id' => 'x', 'redirect_uri' => ['http://127.0.0.1/cb']]],
    'sin nada' => [[]],
    'challenge array' => [['response_type' => 'code', 'code_challenge' => ['a']]],
]);

test('OAuthNegatives: un cliente con varias redirect_uris exige indicar una', function () {
    $user = User::factory()->create();
    $client = oauthClient(['http://127.0.0.1:1/a', 'http://127.0.0.1:1/b']);

    $this->actingAs($user)->get(authorizeUrl(authorizeParams($client, ['redirect_uri' => null])))->assertStatus(400);
    $this->actingAs($user)->get(authorizeUrl(authorizeParams($client, ['redirect_uri' => 'http://127.0.0.1:1/b'])))->assertOk();
});

test('OAuthNegatives: un usuario sin verificar no puede autorizar', function () {
    $user = User::factory()->unverified()->create();
    $client = oauthClient();

    $this->actingAs($user)->get(authorizeUrl(authorizeParams($client)))->assertRedirect(route('verification.notice', absolute: false));
    expect(OAuthAuthCode::count())->toBe(0);
});

test('OAuthNegatives: borrar el cliente anula sus codigos pendientes y no rompe los tokens', function () {
    [$code, , $user, $client] = mintCode();
    $token = $user->createToken('t', ['posts:read'])->accessToken;
    $token->forceFill(['oauth_client_id' => $client->id])->save();

    $client->delete();

    expect(OAuthAuthCode::count())->toBe(0)->and(PersonalAccessToken::find($token->id)?->oauth_client_id)->toBeNull();
    redeem($code, $client)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

test('OAuthNegatives: el codigo de un usuario no sirve con el verifier de otra sesion', function () {
    [$code, , , $client] = mintCode();

    redeem($code, $client, ['code_verifier' => 'otro-verifier-valido-0123456789-0123456789-abcdef'])->assertStatus(400);
    expect(PersonalAccessToken::count())->toBe(0);
});
