<?php

use App\Jobs\ImportRemoteMedia;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\DriveFileSource;
use App\Services\Media\RemoteMediaImporter;
use App\Services\Media\UrlGuard;
use App\Services\MediaUploadService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// C-04b (T-45, CA-18): flujo completo de Drive (metadatos, tipo y tamano, tramos con Range, token caducado, cifrado del job).
// Nunca se llama a Drive real: `Http::fake`, DNS inyectado y `preventStrayRequests`.

const FLOW_TOKEN = 'ya29.flow-secret-token';
const FLOW_FILE = 'FILE_abc-123';

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'services.google.client_id' => 'cid.apps.googleusercontent.com']);
    $this->user = User::factory()->create();
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['142.250.200.74']));
    $this->fake = function (array $stubs): void {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    };
});

function flowJpeg(int $w = 120, int $h = 90): string
{
    $img = imagecreatetruecolor($w, $h);
    for ($i = 0; $i < 300; $i++) {
        imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, 0xFFFFFF));
    }
    ob_start();
    imagejpeg($img, null, 90);

    return (string) ob_get_clean();
}

/** Respuestas de tokeninfo y metadatos validas para un fichero de $size bytes. */
function driveOk(int $size, string $mime = 'image/jpeg'): array
{
    return [
        'oauth2.googleapis.com/tokeninfo' => Http::response(['aud' => 'cid.apps.googleusercontent.com', 'scope' => DriveFileSource::SCOPE]),
        'www.googleapis.com/drive/v3/files/'.FLOW_FILE.'?fields=*' => Http::response(['name' => 'foto.jpg', 'mimeType' => $mime, 'size' => (string) $size]),
    ];
}

function driveMedia(): string
{
    return 'www.googleapis.com/drive/v3/files/'.FLOW_FILE.'?alt=media';
}

/** Crea el asset como el endpoint (sin ejecutar el job) y devuelve el job tal como se encolo. */
function beginDrive(): array
{
    Queue::fake();
    $asset = app(MediaUploadService::class)->importFromDrive(test()->user, FLOW_FILE, FLOW_TOKEN);
    $job = Queue::pushed(ImportRemoteMedia::class)->first();

    return [$asset, $job];
}

/** Ejecuta las pasadas del job (la cola esta simulada): `handle()` hasta que el asset deja de estar pendiente. */
function runDrive(ImportRemoteMedia $job): void
{
    for ($i = 0; $i < 6; $i++) {
        $job->handle(app(RemoteMediaImporter::class));

        if (MediaAsset::withoutUserScope()->find($job->assetId)->status !== MediaAsset::STATUS_PENDING) {
            return;
        }
    }
}

test('metadatos y descarga: el asset acaba «listo» y toda peticion con Bearer va solo a www.googleapis.com', function () {
    $body = flowJpeg();
    ($this->fake)(driveOk(strlen($body)) + [driveMedia() => Http::response($body, 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($body)])]);

    [$asset, $job] = beginDrive();
    expect($asset->status)->toBe(MediaAsset::STATUS_PENDING)->and($asset->source)->toBe('drive');
    runDrive($job);

    $asset = $asset->fresh();
    expect($asset->status)->toBe(MediaAsset::STATUS_READY)->and($asset->mime)->toBe('image/jpeg')->and($asset->size)->toBe(strlen($body))
        ->and(json_encode($asset->getAttributes()))->not->toContain('flow-secret');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'alt=media') && $r->hasHeader('Authorization', 'Bearer '.FLOW_TOKEN) && $r->hasHeader('Range', 'bytes=0-'));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'tokeninfo') && $r->hasHeader('Authorization') === false);
    foreach (Http::recorded() as [$request]) {
        if ($request->hasHeader('Authorization')) {
            expect(parse_url($request->url(), PHP_URL_HOST))->toBe('www.googleapis.com');
        }
    }
});

test('un fichero grande se descarga por tramos con Range e If-Range y se reanuda', function () {
    $body = flowJpeg(200, 150);
    $n = strlen($body);
    $a = intdiv($n, 2);
    $slice = fn (int $from, int $to) => Http::response(substr($body, $from, $to - $from + 1), 206, ['Content-Type' => 'image/jpeg', 'Content-Range' => "bytes $from-$to/$n", 'ETag' => '"d1"', 'Accept-Ranges' => 'bytes']);
    ($this->fake)(driveOk($n) + [driveMedia() => Http::sequence()->pushResponse($slice(0, $a - 1))->pushResponse($slice($a, $n - 1))]);

    [$asset, $job] = beginDrive();
    runDrive($job);

    expect($asset->fresh()->status)->toBe(MediaAsset::STATUS_READY)->and($asset->fresh()->import_state['passes'])->toBe(2);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Range', "bytes=$a-") && $r->hasHeader('If-Range', '"d1"') && $r->hasHeader('Authorization', 'Bearer '.FLOW_TOKEN));
});

test('un 401 a mitad de la descarga deja el asset «invalido» con «Vuelve a elegir el fichero en Drive» y borra el parcial', function () {
    $body = flowJpeg();
    $half = intdiv(strlen($body), 2);
    ($this->fake)(driveOk(strlen($body)) + [driveMedia() => Http::sequence()
        ->pushResponse(Http::response(substr($body, 0, $half), 206, ['Content-Type' => 'image/jpeg', 'Content-Range' => 'bytes 0-'.($half - 1).'/'.strlen($body), 'ETag' => '"d1"', 'Accept-Ranges' => 'bytes']))
        ->pushResponse(Http::response(['error' => ['code' => 401]], 401)),
    ]);

    [$asset, $job] = beginDrive();
    runDrive($job);

    expect($asset->fresh()->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->fresh()->error)->toContain('Vuelve a elegir el fichero en Drive');
    expect(Storage::disk('local')->allFiles('media'))->toBe([]);
});

test('un fichero demasiado grande se rechaza al pedir los metadatos y, si Drive miente, al descargar', function () {
    config(['media.max_bytes.image' => 1000]);
    ($this->fake)(driveOk(5000));
    Queue::fake();

    expect(fn () => app(MediaUploadService::class)->importFromDrive($this->user, FLOW_FILE, FLOW_TOKEN))->toThrow(InvalidArgumentException::class, 'tamaño máximo');
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);

    // Los metadatos dicen 900 bytes pero la descarga declara 5000.
    ($this->fake)(driveOk(900) + [driveMedia() => Http::response(str_repeat('a', 5000), 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => '5000'])]);
    [$asset, $job] = beginDrive();
    runDrive($job);

    expect($asset->fresh()->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->fresh()->error)->toContain('tamaño máximo');
});

test('una redireccion a googleusercontent.com no recibe el token: la importacion falla sin hacer esa peticion', function () {
    $body = flowJpeg();
    ($this->fake)(driveOk(strlen($body)) + [
        driveMedia() => Http::response('', 302, ['Location' => 'https://doc-0g-ak-docs.googleusercontent.com/docs/securesc/x?e=download']),
        'doc-0g-ak-docs.googleusercontent.com/*' => Http::response($body, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    [$asset, $job] = beginDrive();
    runDrive($job);

    expect($asset->fresh()->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->fresh()->error)->toContain('Vuelve a elegir el fichero en Drive');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'googleusercontent.com'));
});

test('el job va cifrado: el token no aparece en claro en la tabla jobs', function () {
    expect(new ImportRemoteMedia(1, FLOW_FILE, FLOW_TOKEN))->toBeInstanceOf(ShouldBeEncrypted::class);

    config(['queue.default' => 'database']);
    DB::table('jobs')->delete();
    ($this->fake)(driveOk(100));
    app(MediaUploadService::class)->importFromDrive($this->user, FLOW_FILE, FLOW_TOKEN);

    $payload = (string) DB::table('jobs')->value('payload');
    expect($payload)->not->toBe('')->and($payload)->not->toContain('flow-secret')->not->toContain(FLOW_TOKEN);
    expect((string) json_decode($payload, true)['data']['command'])->not->toContain(FLOW_FILE);
    DB::table('jobs')->delete();
});

test('el fileId y el token se validan en la fuente', function () {
    $guard = new UrlGuard(fn () => ['142.250.200.74']);

    expect(fn () => new DriveFileSource($guard, '../x', 'tok'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DriveFileSource($guard, 'a?alt=media&b=1', 'tok'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new DriveFileSource($guard, FLOW_FILE, "tok\r\nX-Evil: 1"))->toThrow(InvalidArgumentException::class)
        ->and((new DriveFileSource($guard, FLOW_FILE, 'tok'))->reference())->toBe(FLOW_FILE);
});
