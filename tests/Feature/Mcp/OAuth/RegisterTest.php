<?php

use App\Models\AuditLog;
use App\Models\OAuthClient;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('mcp-oauth-register:127.0.0.1');
    RateLimiter::clear('mcp-oauth-register:global');
});

function dcr(array $body)
{
    return test()->postJson('/oauth/register', $body);
}

test('OAuthRegister CA-15: un redirect loopback registra el cliente (201, publico)', function () {
    $r = dcr(['client_name' => 'Claude Code', 'redirect_uris' => ['http://127.0.0.1:53111/callback']])->assertCreated();

    $r->assertJsonPath('token_endpoint_auth_method', 'none')
        ->assertJsonPath('redirect_uris', ['http://127.0.0.1:53111/callback'])
        ->assertHeader('Cache-Control');
    expect(OAuthClient::find($r->json('client_id')))->not->toBeNull()
        ->and($r->getContent())->not->toContain('client_secret');
});

test('OAuthRegister CA-15: un redirect no permitido da 400 invalid_redirect_uri', function (mixed $uris) {
    dcr(['redirect_uris' => $uris])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    expect(OAuthClient::count())->toBe(0);
})->with([
    'https ajeno' => [['https://evil.example/cb']],
    'una buena y una mala' => [['http://localhost:1/cb', 'http://evil.example/cb']],
    'vacia' => [[]],
    'no es lista' => [['a' => 'http://localhost/cb']],
    'no es array' => ['http://localhost/cb'],
    'elementos no string' => [[123, ['x']]],
]);

test('OAuthRegister: sin redirect_uris es 400 sin 500', function () {
    dcr([])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
});

test('OAuthRegister: metadatos no soportados dan 400 invalid_client_metadata', function (array $extra) {
    dcr(['redirect_uris' => ['http://localhost:1/cb']] + $extra)->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');
})->with([
    'secreto' => [['token_endpoint_auth_method' => 'client_secret_basic']],
    'implicit' => [['grant_types' => ['implicit']]],
    'sin authorization_code' => [['grant_types' => ['refresh_token']]],
    'response_type token' => [['response_types' => ['token']]],
    'nombre no string' => [['client_name' => ['x']]],
    'nombre larguisimo' => [['client_name' => str_repeat('a', 101)]],
    'nombre solo html' => [['client_name' => '<b></b>']],
]);

test('OAuthRegister: el JSON malformado no provoca 500', function () {
    $this->call('POST', '/oauth/register', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{no es json')->assertStatus(400);
});

test('OAuthRegister: se admite refresh_token declarado pero se responde solo authorization_code', function () {
    dcr(['redirect_uris' => ['http://localhost:1/cb'], 'grant_types' => ['authorization_code', 'refresh_token']])
        ->assertCreated()->assertJsonPath('grant_types', ['authorization_code']);
});

test('OAuthRegister: el nombre se sanea (sin etiquetas)', function () {
    $r = dcr(['client_name' => '<script>x</script>Mi cliente', 'redirect_uris' => ['http://localhost:1/cb']])->assertCreated();

    expect($r->json('client_name'))->not->toContain('<');
});

test('OAuthRegister CA-15: a partir de 10 registros por hora desde una IP responde 429 con Retry-After', function () {
    foreach (range(1, 10) as $i) {
        dcr(['redirect_uris' => ['http://localhost:1/cb']])->assertCreated();
    }

    dcr(['redirect_uris' => ['http://localhost:1/cb']])->assertStatus(429)->assertHeader('Retry-After');
});

test('OAuthRegister: audita oauth.client.registered sin datos sensibles', function () {
    $r = dcr(['client_name' => 'Cliente', 'redirect_uris' => ['http://localhost:1/cb']])->assertCreated();

    $row = AuditLog::where('action', 'oauth.client.registered')->firstOrFail();
    expect($row->detail)->toBe('client_id='.$r->json('client_id'))
        ->and($row->user_id)->toBeNull();
});

test('OAuthRegister: grant_types o response_types con elementos no string dan 400, no 500', function (array $over) {
    dcr(['redirect_uris' => ['http://localhost:1/cb']] + $over)->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');
})->with([
    'grant objeto' => [['grant_types' => [['x']]]],
    'grant numero' => [['grant_types' => [1]]],
    'response array' => [['response_types' => [['code']]]],
]);

test('OAuthRegister: guarda software_id, software_version e IP de alta', function () {
    $r = dcr(['redirect_uris' => ['http://localhost:1/cb'], 'software_id' => 'app-1', 'software_version' => '2.3'])->assertCreated();

    $c = OAuthClient::find($r->json('client_id'));
    expect($c->software_id)->toBe('app-1')->and($c->software_version)->toBe('2.3')->and($c->created_ip)->toBe('127.0.0.1');
});

test('OAuthRegister: segmentos . y .. (tambien %2e en cualquier caja) se rechazan', function (string $uri) {
    config(['services.mcp.oauth.redirect_allowed' => ['https://app.example/mcp']]);
    dcr(['redirect_uris' => [$uri]])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
})->with([
    'https://app.example/mcp/../admin', 'https://app.example/mcp/%2e%2e/admin', 'https://app.example/mcp/%2E%2E/admin',
    'https://app.example/mcp/%2e./x', 'https://app.example/mcp/./x', 'http://localhost/a/../b',
]);

test('OAuthRegister: tope global por hora ademas del de IP', function () {
    config(['services.mcp.oauth.register_global_per_hour' => 2]);

    foreach (['1.1.1.1', '2.2.2.2'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/oauth/register', ['redirect_uris' => ['http://localhost:1/cb']])->assertCreated();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '3.3.3.3'])->postJson('/oauth/register', ['redirect_uris' => ['http://localhost:1/cb']])->assertStatus(429);
    RateLimiter::clear('mcp-oauth-register:global');
});

test('OAuthRegister: el limite por IP agrupa un /64 de IPv6', function () {
    $key = new ReflectionMethod(AppServiceProvider::class, 'ipKey');
    $a = $key->invoke(null, '2001:db8:1:2:aaaa::1');
    $b = $key->invoke(null, '2001:db8:1:2:bbbb::9');

    expect($a)->toBe($b)->and($key->invoke(null, '2001:db8:1:3::1'))->not->toBe($a)->and($key->invoke(null, '9.9.9.9'))->toBe('9.9.9.9');
});

test('OAuthRegister: la IPv4 mapeada y el loopback no comparten cupo con todo IPv4', function () {
    $key = new ReflectionMethod(AppServiceProvider::class, 'ipKey');

    expect($key->invoke(null, '::ffff:1.2.3.4'))->toBe('1.2.3.4')
        ->and($key->invoke(null, '::ffff:5.6.7.8'))->toBe('5.6.7.8')
        ->and($key->invoke(null, '::1'))->toBe('::1');
});
