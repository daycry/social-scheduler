<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\Support\FakeConnector;

afterEach(fn () => TrustProxies::flushState());

test('behind a trusted TLS proxy route() builds https URLs so the OAuth redirect_uri matches the registered one', function () {
    TrustProxies::at('*');
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
