<?php

use App\Connectors\Contracts\Connector;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Meta\MetaConnector;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.meta.client_id' => 'APP123',
        'services.meta.client_secret' => 'SECRET456',
        'social.meta.graph_version' => 'v23.0',
    ]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

function metaFake(array $over = []): void
{
    Http::swap(new Factory);
    $dataAccess = now()->addDays(90)->timestamp;
    Http::fake(array_merge([
        'graph.facebook.com/v23.0/oauth/access_token*' => function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response(($q['grant_type'] ?? '') === 'fb_exchange_token'
                ? ['access_token' => 'LONG-USER-TOKEN', 'expires_in' => 5184000]
                : ['access_token' => 'SHORT-USER-TOKEN']);
        },
        'graph.facebook.com/v23.0/debug_token*' => Http::response(['data' => [
            'is_valid' => true, 'expires_at' => 0, 'data_access_expires_at' => $dataAccess,
            'scopes' => ['pages_show_list', 'pages_manage_posts', 'instagram_basic', 'business_management'],
        ]]),
        'graph.facebook.com/v23.0/me/accounts*' => Http::response(['data' => []]),
        'graph.facebook.com/v23.0/me/businesses*' => Http::response(['data' => [['id' => 'B1', 'name' => 'aipocalipsis']]]),
        'graph.facebook.com/v23.0/B1/owned_pages*' => Http::response(['data' => [[
            'id' => '1065269806670394', 'name' => 'Aipocalipsis', 'access_token' => 'PAGE-TOKEN-1',
            'instagram_business_account' => ['id' => '17841400000000000', 'username' => 'aipocalipsis_oficial'],
        ], [
            'id' => '2222', 'name' => 'Solo Facebook', 'access_token' => 'PAGE-TOKEN-2',
        ]]]),
        'graph.facebook.com/v23.0/B1/client_pages*' => Http::response(['data' => []]),
    ], $over));
}

test('MetaOAuth authorization URL uses the fixed Graph version, scopes and rerequest', function () {
    $url = (new MetaConnector)->authorizationUrl('STATE1', 'https://app.test/connections/meta/callback', rerequest: true);

    expect($url)->toStartWith('https://www.facebook.com/v23.0/dialog/oauth?')
        ->toContain('client_id=APP123')->toContain('state=STATE1')->toContain('auth_type=rerequest')
        ->toContain('business_management')->toContain('instagram_content_publish')->toContain('instagram_manage_contents');
    expect((new MetaConnector)->authorizationUrl('S', 'https://x/cb'))->not->toContain('auth_type');
});

test('MetaOAuth exchanges the code for a long lived token and finds pages through business portfolios and linked IG', function () {
    metaFake();

    $candidates = (new MetaConnector)->exchangeCode('CODE', 'https://app.test/connections/meta/callback');

    expect($candidates)->toHaveCount(3);
    expect(collect($candidates)->map->key()->all())->toBe(['facebook:1065269806670394', 'instagram:17841400000000000', 'facebook:2222']);

    $ig = $candidates[1];
    expect($ig->network)->toBe(Network::Instagram)->and($ig->username)->toBe('aipocalipsis_oficial')
        ->and($ig->accessToken)->toBe('PAGE-TOKEN-1')->and($ig->refreshToken)->toBe('LONG-USER-TOKEN')
        ->and($ig->meta['page_id'])->toBe('1065269806670394')
        ->and($ig->dataAccessExpiresAt->isFuture())->toBeTrue()
        ->and($ig->tokenExpiresAt->isFuture())->toBeTrue();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'fb_exchange_token') && str_contains($r->url(), 'SHORT-USER-TOKEN'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'me/businesses'));
});

test('MetaOAuth fails with a readable message when the user manages no pages', function () {
    metaFake(['graph.facebook.com/v23.0/me/businesses*' => Http::response(['data' => []])]);

    expect(fn () => (new MetaConnector)->exchangeCode('CODE', 'https://x/cb'))
        ->toThrow(ConnectorException::class, 'No se encontro ninguna Pagina');
});

test('MetaOAuth rejects the connection when critical permissions were not granted', function () {
    metaFake(['graph.facebook.com/v23.0/debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => ['pages_show_list']]])]);

    expect(fn () => (new MetaConnector)->exchangeCode('CODE', 'https://x/cb'))
        ->toThrow(ConnectorException::class, 'pages_manage_posts');
});

test('MetaOAuth maps a permissions error from Graph to a readable message', function () {
    metaFake(['graph.facebook.com/v23.0/me/accounts*' => Http::response(['error' => ['message' => '(#200) Requires pages_show_list permission', 'code' => 200]], 403)]);

    expect(fn () => (new MetaConnector)->exchangeCode('CODE', 'https://x/cb'))
        ->toThrow(ConnectorException::class, 'Permisos insuficientes en Meta');
});

test('MetaOAuth refresh renews the long lived token, re-reads the page token and updates data access', function () {
    metaFake([
        'graph.facebook.com/v23.0/1065269806670394*' => Http::response(['access_token' => 'PAGE-TOKEN-NEW', 'id' => '1065269806670394']),
    ]);
    $account = SocialAccount::factory()->for($this->brand)->create([
        'network' => Network::Facebook, 'external_id' => '1065269806670394', 'access_token' => 'PAGE-TOKEN-OLD',
        'refresh_token' => 'LONG-OLD', 'meta' => ['page_id' => '1065269806670394'],
    ]);

    $result = (new MetaConnector)->refresh($account);

    expect($result->accessToken)->toBe('PAGE-TOKEN-NEW')->and($result->refreshToken)->toBe('LONG-USER-TOKEN')
        ->and($result->tokenExpiresAt->isFuture())->toBeTrue()->and($result->dataAccessExpiresAt->isFuture())->toBeTrue();
});

test('MetaOAuth refresh requires reconnection when Meta invalidates the token or data access expired', function () {
    $account = SocialAccount::factory()->for($this->brand)->create(['network' => Network::Facebook, 'refresh_token' => 'LONG-OLD']);

    metaFake(['graph.facebook.com/v23.0/oauth/access_token*' => Http::response(['error' => ['message' => 'Error validating access token', 'code' => 190]], 400)]);
    expect(fn () => (new MetaConnector)->refresh($account))->toThrow(ReconnectRequiredException::class);

    $expired = SocialAccount::factory()->for($this->brand)->create(['data_access_expires_at' => now()->subDay()]);
    expect(fn () => (new MetaConnector)->refresh($expired))->toThrow(ReconnectRequiredException::class, '90 dias');
});

test('MetaOAuth refresh treats a Graph outage as a transient error', function () {
    metaFake(['graph.facebook.com/v23.0/oauth/access_token*' => Http::response('Bad gateway', 502)]);
    $account = SocialAccount::factory()->for($this->brand)->create(['refresh_token' => 'LONG-OLD']);

    try {
        (new MetaConnector)->refresh($account);
        $this->fail('Se esperaba una excepcion');
    } catch (ConnectorException $e) {
        expect($e)->not->toBeInstanceOf(ReconnectRequiredException::class);
    }
});

test('MetaOAuth controller flow: redirect stores state, callback with several accounts asks the user to choose and select connects', function () {
    metaFake();
    $this->actingAs($this->user);

    $redirect = $this->post(route('brands.connections.connect', ['brand' => $this->brand, 'provider' => 'meta'], false));
    $redirect->assertRedirectContains('https://www.facebook.com/v23.0/dialog/oauth');
    $state = session('oauth.meta.state');
    expect($state)->toHaveLength(40);

    $this->get('/connections/meta/callback?code=CODE&state='.$state)
        ->assertRedirect(route('brands.connections.index', $this->brand, false));

    expect(SocialAccount::count())->toBe(0);

    $page = $this->get(route('brands.connections.index', $this->brand, false));
    $page->assertInertia(fn ($p) => $p->component('Brands/Connections')
        ->has('pending.candidates', 3)
        ->where('pending.provider', 'meta'));
    expect($page->getContent())->not->toContain('PAGE-TOKEN')->not->toContain('LONG-USER-TOKEN');

    $this->post(route('brands.connections.select', $this->brand, false), ['keys' => ['facebook:1065269806670394', 'instagram:17841400000000000']])
        ->assertRedirect();

    $accounts = SocialAccount::orderBy('network')->get();
    expect($accounts)->toHaveCount(2)
        ->and($accounts->pluck('network')->map->value->all())->toBe(['facebook', 'instagram'])
        ->and($accounts->every(fn ($a) => $a->status === 'activa' && $a->brand_id === $this->brand->id))->toBeTrue()
        ->and($accounts[1]->access_token)->toBe('PAGE-TOKEN-1');
});

test('MetaOAuth callback rejects an invalid state and a cancelled authorization', function () {
    metaFake();
    $this->actingAs($this->user);

    $this->get('/connections/meta/callback?code=CODE&state=forged')->assertRedirect(route('brands.index', absolute: false));
    expect(SocialAccount::count())->toBe(0);

    $this->post(route('brands.connections.connect', ['brand' => $this->brand, 'provider' => 'meta'], false));
    $state = session('oauth.meta.state');
    $this->get('/connections/meta/callback?error=access_denied&state='.$state)
        ->assertRedirect(route('brands.connections.index', $this->brand, false));
    expect(session('error'))->toContain('cancelada');
    expect(SocialAccount::count())->toBe(0);
});

test('MetaOAuth another user cannot start a connection on a brand they do not own', function () {
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->post(route('brands.connections.connect', ['brand' => $this->brand, 'provider' => 'meta'], false))
        ->assertNotFound();
});

test('MetaOAuth connector implements the common contract', function () {
    expect(new MetaConnector)->toBeInstanceOf(Connector::class)->and((new MetaConnector)->refreshMarginHours())->toBe(168);
});

test('MetaOAuth follows paging.next on pages and businesses up to the configured cap', function () {
    metaFake([
        'graph.facebook.com/v23.0/me/accounts*' => fn ($request) => str_contains($request->url(), 'after=A')
            ? Http::response(['data' => [['id' => 'P2', 'name' => 'Dos', 'access_token' => 'T2']]])
            : Http::response(['data' => [['id' => 'P1', 'name' => 'Uno', 'access_token' => 'T1']], 'paging' => ['next' => 'https://graph.facebook.com/v23.0/me/accounts?after=A']]),
        'graph.facebook.com/v23.0/me/businesses*' => Http::response(['data' => []]),
    ]);

    $keys = collect((new MetaConnector)->exchangeCode('CODE', 'https://x/cb'))->map->key()->all();

    expect($keys)->toBe(['facebook:P1', 'facebook:P2']);
});

test('MetaOAuth stops following paging.next at the page cap and ignores foreign hosts', function () {
    config(['social.meta.max_pages' => 2]);
    $loop = fn (string $next) => Http::response(['data' => [['id' => 'P'.random_int(1, 999999), 'name' => 'X', 'access_token' => 'T']], 'paging' => ['next' => $next]]);
    metaFake([
        'graph.facebook.com/v23.0/me/accounts*' => $loop('https://graph.facebook.com/v23.0/me/accounts?after=LOOP'),
        'graph.facebook.com/v23.0/me/businesses*' => Http::response(['data' => []]),
    ]);

    (new MetaConnector)->exchangeCode('CODE', 'https://x/cb');
    Http::assertSentCount(1 + 1 + 1 + 2 + 1); // oauth, exchange, debug, 2 paginas de me/accounts, me/businesses

    metaFake([
        'graph.facebook.com/v23.0/me/accounts*' => Http::response(['data' => [['id' => 'P1', 'name' => 'Uno', 'access_token' => 'T1']], 'paging' => ['next' => 'https://evil.test/steal?x=1']]),
        'graph.facebook.com/v23.0/me/businesses*' => Http::response(['data' => []]),
    ]);
    (new MetaConnector)->exchangeCode('CODE', 'https://x/cb');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.test'));
});

test('MetaOAuth treats a failing debug_token as a readable error instead of skipping the permission check', function () {
    metaFake(['graph.facebook.com/v23.0/debug_token*' => Http::response('Bad gateway', 502)]);

    expect(fn () => (new MetaConnector)->exchangeCode('CODE', 'https://x/cb'))
        ->toThrow(ConnectorException::class, 'comprobar los permisos');
});

test('MetaOAuth offers Instagram with a permission warning when instagram_content_publish was not granted', function () {
    metaFake();
    $candidates = collect((new MetaConnector)->exchangeCode('CODE', 'https://x/cb'));

    $ig = $candidates->first(fn ($c) => $c->network === Network::Instagram);
    expect($ig->meta['missing_scopes'])->toBe(['instagram_content_publish'])
        ->and($ig->toPublicArray()['missing_scopes'])->toBe(['instagram_content_publish']);

    metaFake(['graph.facebook.com/v23.0/debug_token*' => Http::response(['data' => [
        'is_valid' => true, 'data_access_expires_at' => now()->addDays(90)->timestamp,
        'scopes' => ['pages_show_list', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish'],
    ]])]);
    $ig = collect((new MetaConnector)->exchangeCode('CODE', 'https://x/cb'))->first(fn ($c) => $c->network === Network::Instagram);
    expect($ig->meta['missing_scopes'])->toBe([]);
});

test('MetaOAuth refresh treats a revoked Page token (190) as permanent instead of keeping the stale one', function () {
    metaFake(['graph.facebook.com/v23.0/P*' => Http::response(['error' => ['message' => 'Error validating access token', 'code' => 190]], 400)]);
    $fb = SocialAccount::factory()->for($this->brand)->create(['network' => Network::Facebook, 'refresh_token' => 'USER-TOKEN', 'meta' => ['page_id' => 'P']]);

    expect(fn () => (new MetaConnector)->refresh($fb))->toThrow(ReconnectRequiredException::class);
});

test('MetaOAuth refresh shares one token exchange between accounts that share the user token', function () {
    metaFake(['graph.facebook.com/v23.0/P*' => Http::response(['access_token' => 'PAGE-TOKEN-NEW', 'id' => 'P'])]);
    $fb = SocialAccount::factory()->for($this->brand)->create(['network' => Network::Facebook, 'refresh_token' => 'SHARED-USER-TOKEN', 'meta' => ['page_id' => 'P']]);
    $ig = SocialAccount::factory()->for($this->brand)->create(['network' => Network::Instagram, 'refresh_token' => 'SHARED-USER-TOKEN', 'meta' => ['page_id' => 'P']]);

    (new MetaConnector)->refresh($fb);
    (new MetaConnector)->refresh($ig);

    $exchanges = Http::recorded(fn ($req) => str_contains($req->url(), 'fb_exchange_token'));
    expect($exchanges)->toHaveCount(1);
});
