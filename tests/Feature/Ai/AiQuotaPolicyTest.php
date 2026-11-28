<?php

use App\Connectors\Network;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AiAssistant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Política ÚNICA de cuota del asistente (suggest, schedule, adapt y complete, con y sin `$usable`):
//  - el proveedor respondió (llamada pagada, útil o no): se gastan el cupo del usuario y el global, sin reembolso;
//  - la llamada no llegó a hacerse o el proveedor la rechazó con HTTP (sin coste): se devuelven los dos;
//  - timeout o error de conexión (ambiguo, probablemente cobrado): no se devuelve ninguno.

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    config(['social.ai' => ['enabled' => true, 'api_key' => 'sk-ant-TEST', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 600, 'timeout' => 30, 'daily_limit' => 20, 'global_daily_limit' => 0]]);
    $this->user = User::factory()->create();
    $this->ai = app(AiAssistant::class);
    $this->global = fn () => (int) Cache::get('ai:global:'.now('UTC')->toDateString());
});

test('un timeout no devuelve ninguna cuota', function () {
    Http::fake(['api.anthropic.com/*' => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => $this->ai->suggest($this->user, 'una idea'))->toThrow(PostValidationException::class, 'no responde');
    expect($this->ai->remaining($this->user))->toBe(19)->and(($this->global)())->toBe(1);
});

test('un rechazo HTTP del proveedor devuelve ambas cuotas', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);

    expect(fn () => $this->ai->suggest($this->user, 'una idea'))->toThrow(PostValidationException::class, 'saturado');
    expect($this->ai->remaining($this->user))->toBe(20)->and(($this->global)())->toBe(0);
});

test('complete() con una respuesta inutilizable gasta ambas cuotas y no promete devolver el uso', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'cortado']]])]);

    expect(fn () => $this->ai->complete($this->user, 'items', 'sistema', 'contenido', 100, fn (string $t) => false))
        ->toThrow(PostValidationException::class, 'incompleta');
    expect($this->ai->remaining($this->user))->toBe(19)->and(($this->global)())->toBe(1);
});

test('N respuestas inservibles provocadas por la entrada agotan el cupo del usuario y la global no pasa de N', function () {
    config(['social.ai.daily_limit' => 3, 'social.ai.global_daily_limit' => 100]);
    $brand = Brand::factory()->for($this->user)->create();
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $brand->id, 'network' => Network::Instagram]);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'no lo sé']]])]);

    foreach (range(1, 3) as $i) {
        expect(fn () => $this->ai->proposeSchedule($this->user, $brand, 'ayer a las 9'))->toThrow(PostValidationException::class, 'No he podido');
    }
    expect($this->ai->remaining($this->user))->toBe(0)
        ->and(fn () => $this->ai->proposeSchedule($this->user, $brand, 'ayer a las 9'))->toThrow(PostValidationException::class, 'límite diario')
        ->and(($this->global)())->toBe(3);
    Http::assertSentCount(3);
});

test('el timeout de adapt crece con los tokens y no pasa del tope de la config', function () {
    config(['social.ai.timeout' => 30, 'social.ai.adapt_max_timeout' => 50]);

    expect($this->ai->adaptTimeout(500))->toBe(40)->and($this->ai->adaptTimeout(2000))->toBe(50)->and($this->ai->adaptTimeout(8000))->toBe(50);
    config(['social.ai.adapt_max_timeout' => 10]);
    expect($this->ai->adaptTimeout(8000))->toBe(30); // nunca por debajo del timeout base
});

test('el tope por defecto del timeout de adapt cabe en el fastcgi_read_timeout de Nginx (60 s)', function () {
    // El beforeEach sustituye `social.ai`: se lee el fichero de config real.
    $timeout = (require config_path('social.php'))['ai']['adapt_max_timeout'];

    expect($timeout)->toBe(50)->toBeLessThanOrEqual(55);
});
