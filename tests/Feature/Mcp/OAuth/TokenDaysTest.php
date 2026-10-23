<?php

use App\Models\OAuthAuthCode;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Helpers.php';

test('OAuthTokenDays: el default es 90 y la lista ofrece 30, 90, 180 y 365', function () {
    expect(config('services.mcp.oauth.token_days'))->toBe(90)
        ->and(config('services.mcp.oauth.token_day_options'))->toBe([30, 90, 180, 365]);

    $user = User::factory()->create();
    $this->actingAs($user)->get(authorizeUrl(authorizeParams(oauthClient())))->assertInertia(fn (Assert $p) => $p
        ->where('defaultTokenDays', 90)->where('tokenDayOptions', [30, 90, 180, 365]));
});

test('OAuthTokenDays: sin elegir nada el token dura 90 dias', function () {
    $user = User::factory()->create();
    $client = oauthClient();
    $r = $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'approve', 'abilities' => ['posts:read']]);
    expect(OAuthAuthCode::firstOrFail()->token_days)->toBeNull();

    redeem(locationQuery($r)['code'], $client)->assertOk()->assertJsonPath('expires_in', 90 * 86400);
});

test('OAuthTokenDays: elegir 365 da expires_at a 365 dias y expires_in coherente', function () {
    $user = User::factory()->create();
    $client = oauthClient();
    $r = $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'approve', 'abilities' => ['posts:read'], 'token_days' => 365]);
    expect(OAuthAuthCode::firstOrFail()->token_days)->toBe(365);

    $res = redeem(locationQuery($r)['code'], $client)->assertOk()->assertJsonPath('expires_in', 365 * 86400);
    $token = PersonalAccessToken::where('oauth_client_id', $client->id)->firstOrFail();
    expect((int) round(now()->diffInDays($token->expires_at, true)))->toBe(365)
        ->and($res->json('expires_in'))->toBe(365 * 86400);
});

test('OAuthTokenDays: un valor fuera de la lista da error de validacion y no emite codigo', function (mixed $value) {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/oauth/authorize', authorizeParams(oauthClient()) + ['decision' => 'approve', 'abilities' => ['posts:read'], 'token_days' => $value])
        ->assertSessionHasErrors('token_days');

    expect(OAuthAuthCode::count())->toBe(0);
})->with([9999, 45, 0, -1, 'abc']);

test('OAuthTokenDays: una query token_days=9999 en /oauth/authorize se ignora', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(authorizeUrl(authorizeParams(oauthClient()) + ['token_days' => 9999]))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('defaultTokenDays', 90)->where('params', fn ($params) => ! isset($params['token_days'])));
});

test('OAuthTokenDays: el canje acota al tope key_max_days aunque el codigo guarde mas', function () {
    config(['services.mcp.key_max_days' => 60]);
    [$code, , , $client] = mintCode(['posts:read'], ['token_days' => 365]);

    redeem($code, $client)->assertOk()->assertJsonPath('expires_in', 60 * 86400);
});

test('OAuthTokenDays: con MCP_KEY_MAX_DAYS=60 la lista no ofrece 90 y el default baja a 30', function () {
    $saved = [$_ENV['MCP_KEY_MAX_DAYS'] ?? null, $_SERVER['MCP_KEY_MAX_DAYS'] ?? null, getenv('MCP_KEY_MAX_DAYS')];
    $_ENV['MCP_KEY_MAX_DAYS'] = $_SERVER['MCP_KEY_MAX_DAYS'] = '60';
    putenv('MCP_KEY_MAX_DAYS=60');
    try {
        $oauth = (require base_path('config/services.php'))['mcp']['oauth'];
    } finally {
        unset($_ENV['MCP_KEY_MAX_DAYS'], $_SERVER['MCP_KEY_MAX_DAYS']);
        $saved[0] !== null && $_ENV['MCP_KEY_MAX_DAYS'] = $saved[0];
        $saved[1] !== null && $_SERVER['MCP_KEY_MAX_DAYS'] = $saved[1];
        $saved[2] === false ? putenv('MCP_KEY_MAX_DAYS') : putenv('MCP_KEY_MAX_DAYS='.$saved[2]);
    }

    expect($oauth['token_day_options'])->toBe([30])->and($oauth['token_days'])->toBe(30);
});
