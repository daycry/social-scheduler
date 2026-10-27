<?php

use App\Models\Brand;
use App\Models\User;
use App\Services\Media\GiphyClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// T-65 · C-04b / D4: el proxy de Giphy. Nunca hay red: `Http::fake`. La clave de prueba es inventada.

const GIPHY_TEST_KEY = 'giphy-TEST-KEY-123';

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    config(['services.giphy.api_key' => GIPHY_TEST_KEY, 'social.giphy.hourly_limit' => 90, 'social.giphy.cache_minutes' => 10, 'social.giphy.limit' => 24]);
    $this->user = User::factory()->create();
});

/** Un elemento de la respuesta de Giphy. */
function giphyItem(string $id = 'abc123', array $override = []): array
{
    return array_replace_recursive([
        'id' => $id,
        'title' => 'Gato <b>saltando</b>',
        'images' => [
            'fixed_width_small' => ['url' => "https://media2.giphy.com/media/$id/100w.gif", 'width' => '100', 'height' => '80'],
            'original_mp4' => ['mp4' => "https://media2.giphy.com/media/$id/giphy.mp4"],
        ],
    ], $override);
}

function fakeGiphy(array $items = [], int $status = 200, int $total = 100): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.giphy.com/*' => Http::response($status === 200 ? ['data' => $items, 'pagination' => ['total_count' => $total]] : ['message' => 'x'], $status)]);
}

test('la busqueda llama a Giphy con la clave, el rating pg-13 y devuelve solo lo necesario', function () {
    fakeGiphy([giphyItem()]);

    $res = $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'gato']))->assertOk();

    expect($res->json('results.0'))->toBe([
        'id' => 'abc123', 'title' => 'Gato saltando', 'preview' => 'https://media2.giphy.com/media/abc123/100w.gif',
        'width' => 100, 'height' => 80, 'mp4' => 'https://media2.giphy.com/media/abc123/giphy.mp4',
    ])->and($res->json('next_offset'))->toBe(24);
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.giphy.com/v1/gifs/search?') && $r['q'] === 'gato' && $r['rating'] === 'pg-13' && $r['api_key'] === GIPHY_TEST_KEY);
});

test('sin texto se piden las tendencias', function () {
    fakeGiphy([giphyItem()]);

    $this->actingAs($this->user)->getJson(route('gifs.search'))->assertOk()->assertJsonCount(1, 'results');
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.giphy.com/v1/gifs/trending?'));
});

test('la clave nunca aparece en la respuesta ni en la pagina del compositor', function () {
    fakeGiphy([giphyItem()]);

    $body = $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'gato']))->assertOk()->getContent();
    expect($body)->not->toContain(GIPHY_TEST_KEY);

    $brand = Brand::factory()->for($this->user)->create();
    expect($this->actingAs($this->user)->get(route('brands.posts.create', $brand))->assertOk()->getContent())->not->toContain(GIPHY_TEST_KEY);
});

test('una respuesta repetida sale de la cache y no gasta una llamada de la clave', function () {
    fakeGiphy([giphyItem()]);

    $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'gato']))->assertOk();
    $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'gato']))->assertOk();
    $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'perro']))->assertOk();

    Http::assertSentCount(2);
});

test('agotado el cupo por hora no se llama a Giphy: mensaje claro y 429', function () {
    config(['social.giphy.hourly_limit' => 2]);
    fakeGiphy([giphyItem()]);

    foreach (['a', 'b'] as $q) {
        $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => $q]))->assertOk();
    }
    $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'c']))->assertStatus(429)
        ->assertJsonPath('message', 'Has hecho muchas búsquedas de GIF; prueba en unos minutos.');

    Http::assertSentCount(2);
});

test('un 429 de Giphy se traduce al mismo mensaje y un error 5xx a uno claro, sin la clave', function () {
    fakeGiphy(status: 429);
    $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'x']))->assertStatus(429)->assertJsonPath('message', GiphyClient::RATE_LIMITED);

    fakeGiphy(status: 500);
    $res = $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'y']))->assertStatus(502);
    expect($res->json('message'))->toBe(GiphyClient::UNAVAILABLE)->and($res->getContent())->not->toContain(GIPHY_TEST_KEY);
});

test('un fallo de conexion no filtra la URL con la clave', function () {
    Http::swap(new Factory);
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: api.giphy.com?api_key='.GIPHY_TEST_KEY));

    $res = $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'z']))->assertStatus(502);

    expect($res->getContent())->not->toContain(GIPHY_TEST_KEY)->and($res->json('message'))->toBe(GiphyClient::UNAVAILABLE);
});

test('solo se ofrecen GIFs con miniatura y MP4 en hosts de Giphy (lo demas se descarta)', function () {
    fakeGiphy([
        giphyItem('bueno1'),
        giphyItem('malo1', ['images' => ['original_mp4' => ['mp4' => 'https://evil.example.com/x.mp4']]]),
        giphyItem('malo2', ['images' => ['fixed_width_small' => ['url' => 'http://media2.giphy.com/media/x/100w.gif']]]),
        giphyItem('malo3', ['images' => ['original_mp4' => ['mp4' => 'https://media2.giphy.com.evil.example/x/giphy.mp4']]]),
        giphyItem('malo4', ['images' => ['original_mp4' => ['mp4' => 'https://127.0.0.1/giphy.mp4']]]),
        ['id' => '../x', 'images' => []],
    ]);

    $ids = collect($this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'x']))->json('results'))->pluck('id')->all();

    expect($ids)->toBe(['bueno1']);
});

test('sin GIPHY_API_KEY el buscador responde 404 y no llama a Giphy', function () {
    config(['services.giphy.api_key' => null]);
    Http::fake();

    $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'x']))->assertNotFound();
    Http::assertNothingSent();
});

test('exige sesion y valida el texto y el desplazamiento', function () {
    $this->getJson(route('gifs.search'))->assertUnauthorized();

    $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => str_repeat('a', 101)]))->assertStatus(422)->assertJsonValidationErrors('q');
    $this->actingAs($this->user)->getJson(route('gifs.search', ['offset' => 5000]))->assertStatus(422)->assertJsonValidationErrors('offset');
});

test('el throttle por usuario corta tras 20 busquedas por minuto', function () {
    fakeGiphy([giphyItem()]);
    config(['social.giphy.hourly_limit' => 1000]);

    foreach (range(1, 20) as $i) {
        $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => "q$i"]))->assertOk();
    }
    $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'otra']))->assertStatus(429);
});
