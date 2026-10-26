<?php

use App\Jobs\ImportRemoteMedia;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\ImportFailedException;
use App\Services\Media\RemoteMediaImporter;
use App\Services\Media\UrlGuard;
use App\Services\Media\UrlSource;
use App\Services\Media\ValidatedUrl;
use App\Services\MediaUploadService;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Support\Facades\Storage;

// Fase 5 (revision, gap 1): TODA la bateria anterior iba con `Http::fake`, que no pasa por el manejador cURL de Guzzle, y por eso
// no vio que Guzzle 8 rechaza (`InvalidArgumentException`) las opciones `CURLOPT_PROXY`, `FOLLOWLOCATION`, `PROTOCOLS_STR`... en la
// clave `curl`. Estos tests usan el `CurlFactory` REAL y un servidor local de PHP en 127.0.0.1 (sin red externa): si una version de
// Guzzle vuelve a rechazar algo de lo que mandamos, fallan aqui.

/**
 * Guardia de test: valida como el real (ni DNS ni IPs publicas) pero devuelve una peticion al servidor local de PHP, fijando el
 * host de mentira `cdn.example.com` a 127.0.0.1 con el `CURLOPT_RESOLVE` de verdad. SOLO existe en este fichero de tests.
 */
class LoopbackGuard extends UrlGuard
{
    public function __construct(public int $port) {}

    public function validate(string $url): ValidatedUrl
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);

        return new ValidatedUrl("http://cdn.example.com:{$this->port}{$path}".($query ? "?$query" : ''), 'http', 'cdn.example.com', $this->port, '127.0.0.1', ['127.0.0.1']);
    }
}

beforeAll(function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ss-loopback-'.bin2hex(random_bytes(4));
    mkdir($dir);

    $img = imagecreatetruecolor(120, 90);
    for ($i = 0; $i < 400; $i++) {
        imagesetpixel($img, random_int(0, 119), random_int(0, 89), random_int(0, 0xFFFFFF));
    }
    imagejpeg($img, $dir.DIRECTORY_SEPARATOR.'ok.jpg', 90);

    file_put_contents($dir.DIRECTORY_SEPARATOR.'router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__.'/ok.jpg';
header_remove('X-Powered-By');

if ($path === '/missing.jpg') { http_response_code(404); echo 'nada'; return; }
if ($path === '/redirect.jpg') { http_response_code(302); header('Location: /ok.jpg'); echo 'REDIRECCION'; return; }
if ($path === '/slow.jpg') {
    $size = filesize($file);
    header('Content-Type: image/jpeg'); header('Content-Length: '.$size); header('Accept-Ranges: bytes');
    echo substr(file_get_contents($file), 0, 100); @ob_flush(); flush();
    for ($i = 0; $i < 60 && ! connection_aborted(); $i++) { usleep(100000); echo ' '; @ob_flush(); flush(); }
    return;
}
if ($path === '/huge.jpg') {
    header('Content-Type: image/jpeg'); header('Content-Length: 5000');
    echo str_repeat('a', 5000); return;
}
if ($path === '/chunked.jpg') {
    // Sin Content-Length (chunked): el tope solo se puede aplicar DURANTE el streaming. 400 trozos de 64 KB = ~25 MB si nadie corta.
    header('Content-Type: image/jpeg');
    for ($i = 0; $i < 400 && ! connection_aborted(); $i++) { echo str_repeat('a', 65536); @ob_flush(); flush(); usleep(2000); }
    return;
}
if ($path === '/drip.jpg') {
    // Goteo (gap F5-2 #1): soporta Range y entrega 1 byte por peticion, anunciando el total real.
    $body = file_get_contents($file); $size = strlen($body);
    header('Content-Type: image/jpeg'); header('Accept-Ranges: bytes'); header('ETag: "v1"');
    $from = preg_match('/^bytes=(\d+)-$/', $_SERVER['HTTP_RANGE'] ?? '', $m) ? (int) $m[1] : 0;
    http_response_code(206); header("Content-Range: bytes $from-$from/$size"); echo substr($body, $from, 1); return;
}
if ($path === '/chunk.jpg') {
    // CDN que limita cada 206 a un tramo fijo (como uno de 8 MB a escala): sano, con Range, pero en muchas pasadas.
    $body = file_get_contents($file); $size = strlen($body);
    header('Content-Type: image/jpeg'); header('Accept-Ranges: bytes'); header('ETag: "v1"');
    $from = preg_match('/^bytes=(\d+)-$/', $_SERVER['HTTP_RANGE'] ?? '', $m) ? (int) $m[1] : 0;
    if ($from >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); return; }
    $to = min($size - 1, $from + 699);
    http_response_code(206); header("Content-Range: bytes $from-$to/$size"); echo substr($body, $from, $to - $from + 1); return;
}
if ($path === '/flaky.jpg' || $path === '/flaky-slow.jpg') {
    // Alterna 503 y 206 (tramos de 700 B) contando peticiones en un fichero: las impares son 503 (la lenta tarda ~0,4 s en fallar).
    $counter = __DIR__.'/'.basename($path).'.count';
    $n = (int) @file_get_contents($counter) + 1; file_put_contents($counter, (string) $n);
    if ($n % 2 === 1) { if ($path === '/flaky-slow.jpg') { usleep(400000); } http_response_code(503); echo 'ocupado'; return; }
    $body = file_get_contents($file); $size = strlen($body);
    header('Content-Type: image/jpeg'); header('Accept-Ranges: bytes'); header('ETag: "v1"');
    $from = preg_match('/^bytes=(\d+)-$/', $_SERVER['HTTP_RANGE'] ?? '', $m) ? (int) $m[1] : 0;
    if ($from >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); return; }
    $to = min($size - 1, $from + 699);
    http_response_code(206); header("Content-Range: bytes $from-$to/$size"); echo substr($body, $from, $to - $from + 1); return;
}
if ($path === '/proxy-check.jpg') { header('Content-Type: image/jpeg'); header('Content-Length: '.filesize($file)); readfile($file); return; }

$body = file_get_contents($file);
$size = strlen($body);
header('Content-Type: image/jpeg'); header('Accept-Ranges: bytes'); header('ETag: "v1"');
if (preg_match('/^bytes=(\d+)-$/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
    $from = (int) $m[1];
    if ($from >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); return; }
    http_response_code(206); header("Content-Range: bytes $from-".($size - 1)."/$size");
    echo substr($body, $from); return;
}
header('Content-Length: '.$size); echo $body;
PHP);

    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) parse_url('tcp://'.stream_socket_get_name($sock, false), PHP_URL_PORT);
    fclose($sock);

    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir, $dir.DIRECTORY_SEPARATOR.'router.php'], [0 => ['pipe', 'r'], 1 => ['file', $dir.DIRECTORY_SEPARATOR.'out.log', 'w'], 2 => ['file', $dir.DIRECTORY_SEPARATOR.'err.log', 'w']], $pipes);

    for ($i = 0; $i < 50; $i++) {
        if (($c = @fsockopen('127.0.0.1', $port)) !== false) {
            fclose($c);
            break;
        }
        usleep(100000);
    }

    $GLOBALS['loopback'] = ['dir' => $dir, 'port' => $port, 'proc' => $proc, 'bytes' => file_get_contents($dir.DIRECTORY_SEPARATOR.'ok.jpg')];
});

afterAll(function () {
    $l = $GLOBALS['loopback'] ?? null;

    if ($l === null) {
        return;
    }

    proc_terminate($l['proc']);
    proc_close($l['proc']);
    foreach (glob($l['dir'].DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($l['dir']);
});

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->port = $GLOBALS['loopback']['port'];
    app()->instance(UrlGuard::class, new LoopbackGuard($this->port));
});

function loopbackImport(string $path): MediaAsset
{
    $url = "http://cdn.example.com$path";
    $source = new UrlSource(app(UrlGuard::class), $url);
    $asset = app(MediaUploadService::class)->beginImport(test()->user, MediaAsset::SOURCE_URL, $source->reference(), $source->name());
    ImportRemoteMedia::dispatchSync($asset->id, $url);

    return $asset->fresh();
}

test('CurlFactory real: las opciones de UrlGuard::requestOptions no las rechaza Guzzle (sin conectar)', function () {
    $guard = new UrlGuard(fn () => ['93.184.216.34']);
    $v = $guard->validate('https://cdn.example.com/a.jpg');
    $options = $guard->requestOptions($v) + [
        'sink' => fopen('php://temp', 'w+'), 'progress' => fn ($total, $now) => false, 'on_headers' => fn ($r) => null,
        'timeout' => 5, 'connect_timeout' => 3,
    ];

    $easy = (new CurlFactory(1))->create(new PsrRequest('GET', $v->url, ['Range' => 'bytes=0-']), $options);

    expect($easy)->toBeInstanceOf(EasyHandle::class)
        ->and($options['proxy'])->toBe('')->and($options['allow_redirects'])->toBeFalse()
        ->and($options['protocols'])->toBe(['http', 'https'])->and($options['decode_content'])->toBeFalse()
        ->and($options['curl'])->toBe([CURLOPT_RESOLVE => ['cdn.example.com:443:93.184.216.34']]);
});

test('CurlFactory real: una opcion de cURL con opcion propia en Guzzle sigue siendo rechazada (centinela de version)', function () {
    $options = (new UrlGuard(fn () => ['93.184.216.34']))->requestOptions((new UrlGuard(fn () => ['93.184.216.34']))->validate('http://cdn.example.com/a'));
    $options['curl'][CURLOPT_PROXY] = '';

    expect(fn () => (new CurlFactory(1))->create(new PsrRequest('GET', 'http://cdn.example.com/a'), $options))
        ->toThrow(InvalidArgumentException::class);
});

test('manejador real: una importacion completa funciona de punta a punta, con la IP fijada por CURLOPT_RESOLVE', function () {
    $asset = loopbackImport('/ok.jpg');

    // `cdn.example.com` no existe: solo llega a 127.0.0.1 si el CURLOPT_RESOLVE se aplica de verdad.
    expect($asset->status)->toBe(MediaAsset::STATUS_READY)->and($asset->size)->toBe(strlen($GLOBALS['loopback']['bytes']))
        ->and($asset->path)->toEndWith('.jpg')->not->toEndWith('.jpg.jpg');
});

test('manejador real: el proxy del entorno se ignora (proxy => "")', function () {
    $old = [getenv('HTTP_PROXY'), getenv('http_proxy'), getenv('HTTPS_PROXY'), getenv('ALL_PROXY')];
    putenv('HTTP_PROXY=http://127.0.0.1:9');
    putenv('http_proxy=http://127.0.0.1:9');
    putenv('ALL_PROXY=http://127.0.0.1:9');

    try {
        $asset = loopbackImport('/proxy-check.jpg');
    } finally {
        foreach (['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'ALL_PROXY'] as $i => $k) {
            $old[$i] === false ? putenv($k) : putenv("$k=$old[$i]");
        }
    }

    expect($asset->status)->toBe(MediaAsset::STATUS_READY);
});

test('manejador real: la redireccion la sigue UrlGuard::follow, no cURL', function () {
    $asset = loopbackImport('/redirect.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_READY)->and($asset->size)->toBe(strlen($GLOBALS['loopback']['bytes']));
});

test('manejador real: un 404 es PERMANENTE con su mensaje (gap 5): sin reintentos', function () {
    $asset = loopbackImport('/missing.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toBe('El servidor remoto respondió 404.');
});

test('manejador real: el tope de bytes corta la descarga', function () {
    config(['media.max_bytes.image' => 1000]);

    $asset = loopbackImport('/huge.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('tamaño máximo');
});

test('manejador real: el plazo de la pasada corta la descarga y conserva el parcial', function () {
    $source = new UrlSource(app(UrlGuard::class), 'http://cdn.example.com/slow.jpg');
    $sink = fopen('php://temp', 'w+b');
    $policy = fn (?string $mime, ?int $total) => 10_000_000;

    $started = microtime(true);
    $pass = $source->fetch($sink, 0, null, $policy, 10_000_000, microtime(true) + 1.0);
    $elapsed = microtime(true) - $started;

    expect($pass->complete)->toBeFalse()->and($pass->written)->toBeGreaterThan(0)->and($pass->ranges)->toBeTrue()
        ->and($elapsed)->toBeLessThan(5.0); // el servidor aguanta 6 s: cortar antes demuestra que el plazo se aplica durante la descarga
});

test('manejador real: reanuda con Range y completa en dos pasadas', function () {
    $bytes = $GLOBALS['loopback']['bytes'];
    $half = intdiv(strlen($bytes), 2);
    $source = new UrlSource(app(UrlGuard::class), 'http://cdn.example.com/ok.jpg');
    $policy = fn (?string $mime, ?int $total) => 10_000_000;
    $sink = fopen('php://temp', 'w+b');
    fwrite($sink, substr($bytes, 0, $half));

    $pass = $source->fetch($sink, $half, '"v1"', $policy, 10_000_000, microtime(true) + 30);
    rewind($sink);

    expect($pass->complete)->toBeTrue()->and($pass->written)->toBe(strlen($bytes) - $half)->and(stream_get_contents($sink))->toBe($bytes);
});

test('manejador real: si el total declarado cambia entre pasadas, se reinicia en vez de empalmar (gap 9)', function () {
    $bytes = $GLOBALS['loopback']['bytes'];
    $half = intdiv(strlen($bytes), 2);
    $source = new UrlSource(app(UrlGuard::class), 'http://cdn.example.com/ok.jpg');
    $policy = fn (?string $mime, ?int $total) => 10_000_000;
    $sink = fopen('php://temp', 'w+b');
    fwrite($sink, substr($bytes, 0, $half));

    $pass = $source->fetch($sink, $half, null, $policy, 10_000_000, microtime(true) + 30, strlen($bytes) + 123);

    expect($pass->restart)->toBeTrue()->and($pass->restartReason)->toBe('changed');
});

test('ImportFailedException llega intacta desde on_headers con el manejador real', function () {
    $source = new UrlSource(app(UrlGuard::class), 'http://cdn.example.com/missing.jpg');

    expect(fn () => $source->fetch(fopen('php://temp', 'w+b'), 0, null, fn () => 1000, 1000, microtime(true) + 10))
        ->toThrow(ImportFailedException::class, 'El servidor remoto respondió 404');
});

test('manejador real: el tope se aplica DURANTE el streaming de una respuesta chunked sin Content-Length (gap F5-2 #2)', function () {
    $source = new UrlSource(app(UrlGuard::class), 'http://cdn.example.com/chunked.jpg');
    $sink = fopen('php://temp', 'w+b');
    $started = microtime(true);

    expect(fn () => $source->fetch($sink, 0, null, fn () => 100_000, 100_000, microtime(true) + 30))
        ->toThrow(ImportFailedException::class, 'tamaño máximo');
    // Cortó pronto: la respuesta entera serian ~25 MB y ~0,8 s de servidor; en el parcial solo caben unos trozos.
    expect((int) fstat($sink)['size'])->toBeLessThan(2_000_000)->and(microtime(true) - $started)->toBeLessThan(5.0);

    config(['media.max_bytes.image' => 100_000]);
    $asset = loopbackImport('/chunked.jpg');

    expect($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('tamaño máximo');
});

test('manejador real: un remoto que gotea 1 B por pasada acaba «invalido» en pocas pasadas, no en un bucle caliente (gap F5-2 #1)', function () {
    $source = new UrlSource(app(UrlGuard::class), 'http://cdn.example.com/drip.jpg');
    $asset = app(MediaUploadService::class)->beginImport($this->user, MediaAsset::SOURCE_URL, $source->reference(), $source->name());
    $importer = app(RemoteMediaImporter::class);

    $passes = 0;
    do {
        $passes++;
        $done = $importer->run($asset->fresh(), $source);
    } while (! $done && $passes < 50);

    $asset = $asset->fresh();
    expect($done)->toBeTrue()->and($passes)->toBeLessThanOrEqual(3)
        ->and($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toBe('La descarga no avanza; inténtalo de nuevo más tarde.');
});

test('N1: un remoto sano que sirve tramos pequenos completa aunque la espera en cola sea de horas (sin tope por created_at)', function () {
    // Tramos de 700 B: el fichero necesita varias pasadas; cada una aporta poco, asi que el umbral de «pobre» se baja a escala.
    config(['media.import_min_pass_bytes' => 1, 'media.import_min_pass_seconds' => 0, 'media.import_expected_chunk_bytes' => 700]);
    $source = new UrlSource(app(UrlGuard::class), 'http://cdn.example.com/chunk.jpg');
    $asset = app(MediaUploadService::class)->beginImport($this->user, MediaAsset::SOURCE_URL, $source->reference(), $source->name());
    // Espera en cola simulada: la importacion «nacio» hace 5 horas (el tope anterior de 2 h la habria matado a la primera pasada).
    $asset->forceFill(['created_at' => now()->subHours(5)])->save();
    $importer = app(RemoteMediaImporter::class);

    $passes = 0;
    do {
        $passes++;
        $done = $importer->run($asset->fresh(), $source);
    } while (! $done && $passes < 60);

    $asset = $asset->fresh();
    expect($asset->status)->toBe(MediaAsset::STATUS_READY)->and($passes)->toBeGreaterThan(1)
        ->and($asset->size)->toBe(strlen($GLOBALS['loopback']['bytes']));
});

test('N1: el ritmo medio se mide sobre el tiempo de descarga efectivo, no sobre el reloj de pared', function () {
    $mk = fn (array $state) => tap(app(MediaUploadService::class)->beginImport($this->user, MediaAsset::SOURCE_URL, 'https://cdn.example.com/'.uniqid().'.jpg', 'a.jpg'), fn ($a) => $a->forceFill(['created_at' => now()->subDay(), 'import_state' => $state])->save());
    $source = new UrlSource(app(UrlGuard::class), 'http://cdn.example.com/chunk.jpg');
    $importer = app(RemoteMediaImporter::class);

    // 1000 B en 400 s de descarga efectiva = 2,5 B/s < 16 KB/s: se corta.
    $slow = $mk(['bytes' => 0, 'passes' => 5, 'download_seconds' => 400]);
    expect($importer->run($slow->fresh(), $source))->toBeTrue()->and($slow->fresh()->status)->toBe(MediaAsset::STATUS_INVALID)
        ->and($slow->fresh()->error)->toContain('demasiado lenta');

    // Mismo asset de un dia de antiguedad, pero con buen ritmo medio: sigue.
    $fine = $mk(['bytes' => 400 * 1_000_000, 'passes' => 5, 'download_seconds' => 400, 'total' => 5_000_000_000]);
    expect($importer->run($fine->fresh(), $source))->toBeFalse()->and($fine->fresh()->status)->toBe(MediaAsset::STATUS_PENDING);
});

test('G1: el tope de pasadas sale del ritmo minimo prometido (formula) y sigue cubriendo un CDN que corta cada 206 a 8 MB', function () {
    $importer = app(RemoteMediaImporter::class);
    $gib = 1024 * 1024 * 1024;
    // Por ritmo: ceil(ceil(total / (16 KiB/s x 70 s)) x 1,25) + 10. Por tramos de 8 MB: ceil(ceil(total / 8 MiB) x 1,25) + 10.
    $byRate = fn (int $t) => (int) ceil(ceil($t / (16 * 1024 * 70)) * 1.25) + 10;

    expect($importer->maxPasses(4 * $gib))->toBe($byRate(4 * $gib))->toBe(4692)->toBeGreaterThanOrEqual(650)
        ->and($importer->maxPasses($gib))->toBe(1182)
        ->and($importer->maxPasses(null))->toBe(200)->and($importer->maxPasses(1_000_000))->toBe(200);

    // Con un ritmo minimo muy alto manda el calculo por tramos de 8 MB.
    config(['media.import_min_rate_bytes' => 10 * 1024 * 1024]);
    expect($importer->maxPasses(4 * $gib))->toBe(650);
});

test('G1: una descarga sana por encima del ritmo minimo (1 GiB a 50 KiB/s, 4 GiB a 20 KiB/s) no se corta por pasadas', function () {
    $importer = app(RemoteMediaImporter::class);
    $gib = 1024 * 1024 * 1024;
    $perPass = fn (int $rate) => $rate * 70; // bytes que trae una pasada de 70 s al ritmo dado

    foreach ([[$gib, 50 * 1024], [4 * $gib, 20 * 1024]] as [$total, $rate]) {
        expect((int) ceil($total / $perPass($rate)))->toBeLessThan($importer->maxPasses($total));
    }

    // Estado real: a una pasada de completar 1 GiB a 50 KiB/s, con ritmo sano => sigue pendiente (antes se cortaba hacia las 170 pasadas).
    $asset = app(MediaUploadService::class)->beginImport($this->user, MediaAsset::SOURCE_URL, 'https://cdn.example.com/g1.jpg', 'g1.jpg');
    $passes = (int) ceil($gib / $perPass(50 * 1024)) - 1;
    $asset->forceFill(['import_state' => ['bytes' => $passes * $perPass(50 * 1024), 'total' => $gib, 'passes' => $passes, 'download_seconds' => $passes * 70.0, 'ranges' => true]])->save();
    $source = new UrlSource(app(UrlGuard::class), 'http://cdn.example.com/chunk.jpg');

    expect($passes)->toBeGreaterThan(170)
        ->and(app(RemoteMediaImporter::class)->run($asset->fresh(), $source))->toBeFalse()->and($asset->fresh()->status)->toBe(MediaAsset::STATUS_PENDING);
});

test('G2: una pasada que acaba en 503 suma su tiempo y su pasada; un 503 suelto no mata una descarga sana', function () {
    $source = new UrlSource(app(UrlGuard::class), 'http://cdn.example.com/flaky.jpg');
    $asset = app(MediaUploadService::class)->beginImport($this->user, MediaAsset::SOURCE_URL, $source->reference(), $source->name());
    $importer = app(RemoteMediaImporter::class);
    config(['media.import_min_pass_bytes' => 1, 'media.import_min_pass_seconds' => 0]);

    expect(fn () => $importer->run($asset->fresh(), $source))->toThrow(RuntimeException::class, '503');
    $state = $asset->fresh()->import_state;
    expect($state['passes'])->toBe(1)->and($state['failed_passes'])->toBe(1)->and($state['download_seconds'])->toBeGreaterThan(0)
        ->and($asset->fresh()->status)->toBe(MediaAsset::STATUS_PENDING);

    // El reintento (que el job hace tras su espera) sigue adelante y la descarga acaba bien.
    $done = false;
    for ($i = 0; $i < 30 && ! $done; $i++) {
        try {
            $done = $importer->run($asset->fresh(), $source);
        } catch (RuntimeException) {
        }
    }

    expect($done)->toBeTrue()->and($asset->fresh()->status)->toBe(MediaAsset::STATUS_READY);
});

test('G2: un remoto que intercala 503 lentos acaba cortado por ritmo, con el tiempo de los fallos contabilizado', function () {
    // Escala reducida: gracia 1 s y ritmo minimo 100 KB/s; cada 503 tarda ~0,4 s sin aportar bytes y cada 206 trae 700 B.
    config(['media.import_min_pass_bytes' => 1, 'media.import_min_pass_seconds' => 0, 'media.import_rate_grace_seconds' => 1, 'media.import_min_rate_bytes' => 100_000]);
    $source = new UrlSource(app(UrlGuard::class), 'http://cdn.example.com/flaky-slow.jpg');
    $asset = app(MediaUploadService::class)->beginImport($this->user, MediaAsset::SOURCE_URL, $source->reference(), $source->name());
    $importer = app(RemoteMediaImporter::class);

    $done = false;
    $attempts = 0;
    while (! $done && $attempts < 60) {
        $attempts++;
        try {
            $done = $importer->run($asset->fresh(), $source);
        } catch (RuntimeException) {
        }
    }

    $asset = $asset->fresh();
    expect($done)->toBeTrue()->and($attempts)->toBeLessThan(60)
        ->and($asset->status)->toBe(MediaAsset::STATUS_INVALID)->and($asset->error)->toContain('demasiado lenta')
        ->and($asset->import_state['failed_passes'])->toBeGreaterThanOrEqual(2)
        ->and($asset->import_state['download_seconds'])->toBeGreaterThanOrEqual(1.0);
});

test('N2: una pasada incompleta mas corta que el minimo espera el resto dentro del job (ritmo maximo de reencolado)', function () {
    config(['media.import_min_pass_bytes' => 1, 'media.import_min_pass_seconds' => 0.5]);
    $source = new UrlSource(app(UrlGuard::class), 'http://cdn.example.com/chunk.jpg');
    $asset = app(MediaUploadService::class)->beginImport($this->user, MediaAsset::SOURCE_URL, $source->reference(), $source->name());

    $t = microtime(true);
    $done = app(RemoteMediaImporter::class)->run($asset->fresh(), $source);

    expect($done)->toBeFalse()->and(microtime(true) - $t)->toBeGreaterThanOrEqual(0.45)->toBeLessThan(5.0);
});
