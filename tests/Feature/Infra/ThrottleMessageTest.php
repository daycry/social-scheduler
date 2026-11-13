<?php

use App\Models\User;
use App\Services\Media\UrlGuard;
use App\Support\RouteLimits;
use Illuminate\Support\Facades\Route;

// Un 429 de `throttle` sale en castellano y con el tiempo de espera, en la web (JSON), la API y MCP; las cabeceras se conservan.

test('Throttle: el 429 de la web (JSON) va en castellano con el tiempo de Retry-After', function () {
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => []));
    $user = User::factory()->create();
    $last = null;
    for ($i = 0; $i < 11; $i++) {
        $last = $this->actingAs($user)->postJson(route('media.import-url'), ['url' => 'https://cdn.example.com/a.jpg']);
    }

    $last->assertStatus(429)->assertHeader('Retry-After');
    $retry = (int) $last->headers->get('Retry-After');
    expect($retry)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    expect($last->json('message'))->toStartWith('Demasiados intentos. Espera ')->toContain((string) $retry)->not->toContain('Too Many');
});

test('Throttle: el 429 de /api/* y de /mcp va en castellano aunque no se pida JSON', function (string $path) {
    Route::middleware(['api', 'throttle:1,1'])->get($path, fn () => 'ok');

    $this->get($path)->assertOk();
    $response = $this->get($path)->assertStatus(429)->assertHeader('Retry-After');

    expect($response->json('message'))->toStartWith('Demasiados intentos.')->not->toContain('Too Many');
})->with(['/api/v1/_throttle-probe', '/mcp']);

test('Throttle: una peticion HTML normal conserva la respuesta por defecto', function () {
    Route::middleware(['web', 'throttle:1,1'])->get('/_throttle-html', fn () => 'ok');

    $this->get('/_throttle-html')->assertOk();
    $this->get('/_throttle-html')->assertStatus(429)->assertHeader('Retry-After');
});

test('Throttle: los contadores por ruta de un usuario se vacian con RouteLimits::forget (lo usa el E2E aislado)', function () {
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => []));
    $user = User::factory()->create();
    for ($i = 0; $i < 11; $i++) {
        $this->actingAs($user)->postJson(route('media.import-url'), ['url' => 'https://cdn.example.com/a.jpg']);
    }
    $this->actingAs($user)->postJson(route('media.import-url'), ['url' => 'https://cdn.example.com/a.jpg'])->assertStatus(429);

    RouteLimits::forget($user->id);

    $this->actingAs($user)->postJson(route('media.import-url'), ['url' => 'https://cdn.example.com/a.jpg'])->assertStatus(422);
});
