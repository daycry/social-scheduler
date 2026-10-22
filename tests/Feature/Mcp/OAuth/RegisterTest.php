<?php

use App\Models\AuditLog;
use App\Models\OAuthClient;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(fn () => RateLimiter::clear('mcp-oauth-register:127.0.0.1'));

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
