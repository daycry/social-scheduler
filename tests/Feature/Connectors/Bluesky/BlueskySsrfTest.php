<?php

use App\Connectors\Bluesky\BlueskyClient;
use App\Connectors\Bluesky\BlueskyException;
use App\Connectors\Bluesky\BlueskySession;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Services\Media\UrlGuard;
use App\Services\Media\ValidatedUrl;
use Illuminate\Support\Facades\Http;
use Tests\Support\BlueskyFake;

// C-1 (CWE-918): ninguna peticion de Bluesky llega a un host interno. Los probes del revisor se rechazan SIN hacer la peticion.

/**
 * Guardia de test: valida como el real (ni DNS ni IPs publicas) pero devuelve una peticion al servidor local de PHP, fijando el
 * host de mentira `pds.example.com` a 127.0.0.1 con el `CURLOPT_RESOLVE` de verdad. SOLO existe en este fichero de tests.
 */
class BskyLoopbackGuard extends UrlGuard
{
    public function __construct(public int $port) {}

    public function validate(string $url): ValidatedUrl
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);

        return new ValidatedUrl("http://pds.example.com:{$this->port}{$path}".($query ? "?$query" : ''), 'http', 'pds.example.com', $this->port, '127.0.0.1', ['127.0.0.1']);
    }
}

beforeEach(function () {
    BlueskyFake::dns();
    $this->client = app(BlueskyClient::class);
});

dataset('probes de identidad', [
    'metadata por %2F' => 'did:web:169.254.169.254%2Flatest%2Fmeta-data%3F',
    'puerto por %3A' => 'did:web:localhost%3A6379',
    'puerto sin escapar' => 'did:web:example.com:6379',
    'ruta con dos puntos' => 'did:web:example.com:a:b',
    'IP literal' => 'did:web:127.0.0.1',
    'nip.io a loopback' => 'did:web:127.0.0.1.nip.io',
    'dominio a red privada' => 'did:web:interno.example.com',
    'metadata de Azure' => 'did:web:azure.example.com',
    'localhost' => 'did:web:localhost',
]);

test('un did:web hostil como handle se rechaza sin hacer ninguna peticion', function (string $did) {
    BlueskyFake::dns(['127.0.0.1.nip.io' => ['127.0.0.1'], 'interno.example.com' => ['10.0.0.5'], 'azure.example.com' => ['168.63.129.16']]);
    Http::fake();

    expect(fn () => app(BlueskySession::class)->open($did, 'app-pass'))->toThrow(BlueskyException::class);
    Http::assertNothingSent();
})->with('probes de identidad');

test('un PDS del documento DID que no es publico, https y 443 se rechaza sin pedirlo', function (string $endpoint) {
    BlueskyFake::dns(['interno.example.com' => ['10.0.0.5'], 'rebind.example.com' => ['93.184.216.34', '127.0.0.1']]);
    Http::fake([
        'bsky.social/*' => Http::response(['did' => BlueskyFake::DID]),
        'plc.directory/*' => Http::response(['id' => BlueskyFake::DID, 'service' => [['id' => '#atproto_pds', 'serviceEndpoint' => $endpoint]]]),
    ]);

    expect(fn () => app(BlueskySession::class)->open('ana.example.com', 'app-pass'))->toThrow(BlueskyException::class);
    Http::assertNotSent(fn ($r) => ! in_array(parse_url($r->url(), PHP_URL_HOST), ['bsky.social', 'plc.directory'], true));
})->with([
    'http' => 'http://pds.example.com',
    'puerto 8443' => 'https://pds.example.com:8443',
    'puerto 6379' => 'https://pds.example.com:6379',
    'IP literal' => 'https://127.0.0.1',
    'metadata' => 'https://169.254.169.254',
    'DNS a red privada' => 'https://interno.example.com',
    'DNS mixto (rebinding)' => 'https://rebind.example.com',
    'con ruta' => 'https://pds.example.com/xrpc',
    'con credenciales' => 'https://user:pass@pds.example.com',
]);

test('el PDS guardado se revalida en CADA uso: si su DNS pasa a una IP privada, no se pide (rebinding)', function () {
    $account = BlueskyFake::account();
    BlueskyFake::dns(['pds.example.com' => ['169.254.169.254']]);
    Http::fake();

    $session = app(BlueskySession::class);

    expect(fn () => $session->call($account, fn (string $token, string $pds) => $this->client->createRecord($pds, $token, BlueskyFake::DID, 'app.bsky.feed.post', [], 'rk')))
        ->toThrow(BlueskyException::class)
        ->and(fn () => $this->client->uploadBlob('https://pds.example.com', 't', 'x', 'image/png'))->toThrow(BlueskyException::class)
        ->and(fn () => $this->client->getRecord('https://pds.example.com', BlueskyFake::DID, 'app.bsky.feed.post', 'rk'))->toThrow(BlueskyException::class)
        ->and(fn () => $this->client->serviceAuth('https://pds.example.com', 't', 'did:web:video.bsky.app', 'x'))->toThrow(BlueskyException::class)
        ->and(fn () => $this->client->refreshSession('https://pds.example.com', 'r'))->toThrow(BlueskyException::class)
        ->and(fn () => $this->client->createSession('https://pds.example.com', BlueskyFake::DID, 'p'))->toThrow(BlueskyException::class)
        ->and(fn () => $session->refresh($account))->toThrow(BlueskyException::class);
    Http::assertNothingSent();
});

test('un PDS guardado con puerto o http tampoco se pide', function (string $pds) {
    $account = BlueskyFake::account(['meta' => ['did' => BlueskyFake::DID, 'pds' => $pds, 'handle' => 'ana.example.com']]);
    Http::fake();

    expect(fn () => app(BlueskySession::class)->call($account, fn (string $token, string $pds) => $this->client->uploadBlob($pds, $token, 'x', 'image/png')))
        ->toThrow(BlueskyException::class);
    Http::assertNothingSent();
})->with(['http://pds.example.com', 'https://pds.example.com:6379']);

test('una redireccion (302 a la metadata) no se sigue: error sin tocar el destino', function () {
    Http::fake([
        'pds.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    expect(fn () => $this->client->createSession('https://pds.example.com', BlueskyFake::DID, 'p'))
        ->toThrow(BlueskyException::class, 'redireccion');
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254'));
});

test('los hosts fijos (AppView, video, entryway) han de estar en la lista de permitidos', function (string $key, string $method) {
    config(["social.bluesky.$key" => 'https://evil.example.com']);
    Http::fake();

    expect(fn () => $this->client->{$method}())->toThrow(BlueskyException::class, 'no esta permitido');
    Http::assertNothingSent();
})->with([['appview', 'appview'], ['video_service', 'videoService']]);

test('un entryway fuera de la lista no resuelve handles', function () {
    config(['social.bluesky.entryway' => 'https://evil.example.com']);
    Http::fake();

    expect(fn () => $this->client->resolveHandle('ana.example.com'))->toThrow(BlueskyException::class);
    Http::assertNothingSent();
});

test('el mensaje de error no refleja un cuerpo que no es JSON ni mete saltos de linea del servidor', function () {
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.getRecord*' => Http::response('root:x:0:0 SECRETO-INTERNO', 500),
        'pds.example.com/xrpc/com.atproto.server.refreshSession' => Http::response(['error' => 'Bad Code!', 'message' => "linea1\nlinea2\r\n".str_repeat('x', 500)], 400),
    ]);

    try {
        $this->client->getRecord('https://pds.example.com', BlueskyFake::DID, 'app.bsky.feed.post', 'rk');
    } catch (BlueskyException $e) {
        expect($e->getMessage())->not->toContain('SECRETO-INTERNO')->not->toContain('root:');
    }

    try {
        $this->client->refreshSession('https://pds.example.com', 'r');
    } catch (BlueskyException $e) {
        expect($e->getMessage())->not->toContain("\n")->and(mb_strlen($e->getMessage()))->toBeLessThanOrEqual(200)->and($e->errorCode)->toBe('UpstreamFailure');
    }
});

test('C-10: el DID de la sesion tiene que ser el resuelto (un PDS no puede suplantar otra cuenta)', function () {
    BlueskyFake::connect(['session' => ['accessJwt' => BlueskyFake::jwt(), 'refreshJwt' => 'R', 'did' => 'did:plc:zzzzzzzzzzzzzzzzzzzzzzzz', 'handle' => 'victima.example.com']]);

    expect(fn () => app(BlueskySession::class)->open('ana.example.com', 'app-pass'))->toThrow(BlueskyException::class, 'no corresponde');
});

test('C-10: al renovar, una sesion de otro DID pide reconectar', function () {
    $account = BlueskyFake::account();
    Http::fake([
        'pds.example.com/xrpc/com.atproto.server.refreshSession' => Http::response(['error' => 'ExpiredToken', 'message' => 'x'], 400),
        'pds.example.com/xrpc/com.atproto.server.createSession' => Http::response(['accessJwt' => BlueskyFake::jwt(), 'refreshJwt' => 'R', 'did' => 'did:plc:zzzzzzzzzzzzzzzzzzzzzzzz']),
    ]);

    expect(fn () => app(BlueskySession::class)->refresh($account))->toThrow(ReconnectRequiredException::class);
});

test('un documento DID con otro id se rechaza', function () {
    BlueskyFake::dns();
    Http::fake([
        'bsky.social/*' => Http::response(['did' => BlueskyFake::DID]),
        'plc.directory/*' => Http::response(['id' => 'did:plc:zzzzzzzzzzzzzzzzzzzzzzzz', 'service' => [['id' => '#atproto_pds', 'serviceEndpoint' => BlueskyFake::PDS]]]),
    ]);

    expect(fn () => app(BlueskySession::class)->open('ana.example.com', 'app-pass'))->toThrow(BlueskyException::class);
});

// ------------------------------------------------------------------ manejador cURL REAL contra 127.0.0.1 (patron de UrlSourceRealHandlerTest)

beforeAll(function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bsky-loopback-'.bin2hex(random_bytes(4));
    mkdir($dir);

    file_put_contents($dir.DIRECTORY_SEPARATOR.'router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header_remove('X-Powered-By');
file_put_contents(__DIR__.'/hits.log', $path."\n", FILE_APPEND);

if ($path === '/xrpc/com.atproto.repo.getRecord') {
    http_response_code(302); header('Location: http://pds.example.com:'.$_SERVER['SERVER_PORT'].'/escapado'); echo 'REDIRECCION'; return;
}
if ($path === '/escapado') { http_response_code(200); echo '{"escapado":true}'; return; }
header('Content-Type: application/json');
echo json_encode(['accessJwt' => 'a.b.c', 'refreshJwt' => 'r', 'did' => 'did:plc:abcdefghijklmnopqrstuvwx', 'handle' => 'ana.example.com']);
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

    $GLOBALS['bsky_loopback'] = ['dir' => $dir, 'port' => $port, 'proc' => $proc];
});

afterAll(function () {
    $l = $GLOBALS['bsky_loopback'] ?? null;

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

function bskyLoopbackHits(): array
{
    $log = $GLOBALS['bsky_loopback']['dir'].DIRECTORY_SEPARATOR.'hits.log';

    return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
}

test('manejador real: la peticion llega por la IP fijada (CURLOPT_RESOLVE) y un 302 no se sigue', function () {
    $log = $GLOBALS['bsky_loopback']['dir'].DIRECTORY_SEPARATOR.'hits.log';
    @unlink($log);
    app()->instance(UrlGuard::class, new BskyLoopbackGuard($GLOBALS['bsky_loopback']['port']));

    // `pds.example.com` no existe: solo llega a 127.0.0.1 si el CURLOPT_RESOLVE se aplica de verdad.
    $session = $this->client->createSession('https://pds.example.com', BlueskyFake::DID, 'pw');
    expect($session['did'])->toBe(BlueskyFake::DID);

    expect(fn () => $this->client->getRecord('https://pds.example.com', BlueskyFake::DID, 'app.bsky.feed.post', 'rk'))
        ->toThrow(BlueskyException::class, 'redireccion');

    // Se hizo la peticion inicial, pero NO la del `Location`.
    expect(bskyLoopbackHits())->toBe(['/xrpc/com.atproto.server.createSession', '/xrpc/com.atproto.repo.getRecord']);
});
