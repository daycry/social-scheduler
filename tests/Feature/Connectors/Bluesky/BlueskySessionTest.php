<?php

use App\Connectors\Bluesky\BlueskyConnector;
use App\Connectors\Bluesky\BlueskyException;
use App\Connectors\Bluesky\BlueskySession;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Jobs\RefreshSocialAccount;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BlueskyFake;

const BSKY_DID = BlueskyFake::DID;
const BSKY_PDS = BlueskyFake::PDS;

function bskyJwt(int $expiresIn = 7200): string
{
    return BlueskyFake::jwt($expiresIn);
}

function fakeBskyConnect(array $over = []): void
{
    BlueskyFake::connect($over);
}

function bskyAccount(array $over = []): SocialAccount
{
    return BlueskyFake::account($over);
}

beforeEach(function () {
    $this->connector = app(BlueskyConnector::class);
});

test('conectar guarda tokens y app password cifrados, con external_id = DID', function () {
    fakeBskyConnect();
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();

    $candidates = $this->connector->connectWithCredentials(['handle' => '@Ana.Example.com', 'app_password' => 'abcd-efgh-ijkl-mnop']);
    $account = app(SocialAccountService::class)->connect($user, $brand, $candidates[0]);

    expect($candidates)->toHaveCount(1)
        ->and($account->network)->toBe(Network::Bluesky)
        ->and($account->external_id)->toBe(BSKY_DID)
        ->and($account->meta)->toMatchArray(['did' => BSKY_DID, 'pds' => BSKY_PDS, 'handle' => 'ana.example.com', 'email_confirmed' => true])
        ->and($account->refresh_expires_at)->toBeNull()
        ->and($account->token_expires_at)->not->toBeNull();

    $raw = json_encode(DB::table('social_accounts')->first());
    expect($raw)->not->toContain('abcd-efgh-ijkl-mnop')->not->toContain('REFRESH-1');
    expect(SocialAccount::withoutUserScope()->first()->credential_secret)->toBe('abcd-efgh-ijkl-mnop');
    expect(json_encode($candidates[0]->toPublicArray()))->not->toContain('abcd-efgh');
});

test('una app password invalida da un error claro sin la contrasena', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => BSKY_DID]),
        'plc.directory/*' => Http::response(['service' => [['id' => '#atproto_pds', 'serviceEndpoint' => BSKY_PDS]]]),
        'pds.example.com/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'AuthenticationRequired', 'message' => 'Invalid identifier or password'], 401),
    ]);

    try {
        $this->connector->connectWithCredentials(['handle' => 'ana.example.com', 'app_password' => 'SECRET-WRONG-1']);
        $this->fail('Debia lanzar ConnectorException');
    } catch (ConnectorException $e) {
        expect($e->getMessage())->toContain('app password')->not->toContain('SECRET-WRONG-1');
    }
});

test('un handle que no resuelve se rechaza', function () {
    Http::fake(['bsky.social/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['error' => 'InvalidRequest', 'message' => 'Unable to resolve handle'], 400)]);

    expect(fn () => $this->connector->connectWithCredentials(['handle' => 'nadie.example.com', 'app_password' => 'x-x-x-x']))
        ->toThrow(ConnectorException::class);
});

test('un PDS que no es https o es una IP se rechaza', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => BSKY_DID]),
        'plc.directory/*' => Http::response(['service' => [['id' => '#atproto_pds', 'serviceEndpoint' => 'http://169.254.169.254']]]),
    ]);

    expect(fn () => $this->connector->connectWithCredentials(['handle' => 'ana.example.com', 'app_password' => 'x-x-x-x']))
        ->toThrow(ConnectorException::class);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254'));
});

test('refresh usa refreshSession y guarda el refreshJwt nuevo', function () {
    $account = bskyAccount();
    $new = bskyJwt(7200);
    Http::fake(['pds.example.com/xrpc/com.atproto.server.refreshSession' => Http::response(['accessJwt' => $new, 'refreshJwt' => 'NEW-REFRESH', 'did' => BSKY_DID])]);

    $result = $this->connector->refresh($account);

    expect($result->accessToken)->toBe($new)->and($result->refreshToken)->toBe('NEW-REFRESH')
        ->and($result->refreshExpiresAt)->toBeNull()->and($result->tokenExpiresAt)->not->toBeNull();
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer OLD-REFRESH'));
});

test('ExpiredToken en refreshSession recrea la sesion con la app password sin cortar la cuenta', function () {
    $account = bskyAccount();
    $new = bskyJwt(7200);
    Http::fake([
        'pds.example.com/xrpc/com.atproto.server.refreshSession' => Http::response(['error' => 'ExpiredToken', 'message' => 'Token has expired'], 400),
        'pds.example.com/xrpc/com.atproto.server.createSession' => Http::response(['accessJwt' => $new, 'refreshJwt' => 'RECREATED-REFRESH', 'did' => BSKY_DID]),
    ]);

    $result = $this->connector->refresh($account);

    expect($result->accessToken)->toBe($new)->and($result->refreshToken)->toBe('RECREATED-REFRESH');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), 'createSession') && $r['password'] === 'APP-PASS-WORD-1234' && $r['identifier'] === BSKY_DID);
});

test('si la app password tambien falla pide reconectar', function () {
    $account = bskyAccount();
    Http::fake([
        'pds.example.com/xrpc/com.atproto.server.refreshSession' => Http::response(['error' => 'InvalidToken', 'message' => 'bad'], 400),
        'pds.example.com/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'AuthenticationRequired', 'message' => 'Invalid identifier or password'], 401),
    ]);

    expect(fn () => $this->connector->refresh($account))->toThrow(ReconnectRequiredException::class);
});

test('un 5xx al renovar es transitorio, no pide reconectar', function () {
    $account = bskyAccount();
    Http::fake(['pds.example.com/xrpc/com.atproto.server.refreshSession' => Http::response('boom', 502)]);

    try {
        $this->connector->refresh($account);
        $this->fail('Debia lanzar');
    } catch (ConnectorException $e) {
        expect($e)->not->toBeInstanceOf(ReconnectRequiredException::class);
    }
});

test('si otro proceso ya renovo la sesion, no se llama a la red', function () {
    $account = bskyAccount();
    $stale = SocialAccount::withoutUserScope()->find($account->id);
    $account->forceFill(['access_token' => 'RENEWED-BY-OTHER', 'token_expires_at' => now()->addHours(2)])->save();
    Http::fake();

    $result = $this->connector->refresh($stale);

    expect($result->accessToken)->toBe('RENEWED-BY-OTHER');
    Http::assertNothingSent();
});

test('call() renueva y repite una vez ante ExpiredToken, guardando la sesion nueva', function () {
    $account = bskyAccount();
    $new = bskyJwt(7200);
    Http::fake(['pds.example.com/xrpc/com.atproto.server.refreshSession' => Http::response(['accessJwt' => $new, 'refreshJwt' => 'NEW-REFRESH', 'did' => BSKY_DID])]);
    $attempts = [];

    $out = app(BlueskySession::class)->call($account, function (string $token) use (&$attempts) {
        $attempts[] = $token;
        if ($token === 'OLD-ACCESS') {
            throw new BlueskyException('expired', 'ExpiredToken', 400);
        }

        return 'ok';
    });

    expect($out)->toBe('ok')->and($attempts)->toBe(['OLD-ACCESS', $new])
        ->and(SocialAccount::withoutUserScope()->find($account->id)->access_token)->toBe($new);
});

test('call() no reintenta ante un error que no es de sesion', function () {
    $account = bskyAccount();
    Http::fake();

    expect(fn () => app(BlueskySession::class)->call($account, fn () => throw new BlueskyException('x', 'InvalidRequest', 400)))
        ->toThrow(BlueskyException::class);
    Http::assertNothingSent();
});

test('regresion: RefreshSocialAccount no marca reconexion con refresh_expires_at nulo y renueva', function () {
    $account = bskyAccount(['token_expires_at' => now()->subMinutes(5)]);
    $registry = new ConnectorRegistry;
    $registry->register($this->connector);
    $new = bskyJwt(7200);
    Http::fake(['pds.example.com/xrpc/com.atproto.server.refreshSession' => Http::response(['accessJwt' => $new, 'refreshJwt' => 'NEW-REFRESH', 'did' => BSKY_DID])]);

    (new RefreshSocialAccount($account->id))->handle($registry, app(SocialAccountService::class));

    $fresh = SocialAccount::withoutUserScope()->find($account->id);
    expect($fresh->status)->toBe(SocialAccount::STATUS_ACTIVE)
        ->and($fresh->access_token)->toBe($new)
        ->and($fresh->refresh_token)->toBe('NEW-REFRESH')
        ->and($fresh->refresh_expires_at)->toBeNull()
        ->and($fresh->credential_secret)->toBe('APP-PASS-WORD-1234');
});
