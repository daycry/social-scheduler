<?php

use App\Models\OAuthClient;
use Illuminate\Support\Facades\RateLimiter;

// T-12: casos adversariales de la revision de seguridad de la fase 1 (intentos 1 y 2), fijados por HTTP.

beforeEach(function () {
    RateLimiter::clear('mcp-oauth-register:127.0.0.1');
    RateLimiter::clear('mcp-oauth-register:global');
    config(['services.mcp.oauth.redirect_allowed' => ['https://app.example/mcp']]);
});

test('OAuthSecurityReview: el DCR rechaza redirects que escapan del prefijo permitido', function (string $uri) {
    $this->postJson('/oauth/register', ['redirect_uris' => [$uri]])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    expect(OAuthClient::count())->toBe(0);
})->with([
    'punto-punto' => 'https://app.example/mcp/../admin',
    'punto codificado' => 'https://app.example/mcp/%2e%2e/admin',
    'barra codificada' => 'https://app.example/mcp/x%2f..%2f..%2fadmin',
    'contrabarra codificada' => 'https://app.example/mcp/x%5c..%5c..%5cadmin',
    'doble codificacion' => 'https://app.example/mcp/%252e%252e/admin',
    'userinfo' => 'https://app.example@evil.example/mcp/cb',
    'host parecido' => 'https://app.example.evil.example/mcp/cb',
    'esquema javascript' => 'javascript://app.example/mcp',
]);

test('OAuthSecurityReview: dentro del prefijo permitido se registra', function () {
    $this->postJson('/oauth/register', ['redirect_uris' => ['https://app.example/mcp/callback']])->assertCreated();
});

test('OAuthSecurityReview: metadatos de tipo incorrecto dan 400 y no 500', function () {
    $this->postJson('/oauth/register', ['redirect_uris' => ['http://127.0.0.1:5000/cb'], 'grant_types' => [['x']]])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');
});
