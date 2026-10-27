<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\OAuthConnector;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Meta\GraphErrors;
use App\Connectors\Network;
use App\Connectors\Threads\ThreadsConnector;
use App\Jobs\RefreshSocialAccount;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAccountService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ThreadsFake;

beforeEach(function () {
    Http::preventStrayRequests();
    ThreadsFake::configure();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->connector = app(ThreadsConnector::class);
});

test('es un conector OAuth del proveedor threads con su red y su margen de renovacion', function () {
    expect($this->connector)->toBeInstanceOf(OAuthConnector::class)
        ->and($this->connector->provider())->toBe('threads')
        ->and($this->connector->networks())->toBe([Network::Threads])
        ->and($this->connector->refreshMarginHours())->toBe(168);
});

test('la URL de autorizacion usa threads.com, el ID propio de Threads y los permisos de publicar y de metricas', function () {
    $url = $this->connector->authorizationUrl('STATE1', 'https://app.test/connections/threads/callback');

    expect($url)->toStartWith('https://threads.com/oauth/authorize?')
        ->toContain('client_id=THAPP1')->toContain('state=STATE1')->toContain('response_type=code')
        ->toContain('redirect_uri='.urlencode('https://app.test/connections/threads/callback'))
        ->toContain('threads_basic')->toContain('threads_content_publish')->toContain('threads_manage_insights')
        ->not->toContain('THSECRET1');
});

test('canjea el codigo, pasa a token largo de 60 dias y lee el perfil (CA-07)', function () {
    ThreadsFake::connect();

    $candidates = $this->connector->exchangeCode('CODE#_', 'https://app.test/connections/threads/callback');

    expect($candidates)->toHaveCount(1);
    $c = $candidates[0];
    expect($c->network)->toBe(Network::Threads)->and($c->externalId)->toBe(ThreadsFake::USER_ID)
        ->and($c->name)->toBe('Ana Threads')->and($c->username)->toBe('ana.threads')
        ->and($c->accessToken)->toBe('TH-LONG-TOKEN')->and($c->refreshToken)->toBeNull()
        ->and($c->tokenExpiresAt->isFuture())->toBeTrue()
        ->and((int) round(now()->diffInDays($c->tokenExpiresAt, true)))->toBeBetween(59, 61)
        ->and($c->scopes)->toContain('threads_basic');

    // El codigo llega limpio de la "#_" final y el canje usa el secreto propio, en el CUERPO.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.threads.com/oauth/access_token')
        && $r->method() === 'POST' && ($r->data()['code'] ?? null) === 'CODE' && ($r->data()['client_secret'] ?? null) === 'THSECRET1'
        && ($r->data()['grant_type'] ?? null) === 'authorization_code' && ($r->data()['client_id'] ?? null) === 'THAPP1');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.threads.com/access_token') && str_contains($r->url(), 'th_exchange_token')
        && str_contains($r->url(), 'access_token=TH-SHORT'));
});

test('un error del canje se traduce a un mensaje legible', function () {
    Http::fake(['graph.threads.com/oauth/access_token*' => Http::response(['error_type' => 'OAuthException', 'code' => 400, 'error_message' => 'Matching code was not found or was already used'], 400)]);

    expect(fn () => $this->connector->exchangeCode('MAL', 'https://app.test/cb'))
        ->toThrow(ConnectorException::class, 'Threads');
});

test('refresh llama a th_refresh_token y devuelve el token con su nueva caducidad', function () {
    Http::fake(['graph.threads.com/refresh_access_token*' => Http::response(['access_token' => 'TH-NUEVO', 'token_type' => 'bearer', 'expires_in' => 5183944])]);
    $account = ThreadsFake::account(['token_expires_at' => now()->addDays(3)]);

    $r = $this->connector->refresh($account);

    expect($r->accessToken)->toBe('TH-NUEVO')->and($r->refreshToken)->toBeNull()
        ->and((int) round(now()->diffInDays($r->tokenExpiresAt, true)))->toBeBetween(59, 61);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.threads.com/refresh_access_token') && str_contains($r->url(), 'th_refresh_token'));
});

test('refresh con el token revocado pide reconectar', function () {
    Http::fake(['graph.threads.com/refresh_access_token*' => Http::response(['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190]], 400)]);
    $account = ThreadsFake::account();

    expect(fn () => $this->connector->refresh($account))->toThrow(ReconnectRequiredException::class);
});

test('un fallo del servidor al renovar es transitorio, no obliga a reconectar', function () {
    Http::fake(['graph.threads.com/refresh_access_token*' => Http::response('boom', 503)]);
    $account = ThreadsFake::account();

    try {
        $this->connector->refresh($account);
        $this->fail('debia lanzar');
    } catch (ConnectorException $e) {
        expect($e)->not->toBeInstanceOf(ReconnectRequiredException::class);
    }
});

test('RefreshSocialAccount renueva la cuenta antes del margen y guarda la nueva caducidad', function () {
    Http::fake(['graph.threads.com/refresh_access_token*' => Http::response(['access_token' => 'TH-RENOVADO', 'expires_in' => 5183944])]);
    app()->forgetInstance(ConnectorRegistry::class);
    $account = ThreadsFake::account(['token_expires_at' => now()->addHours(100), 'last_refreshed_at' => now()->subDays(30)]);

    expect(RefreshSocialAccount::needsWork($account, app(ConnectorRegistry::class)))->toBeTrue();
    (new RefreshSocialAccount($account->id))->handle(app(ConnectorRegistry::class), app(SocialAccountService::class));

    $fresh = $account->fresh();
    expect($fresh->access_token)->toBe('TH-RENOVADO')->and($fresh->token_expires_at->gt(now()->addDays(59)))->toBeTrue();
});

test('sin THREADS_APP_ID la red no se ofrece; con el, si', function () {
    config(['services.threads.client_id' => '']);
    app()->forgetInstance(ConnectorRegistry::class);
    expect(app(ConnectorRegistry::class)->has('threads'))->toBeFalse()
        ->and(Network::offered())->not->toContain(Network::Threads)
        ->and(Network::Threads->isAvailable())->toBeFalse();

    ThreadsFake::configure();
    app()->forgetInstance(ConnectorRegistry::class);
    expect(app(ConnectorRegistry::class)->has('threads'))->toBeTrue()
        ->and(Network::offered())->toContain(Network::Threads)
        ->and(Network::Threads->isAvailable())->toBeTrue();
});

test('THREADS_ENABLED=false retira la red aunque haya ID', function () {
    config(['social.threads.enabled' => false]);
    app()->forgetInstance(ConnectorRegistry::class);

    expect(app(ConnectorRegistry::class)->has('threads'))->toBeFalse()
        ->and(Network::offered())->not->toContain(Network::Threads)
        ->and(Network::Threads->isAvailable())->toBeFalse();
});

test('la ruta OAuth de Threads redirige a threads.com y el callback conecta la cuenta', function () {
    ThreadsFake::connect();
    app()->forgetInstance(ConnectorRegistry::class);

    $redirect = $this->actingAs($this->user)->post(route('brands.connections.connect', [$this->brand, 'threads']));
    expect($redirect->headers->get('Location'))->toStartWith('https://threads.com/oauth/authorize?');

    $state = session('oauth.threads.state');
    $this->actingAs($this->user)->get(route('connections.callback', ['provider' => 'threads', 'code' => 'ABC#_', 'state' => $state]))
        ->assertRedirect(route('brands.connections.index', $this->brand))->assertSessionHas('success');

    $account = SocialAccount::withoutUserScope()->where('network', 'threads')->firstOrFail();
    expect($account->access_token)->toBe('TH-LONG-TOKEN')->and($account->token_expires_at->isFuture())->toBeTrue()
        ->and($account->refresh_token)->toBeNull();
});

test('regresion: GraphErrors conserva la clasificacion de codigos que tenia GraphClient', function () {
    foreach ([102, 190, 458, 459, 460, 463, 464, 467] as $code) {
        expect(GraphErrors::needsReconnect($code))->toBeTrue("reauth $code");
    }
    foreach ([4, 17, 32, 613, 80000, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014] as $code) {
        expect(GraphErrors::isRateLimit($code))->toBeTrue("limite $code");
    }
    expect(GraphErrors::needsReconnect(100))->toBeFalse()->and(GraphErrors::isRateLimit(190))->toBeFalse();
});
