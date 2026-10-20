<?php

use App\Models\Brand;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Panaderia Sol']);
});

function aiEnable(array $over = []): void
{
    config(['social.ai' => array_merge(['enabled' => true, 'api_key' => 'sk-ant-TEST', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 600, 'timeout' => 30, 'daily_limit' => 20], $over)]);
}

test('IA: desactivada por defecto (config) -> status enabled=false y suggest responde 404 sin llamar a nadie', function () {
    expect(config('social.ai.enabled'))->toBeFalse();

    $this->actingAs($this->user)->getJson(route('ai.status'))->assertOk()->assertExactJson(['enabled' => false, 'remaining' => 0]);
    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'oferta'])->assertNotFound();
});

test('IA: el flag solo no basta, hace falta tambien la clave (y al reves)', function () {
    aiEnable(['api_key' => '']);
    $this->actingAs($this->user)->getJson(route('ai.status'))->assertJson(['enabled' => false]);

    aiEnable(['enabled' => false]);
    $this->actingAs($this->user)->getJson(route('ai.status'))->assertJson(['enabled' => false]);
    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'x'])->assertNotFound();
});

test('IA: sugiere con la Messages API (cabeceras, modelo, version) y sin datos personales', function () {
    aiEnable();
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '  Hoy pan recien hecho!  ']], 'stop_reason' => 'end_turn'])]);

    $this->actingAs($this->user)->postJson(route('ai.suggest'), [
        'brief' => 'Oferta del dia, escribe a ana@example.com o llama al +34 600 123 456 o mira https://x.test/oferta',
        'brand_id' => $this->brand->id, 'network' => 'instagram',
    ])->assertOk()->assertJson(['text' => 'Hoy pan recien hecho!', 'remaining' => 19]);

    Http::assertSent(function (Request $r) {
        $body = $r->data();
        $content = $body['messages'][0]['content'];

        return $r->url() === 'https://api.anthropic.com/v1/messages' && $r->method() === 'POST'
            && $r->header('x-api-key')[0] === 'sk-ant-TEST' && $r->header('anthropic-version')[0] === '2023-06-01'
            && $body['model'] === 'claude-sonnet-5-5' && $body['max_tokens'] === 600
            && str_contains($content, 'Marca: Panaderia Sol') && str_contains($content, 'Red de destino: instagram')
            && ! str_contains($content, 'ana@example.com') && ! str_contains($content, '600 123') && ! str_contains($content, 'x.test')
            && ! str_contains(json_encode($body), $this->user->email);
    });
});

test('IA: limite diario por usuario (otro usuario no lo comparte) y se devuelve el cupo si falla el servicio', function () {
    aiEnable(['daily_limit' => 2]);
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(['content' => [['type' => 'text', 'text' => 'uno']]])
        ->push(['error' => ['type' => 'overloaded_error']], 529)
        ->push(['content' => [['type' => 'text', 'text' => 'dos']]])
        ->push(['content' => [['type' => 'text', 'text' => 'otro usuario']]])]);

    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'a'])->assertOk()->assertJson(['remaining' => 1]);
    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'b'])->assertStatus(422)->assertJson(['remaining' => 1]); // fallo: cupo devuelto
    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'c'])->assertOk()->assertJson(['remaining' => 0]);
    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'd'])->assertStatus(422)->assertJsonPath('message', 'Has alcanzado el límite diario de sugerencias.');
    Http::assertSentCount(3);

    $other = User::factory()->create();
    $this->actingAs($other)->postJson(route('ai.suggest'), ['brief' => 'e'])->assertOk()->assertJson(['text' => 'otro usuario']);
});

test('IA: validacion del brief y una marca ajena no se envia', function () {
    aiEnable();
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]])]);

    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => ''])->assertStatus(422);
    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => str_repeat('a', 501)])->assertStatus(422);
    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'x', 'network' => 'myspace'])->assertStatus(422);
    Http::assertNothingSent();

    $other = User::factory()->create();
    $foreign = Brand::factory()->for($other)->create(['name' => 'Marca Secreta']);
    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'x', 'brand_id' => $foreign->id])->assertOk();
    Http::assertSent(fn (Request $r) => ! str_contains(json_encode($r->data()), 'Marca Secreta'));
});

test('IA: un fallo de red o del servicio da un mensaje legible sin filtrar la clave', function () {
    aiEnable();
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid x-api-key sk-ant-TEST']], 401)]);

    $res = $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'x'])->assertStatus(422);
    expect($res->getContent())->not->toContain('sk-ant-TEST');

    Http::fake(['api.anthropic.com/*' => fn () => throw new ConnectionException('timeout')]);
    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'x'])->assertStatus(422)->assertJsonPath('message', 'El asistente no responde ahora mismo. Inténtalo de nuevo en unos minutos.');
});

test('IA: exige sesion', function () {
    $this->getJson(route('ai.status'))->assertUnauthorized();
    $this->postJson(route('ai.suggest'), ['brief' => 'x'])->assertUnauthorized();
});

test('IA: C4 tope diario GLOBAL entre usuarios y un reembolso nunca deja el contador en negativo', function () {
    aiEnable(['global_daily_limit' => 2]);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]])]);
    $other = User::factory()->create();

    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'uno'])->assertOk();
    $this->actingAs($other)->postJson(route('ai.suggest'), ['brief' => 'dos'])->assertOk();
    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'tres'])->assertStatus(422);

    expect((int) Cache::get('ai:global:'.now('UTC')->toDateString()))->toBe(2); // la rechazada se devolvio
});

test('IA: C4 un fallo del servicio tras cambiar de dia devuelve la reserva del dia original sin pasar de cero', function () {
    aiEnable();
    Carbon\Carbon::setTestNow('2026-11-01 23:59:59');
    Http::fake(['api.anthropic.com/*' => function () {
        Carbon\Carbon::setTestNow('2026-11-02 00:00:05'); // cambia el dia mientras la llamada esta en vuelo

        return Http::response([], 500);
    }]);

    $this->actingAs($this->user)->postJson(route('ai.suggest'), ['brief' => 'x'])->assertStatus(422);

    expect((int) Cache::get('ai:'.$this->user->id.':2026-11-01'))->toBe(0)
        ->and((int) Cache::get('ai:'.$this->user->id.':2026-11-02'))->toBe(0);
    Carbon\Carbon::setTestNow();
});
