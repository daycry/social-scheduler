<?php

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Connectors\TikTok\TikTokConnector;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tiktok.client_key' => 'CK', 'services.tiktok.client_secret' => 'CS']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

function tiktokFake(array $over = []): void
{
    Http::swap(new Factory);
    Http::fake(array_merge([
        'open.tiktokapis.com/v2/oauth/token/' => Http::response([
            'access_token' => 'T-ACCESS', 'refresh_token' => 'T-REFRESH', 'expires_in' => 86400,
            'refresh_expires_in' => 31536000, 'open_id' => 'OPEN123', 'scope' => 'user.info.basic,video.publish,video.upload',
        ]),
        'open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => ['open_id' => 'OPEN123', 'display_name' => 'marblepartyrace']], 'error' => ['code' => 'ok']]),
        'open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(['data' => [
            'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY'], 'max_video_post_duration_sec' => 600,
        ], 'error' => ['code' => 'ok']]),
    ], $over));
}

test('TikTokOAuth authorization URL uses the own client key and the three scopes', function () {
    $url = (new TikTokConnector)->authorizationUrl('ST', 'https://app.test/connections/tiktok/callback');

    expect($url)->toStartWith('https://www.tiktok.com/v2/auth/authorize/?')
        ->toContain('client_key=CK')->toContain('state=ST')
        ->toContain(urlencode('user.info.basic,video.upload,video.publish'));
});

test('TikTokOAuth exchanges the code, reads the profile and stores creator_info options without touching direct_post', function () {
    tiktokFake();

    $candidates = (new TikTokConnector)->exchangeCode('CODE', 'https://app.test/connections/tiktok/callback');

    expect($candidates)->toHaveCount(1);
    $c = $candidates[0];
    expect($c->network)->toBe(Network::TikTok)->and($c->externalId)->toBe('OPEN123')->and($c->name)->toBe('marblepartyrace')
        ->and($c->refreshToken)->toBe('T-REFRESH')
        ->and($c->tokenExpiresAt->isFuture())->toBeTrue()->and(now()->diffInDays($c->refreshExpiresAt))->toBeGreaterThan(300)
        ->and($c->meta)->not->toHaveKey('direct_post')
        ->and($c->meta['privacy_level_options'])->toContain('SELF_ONLY')
        ->and($c->meta['max_video_post_duration_sec'])->toBe(600);
});

test('TikTokOAuth creator_info is queried with a JSON object body, not an array', function () {
    tiktokFake();
    (new TikTokConnector)->exchangeCode('CODE', 'https://x/cb');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'creator_info/query') && $r->body() === '{}' && str_contains($r->header('Content-Type')[0], 'application/json'));
});

test('TikTokOAuth still connects when creator_info is unavailable', function () {
    tiktokFake(['open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(['error' => ['code' => 'invalid_params']], 400)]);

    $c = (new TikTokConnector)->exchangeCode('CODE', 'https://x/cb')[0];

    expect($c->meta)->toBe([]);
});

test('TikTokOAuth fails readably on an invalid code or missing upload scopes', function () {
    tiktokFake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(['error' => 'invalid_request', 'error_description' => 'Authorization code is expired'], 400)]);
    expect(fn () => (new TikTokConnector)->exchangeCode('C', 'https://x/cb'))->toThrow(ConnectorException::class, 'invalid_request');

    tiktokFake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(['access_token' => 'A', 'scope' => 'user.info.basic', 'open_id' => 'O'])]);
    expect(fn () => (new TikTokConnector)->exchangeCode('C', 'https://x/cb'))->toThrow(ConnectorException::class, 'video.upload');

    // video.publish solo (sin video.upload) no basta: el modo por defecto de G1 es borrador en la bandeja.
    tiktokFake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(['access_token' => 'A', 'scope' => 'user.info.basic,video.publish', 'open_id' => 'O'])]);
    expect(fn () => (new TikTokConnector)->exchangeCode('C', 'https://x/cb'))->toThrow(ConnectorException::class, 'video.upload');
});

test('TikTokOAuth refresh rotates access and refresh tokens automatically', function () {
    tiktokFake(['open.tiktokapis.com/v2/oauth/token/' => Http::response([
        'access_token' => 'T-ACCESS-2', 'refresh_token' => 'T-REFRESH-2', 'expires_in' => 86400, 'refresh_expires_in' => 31536000,
    ])]);
    $account = SocialAccount::factory()->for($this->brand)->network(Network::TikTok)->create(['refresh_token' => 'T-REFRESH', 'refresh_expires_at' => now()->addDays(300)]);

    $r = (new TikTokConnector)->refresh($account);

    expect($r->accessToken)->toBe('T-ACCESS-2')->and($r->refreshToken)->toBe('T-REFRESH-2')
        ->and($r->tokenExpiresAt->isFuture())->toBeTrue()->and($r->refreshExpiresAt->isFuture())->toBeTrue();
    Http::assertSent(fn ($req) => $req['grant_type'] === 'refresh_token' && $req['refresh_token'] === 'T-REFRESH' && $req['client_key'] === 'CK');
});

test('TikTokOAuth refresh requires reconnection when the refresh token is invalid or expired and 503 is transient', function () {
    $account = SocialAccount::factory()->for($this->brand)->network(Network::TikTok)->create(['refresh_token' => 'T-REFRESH', 'refresh_expires_at' => now()->addDays(10)]);

    tiktokFake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(['error' => 'invalid_grant'], 400)]);
    expect(fn () => (new TikTokConnector)->refresh($account))->toThrow(ReconnectRequiredException::class);

    $old = SocialAccount::factory()->for($this->brand)->network(Network::TikTok)->create(['refresh_token' => 'X', 'refresh_expires_at' => now()->subDay()]);
    expect(fn () => (new TikTokConnector)->refresh($old))->toThrow(ReconnectRequiredException::class, '365 dias');

    tiktokFake(['open.tiktokapis.com/v2/oauth/token/' => Http::response('down', 503)]);
    try {
        (new TikTokConnector)->refresh($account);
        $this->fail('Se esperaba una excepcion');
    } catch (ConnectorException $e) {
        expect($e)->not->toBeInstanceOf(ReconnectRequiredException::class);
    }
});

test('TikTokOAuth controller flow connects the account and keeps direct_post across reconnection', function () {
    tiktokFake();
    $this->actingAs($this->user);

    $this->post(route('brands.connections.connect', ['brand' => $this->brand, 'provider' => 'tiktok'], false))
        ->assertRedirectContains('https://www.tiktok.com/v2/auth/authorize/');
    $this->get('/connections/tiktok/callback?code=CODE&state='.session('oauth.tiktok.state'))
        ->assertRedirect(route('brands.connections.index', $this->brand, false));

    $account = SocialAccount::first();
    expect($account->network)->toBe(Network::TikTok)->and($account->status)->toBe('activa')->and($account->meta['direct_post'] ?? false)->toBeFalse();

    $account->update(['meta' => array_merge($account->meta, ['direct_post' => true])]);
    $this->post(route('brands.connections.connect', ['brand' => $this->brand, 'provider' => 'tiktok'], false));
    $this->get('/connections/tiktok/callback?code=CODE&state='.session('oauth.tiktok.state'));

    expect(SocialAccount::count())->toBe(1)->and(SocialAccount::first()->meta['direct_post'])->toBeTrue();
});
