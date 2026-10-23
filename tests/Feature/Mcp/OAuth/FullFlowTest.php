<?php

use App\Mcp\Abilities;
use App\Models\Brand;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Tests\Support\McpClient;

uses(McpClient::class);

require_once __DIR__.'/Helpers.php';

test('OAuthFullFlow: register -> authorize -> token -> tools/call con el token emitido', function () {
    $user = User::factory()->create();
    Brand::factory()->for($user)->create(['name' => 'Marca del flujo']);

    $flow = runOAuthFlow($user, ['brands:read']);

    app('auth')->forgetGuards();
    [$err, $data] = $this->callTool($flow['token'], 'list_brands');
    expect($err)->toBeFalse()->and(json_encode($data))->toContain('Marca del flujo');
});

test('OAuthFullFlow: el token solo tiene lo que se marco en el consentimiento (CA-23 de extremo a extremo)', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();

    $flow = runOAuthFlow($user, Abilities::presets()['read']['abilities']);

    app('auth')->forgetGuards();
    [$err, $msg] = $this->callTool($flow['token'], 'create_post', ['brand_id' => $brand->id, 'text' => 'x']);
    expect($err)->toBeTrue()->and($msg)->toContain('posts:write');
});

test('OAuthFullFlow: autorizar dos veces el mismo cliente deja un solo token vivo', function () {
    $user = User::factory()->create();

    $client = oauthClient();
    $tokens = [];
    foreach (['a', 'b'] as $i) {
        $approve = $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'approve', 'abilities' => ['brands:read']]);
        $code = locationQuery($approve)['code'];
        $tokens[$i] = redeem($code, $client)->assertOk()->json('access_token');
    }

    expect($tokens['a'])->not->toBe($tokens['b'])
        ->and(PersonalAccessToken::where('oauth_client_id', $client->id)->count())->toBe(1)
        ->and(PersonalAccessToken::where('oauth_client_id', $client->id)->value('id'))->toBe((int) explode('|', $tokens['b'])[0]);
});

test('OAuthFullFlow: denegar vuelve con access_denied y no hay token', function () {
    $user = User::factory()->create();
    $client = oauthClient();

    $r = $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'deny']);

    expect(locationQuery($r)['error'])->toBe('access_denied')->and(PersonalAccessToken::count())->toBe(0);
});

test('OAuthFullFlow: el token del flujo no sirve para otro usuario ni da acceso a sus marcas', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    Brand::factory()->for($b)->create(['name' => 'Marca de B']);

    $flow = runOAuthFlow($a, ['brands:read']);

    app('auth')->forgetGuards();
    [, $data] = $this->callTool($flow['token'], 'list_brands');
    expect(json_encode($data))->not->toContain('Marca de B');
});
