<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\UrlGuard;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// T-69 · CA-15: con la clave de Giphy configurada, el usuario busca «gato», elige un GIF y su MP4 se importa como media (API simulada, sin red).

beforeEach(function () {
    Cache::flush();
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'services.giphy.api_key' => 'giphy-TEST-KEY']);
    $this->user = User::factory()->create();
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => str_ends_with($host, 'giphy.com') ? ['151.101.2.2'] : []));
});

test('CA-15: buscar «gato», elegir un GIF e importar su MP4 como media «lista»; el compositor muestra la atribución', function () {
    if (! str_contains((string) shell_exec('ffmpeg -version 2>&1'), 'ffmpeg version')) {
        $this->markTestSkipped('ffmpeg no disponible para crear el MP4 de prueba.');
    }
    $file = tempnam(sys_get_temp_dir(), 'gif').'.mp4';
    exec('ffmpeg -loglevel error -y -f lavfi -i testsrc=duration=2:size=160x120:rate=25 -pix_fmt yuv420p '.escapeshellarg($file).' 2>&1', $out, $code);
    expect($code)->toBe(0);
    $mp4 = (string) file_get_contents($file);
    @unlink($file);

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'api.giphy.com/*' => Http::response(['data' => [[
            'id' => 'gato1', 'title' => 'Gato',
            'images' => ['fixed_width_small' => ['url' => 'https://media2.giphy.com/media/gato1/100w.gif', 'width' => '100', 'height' => '100'], 'original_mp4' => ['mp4' => 'https://media2.giphy.com/media/gato1/giphy.mp4']],
        ]], 'pagination' => ['total_count' => 1]]),
        'media2.giphy.com/*' => Http::response($mp4, 200, ['Content-Type' => 'video/mp4', 'Content-Length' => (string) strlen($mp4)]),
    ]);

    // 1. La busqueda pasa por el servidor.
    $found = $this->actingAs($this->user)->getJson(route('gifs.search', ['q' => 'gato']))->assertOk()->json('results.0');
    expect($found['mp4'])->toBe('https://media2.giphy.com/media/gato1/giphy.mp4');

    // 2. Se elige y se importa (la cola de los tests es sincrona: la descarga ya ha corrido).
    $res = $this->actingAs($this->user)->postJson(route('media.import-gif'), ['url' => $found['mp4']])->assertStatus(202);

    $asset = MediaAsset::withoutUserScope()->findOrFail($res->json('asset.id'));
    expect($asset->status)->toBe(MediaAsset::STATUS_READY)->and($asset->source)->toBe('giphy')->and($asset->kind)->toBe('video')
        ->and($asset->mime)->toBe('video/mp4')->and($asset->user_id)->toBe($this->user->id);

    // 3. Solo se hablo con Giphy (API y CDN), nunca con otro destino.
    Http::assertSent(fn ($r) => in_array(parse_url($r->url(), PHP_URL_HOST), ['api.giphy.com', 'media2.giphy.com'], true));
    Http::assertNotSent(fn ($r) => ! in_array(parse_url($r->url(), PHP_URL_HOST), ['api.giphy.com', 'media2.giphy.com'], true));

    // 4. El compositor recibe el booleano y el componente lleva la atribucion.
    $brand = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user)->get(route('brands.posts.create', $brand))->assertInertia(fn ($p) => $p->where('giphy.enabled', true));
    expect(file_get_contents(resource_path('js/Components/Composer/GifPicker.vue')))->toContain('Powered by GIPHY');
});

test('CA-15: otro usuario no ve el asset importado (responde como inexistente)', function () {
    Http::fake();
    $asset = MediaAsset::factory()->for($this->user)->create(['source' => 'giphy', 'status' => MediaAsset::STATUS_READY]);

    $this->actingAs(User::factory()->create())->getJson(route('media.show', $asset))->assertNotFound(); // el alcance por usuario lo responde como inexistente
});
