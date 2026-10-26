<?php

use App\Jobs\ImportRemoteMedia;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\RemoteMediaImporter;
use App\Services\Media\RemotePass;
use App\Services\Media\RemoteSource;
use App\Services\Media\UrlGuard;
use App\Services\Media\UrlSource;
use App\Services\Media\ValidatedUrl;
use App\Services\MediaStorage;
use App\Services\MediaUploadService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// C-04a (T-35, CA-14/CA-16/CA-17): importacion remota por pasadas. Nunca hay red: DNS inyectado y `Http::fake`.

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->dns = ['cdn.example.com' => ['93.184.216.34'], 'other.example.com' => ['93.184.216.35']];
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => $this->dns[$host] ?? []));
});

/** Bytes de un JPEG real de $w x $h (con ruido para que pese algo). */
function importJpeg(int $w = 64, int $h = 48): string
{
    $img = imagecreatetruecolor($w, $h);
    for ($i = 0; $i < 200; $i++) {
        imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, 0xFFFFFF));
    }
    ob_start();
    imagejpeg($img, null, 90);

    return (string) ob_get_clean();
}

function startImport(string $url, ?User $user = null): MediaAsset
{
    $user ??= test()->user;
    $source = new UrlSource(app(UrlGuard::class), $url);
    $asset = app(MediaUploadService::class)->beginImport($user, MediaAsset::SOURCE_URL, $source->reference(), $source->name());
    ImportRemoteMedia::dispatchSync($asset->id, $url);

    return $asset->fresh();
}

function jpegHeaders(int $length, array $extra = []): array
{
    return ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) $length] + $extra;
}

test('ImportRemoteMedia: una URL JPEG valida acaba en un asset «listo» (CA-14)', function () {
    $body = importJpeg(80, 60);
    Http::fake(['cdn.example.com/*' => Http::response($body, 200, jpegHeaders(strlen($body)))]);

    $asset = startImport('https://cdn.example.com/foto.jpg?token=abc');

    expect($asset->status)->toBe(MediaAsset::STATUS_READY)
        ->and($asset->source)->toBe('url')
        ->and($asset->source_ref)->toBe('https://cdn.example.com/foto.jpg')
        ->and($asset->original_name)->toBe('foto.jpg')
        ->and($asset->mime)->toBe('image/jpeg')->and($asset->kind)->toBe('image')
        ->and($asset->width)->toBe(80)->and($asset->height)->toBe(60)
        ->and($asset->size)->toBe(strlen($body))
        ->and($asset->path)->toEndWith('.jpg')->not->toEndWith('.part');
    Storage::disk('local')->assertExists($asset->path);
    expect(Storage::disk('local')->allFiles('media'))->toHaveCount(1); // sin parciales
});

test('ImportRemoteMedia: la peticion lleva Range y no deja el token en el asset publico', function () {
    $body = importJpeg();
    Http::fake(['cdn.example.com/*' => Http::response($body, 200, jpegHeaders(strlen($body)))]);

    $asset = startImport('https://cdn.example.com/foto.jpg?token=SECRETO');

    Http::assertSent(fn (Request $r) => $r->hasHeader('Range', 'bytes=0-') && $r->hasHeader('Accept-Encoding', 'identity'));
    expect(json_encode($asset->toPublicArray()))->not->toContain('SECRETO')
        ->and(json_encode($asset->getAttributes()))->not->toContain('SECRETO');
});

test('ImportRemoteMedia: superar max_bytes por Content-Length deja el asset «invalido» (CA-16)', function () {
    config(['media.max_bytes.image' => 1000]);
    Http::fake(['cdn.example.com/*' => Http::response(str_repeat('a', 1500), 200, jpegHeaders(1500))]);

    $asset = startImport('https://cdn.example.com/grande.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('tamaño máximo');
    expect(Storage::disk('local')->allFiles('media'))->toBe([]);
});

test('ImportRemoteMedia: sin Content-Length, los bytes reales mandan (CA-16)', function () {
    config(['media.max_bytes.image' => 1000]);
    Http::fake(['cdn.example.com/*' => Http::response(str_repeat('a', 5000), 200, ['Content-Type' => 'image/jpeg'])]);

    $asset = startImport('https://cdn.example.com/miente.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('tamaño máximo');
    expect(Storage::disk('local')->allFiles('media'))->toBe([]);
});

test('ImportRemoteMedia: un Content-Length que miente a la baja tambien se corta por los bytes reales', function () {
    config(['media.max_bytes.image' => 1000]);
    Http::fake(['cdn.example.com/*' => Http::response(str_repeat('a', 5000), 200, jpegHeaders(10))]);

    $asset = startImport('https://cdn.example.com/miente.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('tamaño máximo');
});

test('ImportRemoteMedia: un MIME falseado se rechaza por el contenido real (CA-16)', function () {
    Http::fake(['cdn.example.com/*' => Http::response('<html><body>no soy una imagen, soy una pagina</body></html>', 200, jpegHeaders(55))]);

    $asset = startImport('https://cdn.example.com/falso.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('no coincide');
    expect(Storage::disk('local')->allFiles('media'))->toBe([]);
});

test('ImportRemoteMedia: un JPEG que se declara PNG se rechaza', function () {
    $body = importJpeg();
    Http::fake(['cdn.example.com/*' => Http::response($body, 200, ['Content-Type' => 'image/png', 'Content-Length' => (string) strlen($body)])]);

    expect(startImport('https://cdn.example.com/x.png')->error)->toContain('no coincide');
});

test('ImportRemoteMedia: un tipo no admitido en la cabecera se rechaza antes de seguir', function (string $type) {
    Http::fake(['cdn.example.com/*' => Http::response('x', 200, ['Content-Type' => $type, 'Content-Length' => '1'])]);

    $asset = startImport('https://cdn.example.com/x.bin');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('no admitido');
})->with(['text/html', 'application/pdf', 'image/svg+xml', 'application/octet-stream', 'image/gif', '']);

test('ImportRemoteMedia: un error 404 deja el asset invalido con el motivo', function () {
    Http::fake(['cdn.example.com/*' => Http::response('nada', 404)]);

    $asset = startImport('https://cdn.example.com/no.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('404');
});

test('ImportRemoteMedia: un fichero grande se importa en varias pasadas con Range e If-Range (CA-17)', function () {
    $body = importJpeg(120, 90);
    $n = strlen($body);
    $a = intdiv($n, 3);
    $b = intdiv($n * 2, 3);
    $slice = fn (int $from, int $to) => Http::response(substr($body, $from, $to - $from + 1), 206, [
        'Content-Type' => 'image/jpeg', 'Content-Range' => "bytes $from-$to/$n", 'ETag' => '"v1"', 'Accept-Ranges' => 'bytes',
    ]);
    Http::fake(['cdn.example.com/*' => Http::sequence()->pushResponse($slice(0, $a - 1))->pushResponse($slice($a, $b - 1))->pushResponse($slice($b, $n - 1))]);

    $asset = startImport('https://cdn.example.com/largo.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_READY)->and($asset->size)->toBe($n)->and($asset->import_state['passes'])->toBe(3);
    Http::assertSentCount(3);
    $ranges = Http::recorded()->map(fn ($pair) => $pair[0]->header('Range')[0] ?? null)->all();
    expect($ranges)->toBe(['bytes=0-', "bytes=$a-", "bytes=$b-"]);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Range', "bytes=$a-") && $r->hasHeader('If-Range', '"v1"'));
    Http::assertSent(fn (Request $r) => $r->hasHeader('Range', "bytes=$b-") && $r->hasHeader('If-Range', '"v1"'));
});

test('ImportRemoteMedia: el progreso (bytes y total) queda en el estado entre pasadas', function () {
    $body = importJpeg(120, 90);
    $n = strlen($body);
    $half = intdiv($n, 2);
    Http::fake(['cdn.example.com/*' => Http::response(substr($body, 0, $half), 206, [
        'Content-Type' => 'image/jpeg', 'Content-Range' => 'bytes 0-'.($half - 1)."/$n", 'ETag' => '"v1"',
    ])]);

    // Se llama al importador directamente: una sola pasada, sin reencolar.
    $source = new UrlSource(app(UrlGuard::class), 'https://cdn.example.com/largo.jpg');
    $asset = app(MediaUploadService::class)->beginImport($this->user, 'url', $source->reference(), $source->name());

    $done = app(RemoteMediaImporter::class)->run($asset, $source);
    $asset = $asset->fresh();

    expect($done)->toBeFalse()
        ->and($asset->status)->toBe(MediaAsset::STATUS_PENDING)
        ->and($asset->toPublicArray())->toMatchArray(['bytes' => $half, 'total' => $n])
        ->and(Storage::disk('local')->size($asset->path))->toBe($half); // el punto de control es el parcial
});

test('ImportRemoteMedia: sin soporte de Range y sin terminar, el error es claro', function () {
    // 200 con el Content-Length entero pero solo la mitad llega (la pasada se corto) y el servidor no anuncia Accept-Ranges.
    $body = importJpeg(120, 90);
    Http::fake(['cdn.example.com/*' => Http::response(substr($body, 0, 400), 200, jpegHeaders(strlen($body)))]);

    $asset = startImport('https://cdn.example.com/lento.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('no permite descargas por partes');
    expect(Storage::disk('local')->allFiles('media'))->toBe([]);
});

test('ImportRemoteMedia: si el remoto responde 200 a una reanudacion se reinicia UNA vez y luego falla', function () {
    $body = importJpeg(120, 90);
    $n = strlen($body);
    $half = intdiv($n, 2);
    $first = fn () => Http::response(substr($body, 0, $half), 206, ['Content-Type' => 'image/jpeg', 'Content-Range' => 'bytes 0-'.($half - 1)."/$n", 'ETag' => '"v1"']);
    $changed = fn () => Http::response($body, 200, jpegHeaders($n, ['ETag' => '"v2"']));
    Http::fake(['cdn.example.com/*' => Http::sequence()->pushResponse($first())->pushResponse($changed())->pushResponse($first())->pushResponse($changed())]);

    $asset = startImport('https://cdn.example.com/cambia.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('cambia durante la descarga');
    expect(Storage::disk('local')->allFiles('media'))->toBe([]);
});

test('ImportRemoteMedia: tras un reinicio por cambio, la segunda vuelta completa con el nuevo contenido', function () {
    $body = importJpeg(120, 90);
    $n = strlen($body);
    $half = intdiv($n, 2);
    Http::fake(['cdn.example.com/*' => Http::sequence()
        ->pushResponse(Http::response(substr($body, 0, $half), 206, ['Content-Type' => 'image/jpeg', 'Content-Range' => 'bytes 0-'.($half - 1)."/$n", 'ETag' => '"v1"']))
        ->pushResponse(Http::response($body, 200, jpegHeaders($n, ['ETag' => '"v2"']))) // reanudacion respondida con 200: cambio
        ->pushResponse(Http::response($body, 200, jpegHeaders($n, ['ETag' => '"v2"'])))]); // reinicio desde 0

    $asset = startImport('https://cdn.example.com/cambia.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_READY)->and($asset->size)->toBe($n);
});

test('ImportRemoteMedia: una URL interna se rechaza sin ninguna peticion (CA-15)', function (string $url) {
    $asset = startImport($url);

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->not->toBe('');
    Http::assertNothingSent();
    expect(Storage::disk('local')->allFiles('media'))->toBe([]);
})->with([
    'http://127.0.0.1/a.jpg', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/a.jpg', 'http://[::ffff:7f00:1]/a.jpg',
    'http://0.0.0.0/a.jpg', 'http://localhost/a.jpg', 'http://user:pass@cdn.example.com/a.jpg', 'ftp://cdn.example.com/a.jpg',
    'http://cdn.example.com:8080/a.jpg', 'http://2130706433/a.jpg',
]);

test('ImportRemoteMedia: una redireccion a una IP interna se rechaza sin pedirla (CA-15)', function () {
    Http::fake(['cdn.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

    $asset = startImport('https://cdn.example.com/redir.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID);
    Http::assertSentCount(1);
});

test('ImportRemoteMedia: una redireccion a otro host publico se sigue y el cuerpo de la redireccion no se queda en el parcial', function () {
    $body = importJpeg();
    Http::fake([
        'cdn.example.com/*' => Http::response('REDIRECCION', 302, ['Location' => 'https://other.example.com/real.jpg']),
        'other.example.com/*' => Http::response($body, 200, jpegHeaders(strlen($body))),
    ]);

    $asset = startImport('https://cdn.example.com/redir.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_READY)->and($asset->size)->toBe(strlen($body));
});

test('ImportRemoteMedia: DNS rebinding — si en la segunda pasada el host ya resuelve a una IP privada, se rechaza', function () {
    $body = importJpeg(120, 90);
    $n = strlen($body);
    $half = intdiv($n, 2);
    $resolutions = 0;
    app()->instance(UrlGuard::class, new UrlGuard(function () use (&$resolutions) {
        return $resolutions++ === 0 ? ['93.184.216.34'] : ['10.0.0.7'];
    }));
    Http::fake(['cdn.example.com/*' => Http::response(substr($body, 0, $half), 206, ['Content-Type' => 'image/jpeg', 'Content-Range' => 'bytes 0-'.($half - 1)."/$n"])]);

    $asset = startImport('https://cdn.example.com/rebind.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('no permitida');
    Http::assertSentCount(1);
    expect(Storage::disk('local')->allFiles('media'))->toBe([]);
});

test('ImportRemoteMedia: cada pasada fija la conexion a la IP validada', function () {
    $body = importJpeg(120, 90);
    $n = strlen($body);
    $half = intdiv($n, 2);
    $pins = [];
    $spy = new class(fn (string $h) => ['93.184.216.34']) extends UrlGuard
    {
        public array $pins = [];

        public function client(ValidatedUrl $v): PendingRequest
        {
            $client = parent::client($v);
            $this->pins[] = $client->getOptions()['curl'][CURLOPT_RESOLVE][0];

            return $client;
        }
    };
    app()->instance(UrlGuard::class, $spy);
    Http::fake(['cdn.example.com/*' => Http::sequence()
        ->pushResponse(Http::response(substr($body, 0, $half), 206, ['Content-Type' => 'image/jpeg', 'Content-Range' => 'bytes 0-'.($half - 1)."/$n"]))
        ->pushResponse(Http::response(substr($body, $half), 206, ['Content-Type' => 'image/jpeg', 'Content-Range' => "bytes $half-".($n - 1)."/$n"]))]);

    startImport('https://cdn.example.com/a.jpg');

    expect($spy->pins)->toBe(['cdn.example.com:443:93.184.216.34', 'cdn.example.com:443:93.184.216.34']);
});

test('ImportRemoteMedia: con almacenamiento S3/R2 falla con un error claro', function () {
    $r2 = new class extends MediaStorage
    {
        public function isS3(): bool
        {
            return true;
        }
    };
    app()->instance(MediaStorage::class, $r2);
    app()->forgetInstance(MediaUploadService::class);

    expect(fn () => app(MediaUploadService::class)->beginImport($this->user, 'url', 'https://cdn.example.com/a.jpg', 'a.jpg'))
        ->toThrow(InvalidArgumentException::class, 'almacenamiento local');

    // Si el asset ya existia (configuracion cambiada con jobs en cola), el importador lo invalida con el mismo motivo.
    $asset = MediaAsset::factory()->create(['status' => 'pendiente', 'path' => 'media/1/x.part', 'source' => 'url', 'user_id' => $this->user->id]);
    $done = app(RemoteMediaImporter::class)->run($asset, new UrlSource(app(UrlGuard::class), 'https://cdn.example.com/a.jpg'));

    expect($done)->toBeTrue()->and($asset->fresh()->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->fresh()->error)->toContain('almacenamiento local');
    Http::assertNothingSent();
});

test('ImportRemoteMedia: un fallo transitorio (503) reintenta y, agotados los intentos, deja el asset invalido', function () {
    Http::fake(['cdn.example.com/*' => Http::response('ocupado', 503)]);
    $source = new UrlSource(app(UrlGuard::class), 'https://cdn.example.com/a.jpg');
    $asset = app(MediaUploadService::class)->beginImport($this->user, 'url', $source->reference(), $source->name());
    $job = new ImportRemoteMedia($asset->id, 'https://cdn.example.com/a.jpg');

    expect(fn () => $job->handle(app(RemoteMediaImporter::class)))->toThrow(RuntimeException::class, '503');
    expect($asset->fresh()->status)->toBe(MediaAsset::STATUS_PENDING); // aun puede reintentarse

    $job->failed(new RuntimeException('x'));

    expect($asset->fresh()->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->fresh()->error)->toContain('inténtalo de nuevo');
    expect(Storage::disk('local')->allFiles('media'))->toBe([]);
});

test('ImportRemoteMedia: el job va en la cola media, cifrado, unico y por debajo del timeout del worker', function () {
    $job = new ImportRemoteMedia(5, 'https://cdn.example.com/a.jpg');

    expect($job->queue)->toBe('media')
        ->and($job)->toBeInstanceOf(ShouldBeEncrypted::class)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toContain('5')
        ->and($job->timeout)->toBe(110)->toBeLessThan(120)
        ->and((int) config('media.import_budget_seconds'))->toBeLessThan($job->timeout);
});

test('ImportRemoteMedia: un asset que ya no esta pendiente o no existe no se toca', function () {
    Http::fake();
    $ready = MediaAsset::factory()->create(['user_id' => $this->user->id, 'source' => 'url']);

    (new ImportRemoteMedia($ready->id, 'https://cdn.example.com/a.jpg'))->handle(app(RemoteMediaImporter::class));
    (new ImportRemoteMedia(999999, 'https://cdn.example.com/a.jpg'))->handle(app(RemoteMediaImporter::class));

    Http::assertNothingSent();
    expect($ready->fresh()->status)->toBe(MediaAsset::STATUS_READY);
});

test('ImportRemoteMedia: pruneAbandoned borra el parcial de un asset pendiente antiguo', function () {
    $source = new UrlSource(app(UrlGuard::class), 'https://cdn.example.com/a.jpg');
    $asset = app(MediaUploadService::class)->beginImport($this->user, 'url', $source->reference(), $source->name());
    Storage::disk('local')->put($asset->path, 'parcial');
    MediaAsset::withoutUserScope()->whereKey($asset->id)->update(['created_at' => now()->subHours(30)]);

    expect(app(MediaUploadService::class)->pruneAbandoned(24))->toBe(1);
    Storage::disk('local')->assertMissing($asset->path);
});

test('ImportRemoteMedia (revision): un fallo de red no filtra la URL con su token a la excepcion ni al log', function () {
    Http::fake(['cdn.example.com/*' => Http::failedConnection('cURL error 7: Failed to connect to https://cdn.example.com/a.jpg?token=SECRETO')]);
    $source = new UrlSource(app(UrlGuard::class), 'https://cdn.example.com/a.jpg?token=SECRETO');
    $asset = app(MediaUploadService::class)->beginImport($this->user, 'url', $source->reference(), $source->name());

    try {
        (new ImportRemoteMedia($asset->id, 'https://cdn.example.com/a.jpg?token=SECRETO'))->handle(app(RemoteMediaImporter::class));
        $this->fail('Debia lanzar');
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain('SECRETO')->and($e->getPrevious())->toBeNull();
    }
});

test('ImportRemoteMedia (revision): si el remoto anuncia Accept-Ranges pero responde 200 con el mismo ETag, el motivo es «no permite partes»', function () {
    $body = importJpeg(120, 90);
    $n = strlen($body);
    $half = intdiv($n, 2);
    $first = fn () => Http::response(substr($body, 0, $half), 206, ['Content-Type' => 'image/jpeg', 'Content-Range' => 'bytes 0-'.($half - 1)."/$n", 'ETag' => '"v1"', 'Accept-Ranges' => 'bytes']);
    $ignored = fn () => Http::response($body, 200, jpegHeaders($n, ['ETag' => '"v1"', 'Accept-Ranges' => 'bytes']));
    Http::fake(['cdn.example.com/*' => Http::sequence()->pushResponse($first())->pushResponse($ignored())->pushResponse($first())->pushResponse($ignored())]);

    $asset = startImport('https://cdn.example.com/ignora.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('no permite descargas por partes');
});

test('ImportRemoteMedia (revision): sin validador, un total distinto entre pasadas reinicia en vez de empalmar dos versiones', function () {
    $body = importJpeg(120, 90);
    $n = strlen($body);
    $half = intdiv($n, 2);
    Http::fake(['cdn.example.com/*' => Http::sequence()
        ->pushResponse(Http::response(substr($body, 0, $half), 206, ['Content-Type' => 'image/jpeg', 'Content-Range' => 'bytes 0-'.($half - 1)."/$n"]))
        ->pushResponse(Http::response('xx', 206, ['Content-Type' => 'image/jpeg', 'Content-Range' => "bytes $half-".($half + 1).'/'.($n + 500)])) // otro total: el fichero cambio
        ->pushResponse(Http::response($body, 200, jpegHeaders($n)))]); // reinicio desde 0

    $asset = startImport('https://cdn.example.com/cambia.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_READY)->and($asset->size)->toBe($n)->and($asset->import_state['restarts'])->toBe(1);
    Http::assertSent(fn (Request $r) => ! $r->hasHeader('If-Range'));
});

/** Fuente de mentira para probar el importador sin red: devuelve siempre la pasada que se le da. */
function fakeSource(RemotePass $pass, ?float &$remaining = null): RemoteSource
{
    return new class($pass, $remaining) implements RemoteSource
    {
        public function __construct(private RemotePass $pass, private ?float &$remaining) {}

        public function fetch($sink, int $offset, ?string $validator, Closure $policy, int $cap, float $deadline, ?int $knownTotal = null): RemotePass
        {
            $this->remaining = $deadline - microtime(true);

            return $this->pass;
        }

        public function name(): string
        {
            return 'a.jpg';
        }

        public function reference(): string
        {
            return 'https://cdn.example.com/a.jpg';
        }
    };
}

test('ImportRemoteMedia (revision): tres pasadas seguidas sin un byte nuevo dan la descarga por fallida (sin bucle sin espera)', function () {
    $source = fakeSource(new RemotePass(false, false, true, 1000, '"v1"', 'image/jpeg', 0));
    $asset = app(MediaUploadService::class)->beginImport($this->user, 'url', $source->reference(), $source->name());
    $importer = app(RemoteMediaImporter::class);

    expect($importer->run($asset->fresh(), $source))->toBeFalse()->and($importer->run($asset->fresh(), $source))->toBeFalse();
    expect($importer->run($asset->fresh(), $source))->toBeTrue();
    expect($asset->fresh()->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->fresh()->error)->toContain('no avanza');
});

test('ImportRemoteMedia (revision): la reanudacion de un asset ya renombrado no genera «.jpg.jpg»', function () {
    $body = importJpeg(80, 60);
    $n = strlen($body);
    $asset = app(MediaUploadService::class)->beginImport($this->user, 'url', 'https://cdn.example.com/a.jpg', 'a.jpg');
    // Un intento anterior renombro el parcial a su nombre final pero no llego a guardarlo como terminado.
    $asset->forceFill(['path' => 'media/'.$this->user->id.'/ya.jpg', 'import_state' => ['bytes' => $n, 'total' => $n, 'validator' => '"v1"', 'ranges' => true, 'mime' => 'image/jpeg', 'sniffed' => true, 'passes' => 1]])->save();
    Storage::disk('local')->put($asset->path, $body);
    Http::fake(['cdn.example.com/*' => Http::response('', 416, ['Content-Range' => "bytes */$n"])]);

    (new ImportRemoteMedia($asset->id, 'https://cdn.example.com/a.jpg'))->handle(app(RemoteMediaImporter::class));

    $fresh = $asset->fresh();
    expect($fresh->status)->toBe(MediaAsset::STATUS_READY)->and($fresh->path)->toBe('media/'.$this->user->id.'/ya.jpg');
    expect(Storage::disk('local')->allFiles('media'))->toBe(['media/'.$this->user->id.'/ya.jpg']);
});

test('ImportRemoteMedia (revision): si finalize() revienta, el fichero vuelve a su nombre de parcial y el path en base de datos no cambia', function () {
    $body = importJpeg(80, 60);
    Http::fake(['cdn.example.com/*' => Http::response($body, 200, jpegHeaders(strlen($body)))]);
    $uploads = Mockery::mock(MediaUploadService::class)->makePartial();
    $uploads->shouldReceive('finalize')->andThrow(new RuntimeException('ffprobe se colgo'));
    $importer = new RemoteMediaImporter(app(MediaStorage::class), $uploads);
    $source = new UrlSource(app(UrlGuard::class), 'https://cdn.example.com/a.jpg');
    $asset = app(MediaUploadService::class)->beginImport($this->user, 'url', $source->reference(), $source->name());
    $partial = $asset->path;

    expect(fn () => $importer->run($asset, $source))->toThrow(RuntimeException::class, 'ffprobe');

    expect(MediaAsset::withoutUserScope()->find($asset->id)->path)->toBe($partial)
        ->and(Storage::disk('local')->allFiles('media'))->toBe([$partial]);
});

test('ImportRemoteMedia (revision): el presupuesto de la pasada reserva tiempo para finalize()', function () {
    config(['media.import_budget_seconds' => 80, 'media.import_finalize_reserve_seconds' => 10]);
    $remaining = null;
    $source = fakeSource(new RemotePass(false, false, true, 1000, null, 'image/jpeg', 10), $remaining);
    $asset = app(MediaUploadService::class)->beginImport($this->user, 'url', $source->reference(), $source->name());

    app(RemoteMediaImporter::class)->run($asset, $source);

    expect($remaining)->toBeGreaterThan(65.0)->toBeLessThanOrEqual(70.0);
});

test('ImportRemoteMedia (revision): el job se reencola sin espera', function () {
    Queue::fake();
    $body = importJpeg(120, 90);
    $half = intdiv(strlen($body), 2);
    Http::fake(['cdn.example.com/*' => Http::response(substr($body, 0, $half), 206, ['Content-Type' => 'image/jpeg', 'Content-Range' => 'bytes 0-'.($half - 1).'/'.strlen($body), 'ETag' => '"v1"'])]);
    $source = new UrlSource(app(UrlGuard::class), 'https://cdn.example.com/a.jpg');
    $asset = app(MediaUploadService::class)->beginImport($this->user, 'url', $source->reference(), $source->name());

    (new ImportRemoteMedia($asset->id, 'https://cdn.example.com/a.jpg'))->handle(app(RemoteMediaImporter::class));

    Queue::assertPushed(ImportRemoteMedia::class, fn ($job) => $job->delay === null);
});

test('tope de pasadas totales: una importacion que lleva demasiadas se da por fallida (gap F5-2 #1)', function () {
    $pass = new RemotePass(false, false, true, 1_000_000, '"v1"', 'image/jpeg', 200_000);

    config(['media.import_max_passes' => 3]);
    $asset = app(MediaUploadService::class)->beginImport($this->user, MediaAsset::SOURCE_URL, 'https://cdn.example.com/a.jpg', 'a.jpg');
    $asset->forceFill(['import_state' => ['bytes' => 0, 'passes' => 3]])->save();
    expect(app(RemoteMediaImporter::class)->run($asset->fresh(), fakeSource($pass)))->toBeTrue()
        ->and($asset->fresh()->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->fresh()->error)->toContain('tarda demasiado');
});

test('la pasada deja un latido (runs) que la UI usa para distinguir una cola parada', function () {
    $asset = app(MediaUploadService::class)->beginImport($this->user, MediaAsset::SOURCE_URL, 'https://cdn.example.com/a.jpg', 'a.jpg');
    expect($asset->fresh()->toPublicArray()['runs'])->toBeNull();

    app(RemoteMediaImporter::class)->run($asset->fresh(), fakeSource(new RemotePass(false, false, true, 1_000_000, '"v1"', 'image/jpeg', 200_000)));

    expect($asset->fresh()->toPublicArray()['runs'])->toBe(1);
});
