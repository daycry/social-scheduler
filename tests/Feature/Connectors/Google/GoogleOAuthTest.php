<?php

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Google\GoogleConnector;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.google.client_id' => 'GID', 'services.google.client_secret' => 'GSECRET']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

function googleFake(array $over = []): void
{
    Http::swap(new Factory);
    Http::fake(array_merge([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'G-ACCESS', 'refresh_token' => 'G-REFRESH', 'expires_in' => 3599,
            'scope' => 'https://www.googleapis.com/auth/youtube https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/yt-analytics.readonly',
        ]),
        'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [
            ['id' => 'UC123', 'snippet' => ['title' => 'Jordi daycry', 'customUrl' => '@daycry']],
        ]]),
    ], $over));
}

test('GoogleOAuth authorization URL requests offline access, forced consent and the YouTube scopes', function () {
    $url = (new GoogleConnector)->authorizationUrl('ST', 'https://app.test/connections/google/callback');

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->toContain('access_type=offline')->toContain('prompt=consent')->toContain('state=ST')
        ->toContain(urlencode('https://www.googleapis.com/auth/yt-analytics.readonly'))
        ->toContain(urlencode('https://www.googleapis.com/auth/youtube.readonly'));
});

test('GoogleOAuth exchanges the code and returns the channel with its refresh token', function () {
    googleFake();

    $candidates = (new GoogleConnector)->exchangeCode('CODE', 'https://app.test/connections/google/callback');

    expect($candidates)->toHaveCount(1);
    $c = $candidates[0];
    expect($c->network)->toBe(Network::YouTube)->and($c->externalId)->toBe('UC123')->and($c->name)->toBe('Jordi daycry')
        ->and($c->username)->toBe('@daycry')->and($c->accessToken)->toBe('G-ACCESS')->and($c->refreshToken)->toBe('G-REFRESH')
        ->and($c->tokenExpiresAt->isFuture())->toBeTrue();

    Http::assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['grant_type'] === 'authorization_code' && $r['code'] === 'CODE');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'youtube/v3/channels') && $r->hasHeader('Authorization', 'Bearer G-ACCESS'));
});

test('GoogleOAuth fails readably when Google returns no refresh token, no channel or lacks the youtube scope', function () {
    googleFake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'A', 'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/youtube'])]);
    expect(fn () => (new GoogleConnector)->exchangeCode('C', 'https://x/cb'))->toThrow(ConnectorException::class, 'token de renovacion');

    googleFake(['www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => []])]);
    expect(fn () => (new GoogleConnector)->exchangeCode('C', 'https://x/cb'))->toThrow(ConnectorException::class, 'ningun canal');

    googleFake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'A', 'refresh_token' => 'R', 'scope' => 'https://www.googleapis.com/auth/youtube.readonly'])]);
    expect(fn () => (new GoogleConnector)->exchangeCode('C', 'https://x/cb'))->toThrow(ConnectorException::class, 'permiso de gestion');
});

test('GoogleOAuth refresh obtains a new access token and keeps the refresh token', function () {
    googleFake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'G-ACCESS-2', 'expires_in' => 3599])]);
    $account = SocialAccount::factory()->for($this->brand)->network(Network::YouTube)->create(['refresh_token' => 'G-REFRESH']);

    $result = (new GoogleConnector)->refresh($account);

    expect($result->accessToken)->toBe('G-ACCESS-2')->and($result->refreshToken)->toBeNull()->and($result->tokenExpiresAt->isFuture())->toBeTrue();
    Http::assertSent(fn ($r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'G-REFRESH');
});

test('GoogleOAuth invalid_grant (revoked or 7 day Testing expiry) requires reconnection, a 503 is transient', function () {
    $account = SocialAccount::factory()->for($this->brand)->network(Network::YouTube)->create(['refresh_token' => 'G-REFRESH']);

    googleFake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
    expect(fn () => (new GoogleConnector)->refresh($account))->toThrow(ReconnectRequiredException::class);

    googleFake(['oauth2.googleapis.com/token' => Http::response('unavailable', 503)]);
    try {
        (new GoogleConnector)->refresh($account);
        $this->fail('Se esperaba una excepcion');
    } catch (ConnectorException $e) {
        expect($e)->not->toBeInstanceOf(ReconnectRequiredException::class);
    }
});

test('GoogleOAuth controller flow connects a single channel directly to the brand', function () {
    googleFake();
    $this->actingAs($this->user);

    $this->get(route('brands.connections.connect', ['brand' => $this->brand, 'provider' => 'google'], false))
        ->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth');
    $state = session('oauth.google.state');

    $this->get('/connections/google/callback?code=CODE&state='.$state)
        ->assertRedirect(route('brands.connections.index', $this->brand, false));

    $account = SocialAccount::first();
    expect($account->network)->toBe(Network::YouTube)->and($account->status)->toBe('activa')
        ->and($account->brand_id)->toBe($this->brand->id)->and($account->refresh_token)->toBe('G-REFRESH');
});
