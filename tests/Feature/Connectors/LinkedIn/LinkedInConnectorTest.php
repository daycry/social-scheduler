<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\OAuthConnector;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\LinkedIn\LinkedInConnector;
use App\Connectors\Network;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\NewNetworksFake;

beforeEach(function () {
    Http::preventStrayRequests();
    NewNetworksFake::linkedin();
    $this->connector = app(LinkedInConnector::class);
});

test('es un conector OAuth del proveedor linkedin, con su red y margen de renovacion', function () {
    expect($this->connector)->toBeInstanceOf(OAuthConnector::class)
        ->and($this->connector->provider())->toBe('linkedin')
        ->and($this->connector->networks())->toBe([Network::LinkedIn])
        ->and($this->connector->refreshMarginHours())->toBe(168);
});

test('la URL de autorizacion lleva el state, el redirect y los permisos de perfil y de paginas, sin secreto', function () {
    $url = $this->connector->authorizationUrl('STATE1', 'https://app.test/connections/linkedin/callback');

    expect($url)->toStartWith('https://www.linkedin.com/oauth/v2/authorization?')
        ->toContain('response_type=code')->toContain('client_id=LIAPP1')->toContain('state=STATE1')
        ->toContain('redirect_uri='.rawurlencode('https://app.test/connections/linkedin/callback'))
        ->toContain('w_member_social')->toContain('w_organization_social')->toContain('openid')
        ->not->toContain('LISECRET1');
});

test('canjea el codigo y ofrece el perfil y las paginas que administra', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'LI-NEW', 'expires_in' => 5184000, 'scope' => 'openid,profile,w_member_social,w_organization_social']),
        'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc123', 'name' => 'Ana Lopez']),
        'api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => [['organization' => 'urn:li:organization:777'], ['organization' => 'urn:li:organization:evil;drop']]]),
        'api.linkedin.com/rest/organizations/777' => Http::response(['localizedName' => 'Acme SL', 'vanityName' => 'acme']),
    ]);

    $candidates = $this->connector->exchangeCode('CODE1', 'https://app.test/connections/linkedin/callback');

    expect($candidates)->toHaveCount(2);
    [$profile, $page] = $candidates;
    expect($profile->network)->toBe(Network::LinkedIn)->and($profile->externalId)->toBe('abc123')->and($profile->name)->toBe('Ana Lopez')
        ->and($profile->meta)->toBe(['kind' => 'profile', 'author_urn' => 'urn:li:person:abc123'])
        ->and($profile->accessToken)->toBe('LI-NEW')->and($profile->tokenExpiresAt->isFuture())->toBeTrue()
        ->and($profile->scopes)->toContain('w_member_social')
        ->and($page->externalId)->toBe('org-777')->and($page->name)->toBe('Acme SL')->and($page->username)->toBe('acme')
        ->and($page->meta)->toBe(['kind' => 'page', 'author_urn' => 'urn:li:organization:777']);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/v2/accessToken') && $r->method() === 'POST'
        && $r['grant_type'] === 'authorization_code' && $r['code'] === 'CODE1' && $r['client_secret'] === 'LISECRET1');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rest/organizationAcls') && $r->hasHeader('LinkedIn-Version') && $r->header('Authorization') === ['Bearer LI-NEW']);
});

test('sin los permisos de Community Management solo se ofrece el perfil', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'LI-NEW', 'expires_in' => 5184000]),
        'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc123', 'given_name' => 'Ana', 'family_name' => 'Lopez']),
        'api.linkedin.com/rest/organizationAcls*' => Http::response(['message' => 'Not enough permissions'], 403),
    ]);

    $candidates = $this->connector->exchangeCode('CODE1', 'https://app.test/cb');

    expect($candidates)->toHaveCount(1)->and($candidates[0]->name)->toBe('Ana Lopez');
});

test('un error del canje se traduce a un mensaje legible', function () {
    Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_request', 'error_description' => 'redirect_uri does not match'], 400)]);
    expect(fn () => $this->connector->exchangeCode('MAL', 'https://app.test/cb'))->toThrow(ConnectorException::class, 'LinkedIn rechazo la conexion');
});

test('un codigo invalido o caducado pide reconectar', function () {
    Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_grant', 'error_description' => 'expired'], 400)]);
    expect(fn () => $this->connector->exchangeCode('MAL', 'https://app.test/cb'))->toThrow(ReconnectRequiredException::class);
});

test('refresh con refresh_token lo canjea y guarda el nuevo; sin el pide reconectar', function () {
    Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'LI-2', 'expires_in' => 5184000, 'refresh_token' => 'LI-R2', 'refresh_token_expires_in' => 31536000])]);
    $account = NewNetworksFake::linkedinAccount(['refresh_token' => 'LI-R1']);

    $r = $this->connector->refresh($account);

    expect($r->accessToken)->toBe('LI-2')->and($r->refreshToken)->toBe('LI-R2')->and($r->tokenExpiresAt->isFuture())->toBeTrue();
    Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'LI-R1');

    $without = NewNetworksFake::linkedinAccount();
    expect(fn () => $this->connector->refresh($without))->toThrow(ReconnectRequiredException::class, 'reconecta');
});

test('sin credenciales de la app la red no se ofrece ni se registra el conector', function () {
    config(['services.linkedin.client_id' => '']);
    app()->forgetInstance(ConnectorRegistry::class);

    expect(Network::LinkedIn->isAvailable())->toBeFalse()->and(Network::offered())->not->toContain(Network::LinkedIn)
        ->and(Network::availableValues())->not->toContain('linkedin');
});
