<?php

use App\Connectors\Mastodon\MastodonClient;
use App\Connectors\Mastodon\MastodonException;
use App\Services\Media\UrlGuard;
use App\Services\Media\ValidatedUrl;
use Illuminate\Support\Facades\Http;

// T-87 (G7): el cuerpo de la respuesta de una instancia tiene tope de tamano: una instancia hostil no puede agotar la memoria del worker.
// Manejador cURL REAL contra 127.0.0.1 (patron de BlueskySsrfTest): la lectura se corta al pasar el tope, no despues de descargarlo todo.

/** Guardia de test: valida como el real pero devuelve una peticion al servidor local, fijando `mastodon.example` a 127.0.0.1. Solo existe en este fichero. */
class MastodonLoopbackGuard extends UrlGuard
{
    public function __construct(public int $port) {}

    public function validate(string $url): ValidatedUrl
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        return new ValidatedUrl("http://mastodon.example:{$this->port}{$path}", 'https', 'mastodon.example', $this->port, '127.0.0.1', ['127.0.0.1']);
    }
}

beforeAll(function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mastodon-loopback-'.bin2hex(random_bytes(4));
    mkdir($dir);

    file_put_contents($dir.DIRECTORY_SEPARATOR.'router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/api/v2/instance') {
    // Sin Content-Length (troceado): solo el tope en la lectura lo detiene. Se escribe de a 64 KB hasta 8 MB.
    echo '{"padding":"';
    for ($i = 0; $i < 128; $i++) { echo str_repeat('a', 65536); flush(); }
    echo '"}';
    return;
}
echo json_encode(['ok' => true]);
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

    $GLOBALS['mastodon_loopback'] = ['dir' => $dir, 'port' => $port, 'proc' => $proc];
});

afterAll(function () {
    $l = $GLOBALS['mastodon_loopback'] ?? null;

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

test('manejador real: una respuesta mayor que el tope se corta y da un error claro', function () {
    Http::allowStrayRequests(); // este test habla con un servidor HTTP local (127.0.0.1) a proposito
    app()->instance(UrlGuard::class, new MastodonLoopbackGuard($GLOBALS['mastodon_loopback']['port']));
    config(['social.mastodon.max_response_bytes' => 100_000]);
    $before = memory_get_peak_usage(true);

    expect(fn () => app(MastodonClient::class)->instance('https://mastodon.example'))->toThrow(MastodonException::class, 'demasiado grande');

    // Se corto cerca del tope: no se descargaron los 8 MB en memoria.
    expect(memory_get_peak_usage(true) - $before)->toBeLessThan(4 * 1024 * 1024);
});

test('manejador real: una respuesta pequena sigue funcionando', function () {
    Http::allowStrayRequests();
    app()->instance(UrlGuard::class, new MastodonLoopbackGuard($GLOBALS['mastodon_loopback']['port']));

    expect(app(MastodonClient::class)->verifyCredentials('https://mastodon.example', 'TOKEN'))->toBe(['ok' => true]);
});
