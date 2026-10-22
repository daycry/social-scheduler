<?php

use App\Models\User;
use Tests\Support\McpClient;

uses(McpClient::class);

test('OAuthMetadata CA-12: la PRM responde en la raiz y en /mcp', function (string $path) {
    $base = rtrim(config('app.url'), '/');

    $this->getJson($path)->assertOk()
        ->assertJsonPath('resource', $base.'/mcp')
        ->assertJsonPath('authorization_servers', [$base])
        ->assertJsonPath('scopes_supported', ['mcp:use']);
})->with(['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp']);

test('OAuthMetadata: la PRM de un recurso que no existe da 404', function () {
    $this->getJson('/.well-known/oauth-protected-resource/otro')->assertNotFound();
});

test('OAuthMetadata CA-13: el ASM anuncia solo code + S256 + cliente publico', function () {
    $base = rtrim(config('app.url'), '/');
    $r = $this->getJson('/.well-known/oauth-authorization-server')->assertOk();

    $r->assertJsonPath('issuer', $base)
        ->assertJsonPath('authorization_endpoint', $base.'/oauth/authorize')
        ->assertJsonPath('token_endpoint', $base.'/oauth/token')
        ->assertJsonPath('registration_endpoint', $base.'/oauth/register')
        ->assertJsonPath('response_types_supported', ['code'])
        ->assertJsonPath('grant_types_supported', ['authorization_code'])
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('token_endpoint_auth_methods_supported', ['none']);

    expect($r->getContent())->not->toContain('refresh_token')->not->toContain('client_secret');
});

test('OAuthMetadata CA-14: el 401 de /mcp lleva WWW-Authenticate con resource_metadata', function (?string $key) {
    $expected = 'Bearer realm="mcp", resource_metadata="'.rtrim(config('app.url'), '/').'/.well-known/oauth-protected-resource/mcp"';

    $this->rpc($key, 'tools/list')->assertUnauthorized()->assertHeader('WWW-Authenticate', $expected);
})->with([[null], ['123|noexiste']]);

test('OAuthMetadata CA-24/28: una key valida sigue entrando y los metadatos no exigen Origin', function () {
    $key = $this->mcpKey(User::factory()->create());

    $this->rpc($key, 'tools/list')->assertOk();
    $this->getJson('/.well-known/oauth-authorization-server', ['Origin' => 'https://evil.example'])->assertOk();
});
