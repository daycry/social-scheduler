<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// T-50: POST ai/schedule. La IA va simulada; el endpoint solo PROPONE (no crea ni programa nada) y respeta el límite diario compartido.

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-02 08:00:00');
    config(['social.ai' => ['enabled' => true, 'api_key' => 'sk-ant-TEST', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 600, 'timeout' => 30, 'daily_limit' => 20, 'global_daily_limit' => 0]]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Bluesky]);
});

function fakeScheduleReply(array $payload = ['datetime' => '2026-11-06T18:00', 'networks' => ['instagram', 'bluesky']]): void
{
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($payload)]]])]);
}

test('propone la fecha y las redes, descuenta del cupo y no crea ninguna publicación', function () {
    fakeScheduleReply();

    $this->actingAs($this->user)->postJson(route('ai.schedule'), ['phrase' => 'el viernes a las 18 en Instagram y Bluesky', 'brand_id' => $this->brand->id])
        ->assertOk()
        ->assertJsonPath('proposal.scheduled_at', '2026-11-06T18:00')
        ->assertJsonPath('proposal.timezone', 'Europe/Madrid')
        ->assertJsonPath('proposal.networks', ['instagram', 'bluesky'])
        ->assertJsonPath('remaining', 19);

    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('con la IA desactivada responde 404 sin llamar a nadie', function () {
    config(['social.ai.enabled' => false]);
    Http::fake();

    $this->actingAs($this->user)->postJson(route('ai.schedule'), ['phrase' => 'mañana', 'brand_id' => $this->brand->id])->assertNotFound();
    Http::assertNothingSent();
});

test('valida la frase y la marca', function () {
    Http::fake();
    $this->actingAs($this->user);

    $this->postJson(route('ai.schedule'), ['brand_id' => $this->brand->id])->assertStatus(422)->assertJsonValidationErrors('phrase');
    $this->postJson(route('ai.schedule'), ['phrase' => str_repeat('a', 201), 'brand_id' => $this->brand->id])->assertStatus(422)->assertJsonValidationErrors('phrase');
    $this->postJson(route('ai.schedule'), ['phrase' => ['x'], 'brand_id' => $this->brand->id])->assertStatus(422);
    $this->postJson(route('ai.schedule'), ['phrase' => 'mañana'])->assertStatus(422)->assertJsonValidationErrors('brand_id');
    Http::assertNothingSent();
});

test('una marca de otro usuario responde 404 y no llama a la IA', function () {
    Http::fake();
    $other = Brand::factory()->for(User::factory()->create())->create();

    $this->actingAs($this->user)->postJson(route('ai.schedule'), ['phrase' => 'mañana', 'brand_id' => $other->id])->assertNotFound();
    Http::assertNothingSent();
});

test('hace falta sesión', function () {
    $this->postJson(route('ai.schedule'), ['phrase' => 'mañana', 'brand_id' => $this->brand->id])->assertUnauthorized();
});

test('límite diario compartido: al agotarse responde 422 con el mensaje y cupo 0', function () {
    config(['social.ai.daily_limit' => 1]);
    fakeScheduleReply(['datetime' => '2026-11-06T18:00', 'networks' => []]);
    $this->actingAs($this->user);

    $this->postJson(route('ai.schedule'), ['phrase' => 'el viernes', 'brand_id' => $this->brand->id])->assertOk()->assertJsonPath('remaining', 0);
    $this->postJson(route('ai.schedule'), ['phrase' => 'el viernes', 'brand_id' => $this->brand->id])
        ->assertStatus(422)->assertJsonPath('message', 'Has alcanzado el límite diario de sugerencias.')->assertJsonPath('remaining', 0);
});

test('una respuesta no válida de la IA da 422 con un mensaje legible y no una fecha', function () {
    fakeScheduleReply(['datetime' => '2020-01-01T00:00', 'networks' => []]);

    $this->actingAs($this->user)->postJson(route('ai.schedule'), ['phrase' => 'cuando sea', 'brand_id' => $this->brand->id])
        ->assertStatus(422)->assertJsonPath('message', 'La fecha propuesta ya ha pasado. Indica una fecha futura.')->assertJsonMissingPath('proposal');
});

test('está limitado a 10 peticiones por minuto', function () {
    config(['social.ai.daily_limit' => 100]);
    fakeScheduleReply(['datetime' => '2026-11-06T18:00', 'networks' => []]);
    $this->actingAs($this->user);

    foreach (range(1, 10) as $i) {
        $this->postJson(route('ai.schedule'), ['phrase' => 'el viernes', 'brand_id' => $this->brand->id])->assertOk();
    }
    $this->postJson(route('ai.schedule'), ['phrase' => 'el viernes', 'brand_id' => $this->brand->id])->assertStatus(429);
});
