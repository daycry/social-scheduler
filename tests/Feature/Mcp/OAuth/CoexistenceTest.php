<?php

use App\Models\Brand;
use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\McpClient;

uses(McpClient::class);

require_once __DIR__.'/Helpers.php';

/** Hace el canje real (codigo en BD -> /oauth/token) y devuelve el access_token. */
function oauthToken(User $user, OAuthClient $client, array $abilities): string
{
    $code = 'c-'.bin2hex(random_bytes(8));
    OAuthAuthCode::create([
        'code_hash' => hash('sha256', $code), 'client_id' => $client->id, 'user_id' => $user->id,
        'redirect_uri' => $client->redirect_uris[0], 'code_challenge' => pkceChallenge(), 'abilities' => $abilities,
        'resource' => rtrim(config('app.url'), '/').'/mcp', 'expires_at' => now()->addMinutes(5),
    ]);

    return test()->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $client->redirect_uris[0],
        'client_id' => $client->id, 'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
    ])->assertOk()->json('access_token');
}

test('OAuthCoexistence CA-23: un token OAuth read no puede create_post, igual que una key sin posts:write', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $oauth = oauthToken($user, oauthClient(), ['brands:read', 'posts:read']);
    $manual = $this->mcpKey($user, ['brands:read', 'posts:read']);

    [$errOauth, $msgOauth] = $this->callTool($oauth, 'create_post', ['brand_id' => $brand->id, 'text' => 'x']);
    [$errManual, $msgManual] = $this->callTool($manual, 'create_post', ['brand_id' => $brand->id, 'text' => 'x']);

    expect($errOauth)->toBeTrue()->and($errManual)->toBeTrue()
        ->and($msgOauth)->toBe($msgManual)->and($msgOauth)->toContain('posts:write');
});

test('OAuthCoexistence CA-24: las API keys manuales siguen funcionando junto a los tokens OAuth', function () {
    $user = User::factory()->create();
    $manual = $this->mcpKey($user);
    oauthToken($user, oauthClient(), ['brands:read']);

    $this->rpc($manual, 'tools/list')->assertOk();
    expect(PersonalAccessToken::whereNull('oauth_client_id')->count())->toBe(1);
});

test('OAuthCoexistence CA-25: revocar el token OAuth desde API keys deja /mcp en 401 y se ve su origen', function () {
    $user = User::factory()->create();
    $client = oauthClient(name: 'Codex');
    $plain = oauthToken($user, $client, ['brands:read']);
    $this->rpc($plain, 'tools/list')->assertOk();
    $tokenId = (int) explode('|', $plain)[0];

    $this->actingAs($user)->get('/api-keys')->assertInertia(fn (Assert $p) => $p->component('ApiKeys/Index')
        ->where('keys.0.id', $tokenId)->where('keys.0.origin', 'oauth')->where('keys.0.oauth_client', 'Codex'));

    $this->actingAs($user)->delete(route('api-keys.destroy', $tokenId, absolute: false))->assertRedirect();

    $this->rpc($plain, 'tools/list')->assertUnauthorized();
});

test('OAuthCoexistence: las keys manuales se listan como manual', function () {
    $user = User::factory()->create();
    $this->mcpKey($user);

    $this->actingAs($user)->get('/api-keys')->assertInertia(fn (Assert $p) => $p
        ->where('keys.0.origin', 'manual')->where('keys.0.oauth_client', null));
});

test('OAuthCoexistence CA-26: reautorizar revoca el token anterior de ese (usuario, cliente) y respeta el resto', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $clientA = oauthClient(name: 'A');
    $clientB = oauthClient(['http://127.0.0.1:2/cb'], 'B');

    $manual = $this->mcpKey($user);
    $bOld = oauthToken($user, $clientB, ['brands:read']);
    $otherUserA = oauthToken($other, $clientA, ['brands:read']);
    $aOld = oauthToken($user, $clientA, ['brands:read']);
    $aNew = oauthToken($user, $clientA, ['brands:read', 'posts:read']);

    $this->rpc($aOld, 'tools/list')->assertUnauthorized();
    foreach ([$aNew, $bOld, $manual, $otherUserA] as $alive) {
        $this->rpc($alive, 'tools/list')->assertOk();
    }
    expect($user->tokens()->where('oauth_client_id', $clientA->id)->count())->toBe(1);
});

test('OAuthCoexistence CA-28: McpGuard sigue dando 403 con Origin ajeno en /mcp; /oauth y /.well-known no exigen Origin', function () {
    $user = User::factory()->create();
    $key = $this->mcpKey($user);
    $evil = ['Origin' => 'https://evil.example'];

    $this->withHeaders($evil)->rpc($key, 'tools/list')->assertForbidden();

    $this->getJson('/.well-known/oauth-authorization-server', $evil)->assertOk();
    $this->getJson('/.well-known/oauth-protected-resource/mcp', $evil)->assertOk();
    $this->postJson('/oauth/register', ['redirect_uris' => ['http://localhost:1/cb']], $evil)->assertCreated();
    $this->post('/oauth/token', ['grant_type' => 'x'], $evil)->assertStatus(400);
});

test('OAuthCoexistence: si se borra el cliente, el token sigue mostrandose como OAuth en /api-keys', function () {
    $user = User::factory()->create();
    [$code, , , $client] = mintCode(['posts:read'], [], $user);
    redeem($code, $client)->assertOk();

    $client->delete();

    $this->actingAs($user)->get('/api-keys')->assertInertia(fn (Assert $p) => $p->where('keys.0.origin', 'oauth')->where('keys.0.oauth_client', null));
});
