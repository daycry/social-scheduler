<?php

use App\Jobs\ImportRemoteMedia;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\GiphySource;
use App\Services\Media\UrlGuard;
use App\Services\MediaUploadService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// T-67 · C-04b / D4: el MP4 elegido se importa por el pipeline de la URL con una lista cerrada de hosts de Giphy. Sin red.

const GIPHY_MP4 = 'https://media2.giphy.com/media/abc123/giphy.mp4';

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'services.giphy.api_key' => 'giphy-TEST-KEY']);
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->dns = ['media2.giphy.com' => ['151.101.2.2'], 'i.giphy.com' => ['151.101.2.2'], 'evil.example.com' => ['93.184.216.34']];
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => $this->dns[$host] ?? []));
});

function importGif(string $url, ?User $as = null)
{
    return test()->actingAs($as ?? test()->user)->postJson(route('media.import-gif'), ['url' => $url]);
}

test('un MP4 de un host de Giphy crea un asset pendiente con source=giphy y encola la descarga en la cola media', function () {
    Queue::fake();
    $res = importGif(GIPHY_MP4)->assertStatus(202)->assertJsonPath('asset.status', 'pendiente')->assertJsonPath('asset.source', 'giphy');

    $asset = MediaAsset::withoutUserScope()->findOrFail($res->json('asset.id'));
    expect($asset->source)->toBe('giphy')->and($asset->original_name)->toBe('giphy-abc123.mp4')->and($asset->source_ref)->toBe(GIPHY_MP4);
    Queue::assertPushed(ImportRemoteMedia::class, fn ($j) => $j->assetId === $asset->id && $j->reference === GIPHY_MP4 && $j->queue === 'media');
});

test('un host que no es de Giphy se rechaza antes de crear nada (lista cerrada)', function (string $url) {
    Queue::fake();
    Http::fake();

    importGif($url)->assertStatus(422)->assertJsonPath('message', 'Solo se pueden importar GIFs de Giphy.');

    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'otro dominio' => 'https://evil.example.com/media/abc123/giphy.mp4',
    'subdominio falso' => 'https://media2.giphy.com.evil.example.com/media/abc123/giphy.mp4',
    'sufijo falso' => 'https://evilgiphy.com/media/abc123/giphy.mp4',
    'IP interna' => 'https://127.0.0.1/giphy.mp4',
    'metadatos de la nube' => 'https://169.254.169.254/latest/meta-data/x.mp4',
    'http plano' => 'http://media2.giphy.com/media/abc123/giphy.mp4',
    'puerto raro' => 'https://media2.giphy.com:8443/media/abc123/giphy.mp4',
    'credenciales en la URL' => 'https://user:pass@media2.giphy.com/media/abc123/giphy.mp4',
    'no es un MP4' => 'https://media2.giphy.com/media/abc123/giphy.gif',
    'esquema file' => 'file:///etc/passwd',
]);

test('una redireccion fuera de Giphy se corta ANTES de pedir el destino (SSRF) y el asset queda invalido', function () {
    Http::fake(['media2.giphy.com/*' => Http::response('', 302, ['Location' => 'https://evil.example.com/dentro.mp4'])]);

    $asset = app(MediaUploadService::class)->importFromGiphy($this->user, GIPHY_MP4);

    expect($asset->fresh()->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->fresh()->error)->toContain('fuera de Giphy');
    Http::assertSentCount(1); // solo el primer salto: el destino fuera de la lista no se pidio
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.example.com'));
});

test('la lista permitida tambien se aplica a una IP privada que resuelva un host de Giphy (UrlGuard)', function () {
    $this->dns['media2.giphy.com'] = ['10.0.0.5'];
    Http::fake();

    importGif(GIPHY_MP4)->assertStatus(422);
    Http::assertNothingSent();
});

test('un MP4 valido de Giphy descargado con ImportRemoteMedia acaba «listo» con source=giphy', function () {
    if (! str_contains((string) shell_exec('ffmpeg -version 2>&1'), 'ffmpeg version')) {
        $this->markTestSkipped('ffmpeg no disponible para crear el MP4 de prueba.');
    }
    $file = tempnam(sys_get_temp_dir(), 'gif').'.mp4';
    exec('ffmpeg -loglevel error -y -f lavfi -i testsrc=duration=2:size=160x120:rate=25 -pix_fmt yuv420p '.escapeshellarg($file).' 2>&1', $out, $code);
    expect($code)->toBe(0);
    $body = (string) file_get_contents($file);
    @unlink($file);
    Http::fake(['media2.giphy.com/*' => Http::response($body, 200, ['Content-Type' => 'video/mp4', 'Content-Length' => (string) strlen($body)])]);

    $asset = app(MediaUploadService::class)->importFromGiphy($this->user, GIPHY_MP4);

    $asset = $asset->fresh(); // la cola de los tests es sincrona: la descarga ya ha corrido
    expect($asset->status)->toBe(MediaAsset::STATUS_READY)->and($asset->source)->toBe('giphy')->and($asset->kind)->toBe('video')
        ->and($asset->mime)->toBe('video/mp4')->and($asset->duration)->toBeLessThan(3.0);
});

test('el importador usa GiphySource para source=giphy y rechaza construirlo con una URL de otro host', function () {
    expect(fn () => new GiphySource(app(UrlGuard::class), 'https://evil.example.com/x.mp4'))->toThrow(InvalidArgumentException::class);
    expect((new GiphySource(app(UrlGuard::class), GIPHY_MP4))->name())->toBe('giphy-abc123.mp4');
});

test('cuenta para el limite de 3 importaciones vivas y exige sesion y clave', function () {
    Queue::fake();
    foreach (range(1, 3) as $i) {
        importGif("https://media2.giphy.com/media/abc$i/giphy.mp4")->assertStatus(202);
    }
    importGif('https://media2.giphy.com/media/abc9/giphy.mp4')->assertStatus(422);

    config(['services.giphy.api_key' => null]);
    importGif(GIPHY_MP4)->assertNotFound();

    auth()->logout();
    $this->postJson(route('media.import-gif'), ['url' => GIPHY_MP4])->assertUnauthorized();
});
