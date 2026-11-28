<?php

use App\Models\Brand;
use App\Models\User;
use App\Models\Webhook;
use App\Services\Media\UrlGuard;
use Illuminate\Support\Facades\Http;

test('WebhookSsrf: private mixed metadata numeric and non-HTTPS receivers are rejected before any request', function (string $url, array $ips) {
    Http::preventStrayRequests();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => $ips));
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $this->actingAs($owner)->postJson('/brands/'.$brand->id.'/webhooks', [
        'name' => 'Receptor inseguro', 'url' => $url,
        'events' => ['post.published'], 'secret' => str_repeat('a', 64),
    ])->assertUnprocessable()->assertJsonValidationErrors('url');
    expect(Webhook::withoutUserScope()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'loopback' => ['https://127.0.0.1/hook', []],
    'private' => ['https://10.0.0.1/hook', []],
    'metadata' => ['https://169.254.169.254/hook', []],
    'numeric alias' => ['https://2130706433/hook', []],
    'mapped IPv6' => ['https://[::ffff:127.0.0.1]/hook', []],
    'mixed DNS' => ['https://hooks.example.test/hook', ['93.184.216.34', '192.168.1.2']],
    'no DNS' => ['https://hooks.example.test/hook', []],
    'cleartext' => ['http://hooks.example.test/hook', ['93.184.216.34']],
]);
