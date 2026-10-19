<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Http\Middleware\TrustProxiesFromConfig;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\Support\FakeConnector;

afterEach(fn () => TrustProxies::flushState());

test('behind a trusted TLS proxy route() builds https URLs so the OAuth redirect_uri matches the registered one', function () {
    // Se configura igual que en produccion: config('app.trusted_proxies') <- TRUSTED_PROXIES del .env.
    config(['app.trusted_proxies' => '*']);
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->register(new FakeConnector('meta', [Network::Instagram])));
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();

    $response = $this->actingAs($user)
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'demo.ngrok.app', 'X-Forwarded-Port' => '443'])
        ->post(route('brands.connections.connect', [$brand, 'meta'], false));

    $response->assertRedirectContains('fake.test');
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['redirect_uri'])->toBe('https://demo.ngrok.app/connections/meta/callback');
});

test('without trusted proxies the forwarded headers are ignored', function () {
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->register(new FakeConnector('meta', [Network::Instagram])));
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();

    $response = $this->actingAs($user)->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.test'])
        ->post(route('brands.connections.connect', [$brand, 'meta'], false));

    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['redirect_uri'])->toStartWith('http://')->not->toContain('evil.test');
});

test('TRUSTED_PROXIES is read from config at request time, not at bootstrap', function () {
    $middleware = new TrustProxiesFromConfig;
    $proxies = fn () => (fn () => $this->proxies())->call($middleware);

    config(['app.trusted_proxies' => '']);
    expect($proxies())->toBeNull();

    config(['app.trusted_proxies' => '10.0.0.1, 10.0.0.0/8']);
    expect($proxies())->toBe(['10.0.0.1', '10.0.0.0/8']);
});

test('outside local/testing only the APP_URL host is trusted, so a forged host cannot poison reset links', function () {
    config(['app.url' => 'https://scheduler.example.com']);

    $hosts = (new TrustHosts($this->app))->hosts();

    expect($hosts)->toContain('^(.+\.)?scheduler\.example\.com$')
        ->and(collect($hosts)->contains(fn ($h) => preg_match('/'.$h.'/', 'evil.test')))->toBeFalse();
});
