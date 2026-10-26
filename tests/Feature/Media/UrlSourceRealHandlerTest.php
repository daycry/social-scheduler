<?php

use App\Jobs\ImportRemoteMedia;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\ImportFailedException;
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
