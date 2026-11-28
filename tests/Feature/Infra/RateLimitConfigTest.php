<?php

use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;

test('named route groups keep their default limits and windows', function (string $name, int $attempts, int $seconds) {
    $request = Request::create('/?publish_now=1', 'GET', server: ['REMOTE_ADDR' => '192.0.2.1']);
    $limit = RateLimiter::limiter($name)($request);

    expect($limit->maxAttempts)->toBe($attempts)->and($limit->decaySeconds)->toBe($seconds);
})->with([
    ['publish-now', 30, 60],
    ['report-builder', 30, 60],
    ['report-schedules', 10, 60],
    ['studio-ai', 6, 60],
    ['canva', 30, 60],
    ['canva-connect', 10, 60],
    ['studio-ai-text', 12, 60],
    ['studio-links', 10, 60],
    ['brand-collaborators', 10, 60],
    ['post-batches', 10, 60],
    ['studio-shares', 10, 60],
    ['studio-copies', 10, 60],
    ['studio-link-comments', 10, 60],
    ['studio-link-view', 60, 60],
    ['two-factor-manage', 10, 60],
    ['passkey-login', 10, 60],
    ['passkey-login-options', 30, 60],
    ['passkey-manage', 20, 60],
    ['social-login', 20, 60],
    ['mcp-oauth-register', 10, 3600],
    ['mcp-oauth-authorize', 30, 60],
    ['mcp-oauth-token', 30, 60],
]);

test('named limits read current configuration and keep user IP and share token buckets', function () {
    config([
        'rate_limits.groups.studio-ai' => 3,
        'rate_limits.per_ip.studio-link-view' => 7,
        'rate_limits.publish_now' => 9,
        'rate_limits.studio_link_comments' => 4,
        'rate_limits.mcp_oauth_register' => 2,
        'rate_limits.two_factor_manage' => 5,
    ]);

    $request = Request::create('/design/example?publish_now=1', 'GET', server: ['REMOTE_ADDR' => '2001:db8:1234:5678::125']);
    $request->setUserResolver(fn () => (object) ['id' => 27]);
    $route = (new Route(['GET'], 'design/{token}', []))->bind($request);
    $request->setRouteResolver(fn () => $route);

    foreach ([
        ['studio-ai', 3, 'studio-ai:27'],
        ['studio-link-view', 7, 'studio-link-view:2001:db8:1234:5678::125'],
        ['publish-now', 9, 'publish-now:27'],
        ['studio-link-comments', 4, 'studio-link-comments:'.hash('sha256', 'example')],
        ['mcp-oauth-register', 2, 'mcp-oauth-register:20010db812345678/64'],
        ['two-factor-manage', 5, '2fa-manage:27'],
    ] as [$name, $attempts, $key]) {
        $limit = RateLimiter::limiter($name)($request);
        expect($limit->maxAttempts)->toBe($attempts)->and($limit->key)->toBe($key);
    }

    expect(RateLimiter::limiter('publish-now')(Request::create('/')))->toBeInstanceOf(Unlimited::class);
});

test('named limits have distinct registrations', function () {
    $names = [
        ...array_keys(config('rate_limits.limits')),
        ...array_keys(config('rate_limits.groups')),
        ...array_keys(config('rate_limits.per_ip')),
        'publish-now', 'studio-link-comments', 'mcp-oauth-register', 'two-factor-manage', 'giphy', 'mcp', 'mcp-ip',
    ];

    expect(array_values(array_unique($names)))->toBe($names);
});
