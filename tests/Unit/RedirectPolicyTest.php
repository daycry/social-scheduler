<?php

use App\Services\Mcp\OAuth\RedirectPolicy;

test('permite loopback con cualquier puerto, incluido [::1]', function (string $uri) {
    expect((new RedirectPolicy([]))->isAllowed($uri))->toBeTrue();
})->with([
    'http://127.0.0.1:53111/callback',
    'http://localhost:8765/cb',
    'http://localhost/cb',
    'http://[::1]:4000/callback',
    'http://LOCALHOST:1/x',
]);

test('permite los callbacks de Claude', function (string $uri) {
    expect((new RedirectPolicy([]))->isAllowed($uri))->toBeTrue();
})->with(['https://claude.ai/api/mcp/auth_callback', 'https://claude.com/api/mcp/auth_callback']);

test('rechaza http no loopback, https ajeno, userinfo, fragmentos y trucos de host', function (string $uri) {
    expect((new RedirectPolicy([]))->isAllowed($uri))->toBeFalse();
})->with([
    'http://evil.example/cb',
    'https://evil.example/cb',
    'http://localhost.evil.com/cb',
    'http://127.0.0.1.evil.com/cb',
    'https://claude.ai.evil.com/api/mcp/auth_callback',
    'https://claude.ai/api/mcp/auth_callback/../x',
    'https://claude.ai@evil.example/api/mcp/auth_callback',
    'http://localhost@evil.example/cb',
    'http://127.0.0.1:80/cb#frag',
    'http://localhost\\@evil.example/cb',
    'javascript:alert(1)',
    'myapp://callback',
    'ftp://localhost/cb',
    '',
    'localhost',
    '//localhost/cb',
]);

test('los prefijos https configurados comparan host exacto y ruta, no cadena', function () {
    $policy = new RedirectPolicy(['https://cliente.example/oauth', 'http://inseguro.example/cb']);

    expect($policy->isAllowed('https://cliente.example/oauth/callback'))->toBeTrue()
        ->and($policy->isAllowed('https://cliente.example/oauth'))->toBeTrue()
        ->and($policy->isAllowed('https://cliente.example/oauthx'))->toBeFalse()
        ->and($policy->isAllowed('https://cliente.example.evil.com/oauth/cb'))->toBeFalse()
        ->and($policy->isAllowed('https://cliente.example:8443/oauth/cb'))->toBeFalse()
        ->and($policy->isAllowed('http://inseguro.example/cb'))->toBeFalse();
});

test('matchesRegistered: exacta y, en loopback, con puerto variable', function () {
    $policy = new RedirectPolicy([]);
    $registered = ['http://127.0.0.1:5000/callback', 'https://claude.ai/api/mcp/auth_callback'];

    expect($policy->matchesRegistered($registered, 'http://127.0.0.1:5000/callback'))->toBeTrue()
        ->and($policy->matchesRegistered($registered, 'http://127.0.0.1:61234/callback'))->toBeTrue()
        ->and($policy->matchesRegistered($registered, 'http://127.0.0.1:5000/otra'))->toBeFalse()
        ->and($policy->matchesRegistered($registered, 'http://localhost:5000/callback'))->toBeFalse()
        ->and($policy->matchesRegistered($registered, 'https://claude.ai/api/mcp/auth_callback'))->toBeTrue()
        ->and($policy->matchesRegistered($registered, 'https://claude.com/api/mcp/auth_callback'))->toBeFalse();
});

test('rechaza separadores y puntos codificados que el destino normalizaria fuera del prefijo', function (string $uri) {
    expect((new RedirectPolicy(['https://app.example/mcp']))->isAllowed($uri))->toBeFalse();
})->with([
    'https://app.example/mcp/x%2f..%2f..%2fadmin',
    'https://app.example/mcp/x/..%2F..%2Fadmin',
    'https://app.example/mcp/x%2f%2e%2e%2f%2e%2e%2fadmin',
    'https://app.example/mcp/x%5c..%5c..%5cadmin',
    'https://app.example/mcp/%252e%252e/admin',
]);
