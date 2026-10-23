<?php

use Tests\TestCase;

uses(TestCase::class);

/** Evalua config/services.php con un entorno concreto, sin tocar el estado global. */
function servicesConfig(array $env): array
{
    $saved = [];
    foreach (['MCP_OAUTH_TOKEN_DAYS', 'MCP_OAUTH_CODE_TTL', 'MCP_OAUTH_REDIRECT_ALLOWED', 'MCP_KEY_MAX_DAYS'] as $key) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }
    foreach ($env as $key => $value) {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("$key=$value");
    }

    try {
        return (require base_path('config/services.php'))['mcp']['oauth'] + ['max' => (require base_path('config/services.php'))['mcp']['key_max_days']];
    } finally {
        foreach ($saved as $key => [$env, $server, $getenv]) {
            unset($_ENV[$key], $_SERVER[$key]);
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            $getenv === false ? putenv($key) : putenv("$key=$getenv");
        }
    }
}

test('los defaults son 90 dias, 300 s y sin redirects extra', function () {
    $c = servicesConfig([]);

    expect($c['token_days'])->toBe(90)->and($c['code_ttl'])->toBe(300)->and($c['redirect_allowed'])->toBe([]);
});

test('token_days nunca supera key_max_days (900 con tope 60 baja a 30, la opcion ofrecida mas cercana)', function () {
    $c = servicesConfig(['MCP_OAUTH_TOKEN_DAYS' => '900', 'MCP_KEY_MAX_DAYS' => '60']);

    expect($c['token_days'])->toBe(30);
});

test('redirect_allowed se parsea como CSV sin vacios', function () {
    $c = servicesConfig(['MCP_OAUTH_REDIRECT_ALLOWED' => ' https://a.example/cb , ,https://b.example ']);

    expect($c['redirect_allowed'])->toBe(['https://a.example/cb', 'https://b.example']);
});
