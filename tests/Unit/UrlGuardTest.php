<?php

use App\Services\Media\UnsafeUrlException;
use App\Services\Media\UrlGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

// C-04a (T-33, CA-15): guardia SSRF. Nada sale a la red: el DNS se inyecta y las peticiones son `Http::fake`.

const PUBLIC_IP = '93.184.216.34';

/** Guardia con un DNS de mentira: `host => [ips]` (o un cierre para simular rebinding). */
function guard(array|Closure $dns = []): UrlGuard
{
    return new UrlGuard($dns instanceof Closure ? $dns : fn (string $host) => $dns[$host] ?? []);
}

beforeEach(fn () => Http::preventStrayRequests());

test('UrlGuard: una URL publica valida devuelve la IP a fijar', function () {
    $v = guard(['cdn.example.com' => [PUBLIC_IP]])->validate('https://cdn.example.com/a.jpg?x=1');

    expect($v->host)->toBe('cdn.example.com')->and($v->port)->toBe(443)->and($v->ip)->toBe(PUBLIC_IP);
    Http::assertNothingSent();
});

test('UrlGuard: IPs privadas, reservadas y de metadatos se rechazan sin ninguna peticion', function (string $url) {
    expect(fn () => guard()->validate($url))->toThrow(UnsafeUrlException::class);
    Http::assertNothingSent();
})->with([
    'loopback' => 'http://127.0.0.1/a.jpg',
    'loopback 127.1.2.3' => 'http://127.1.2.3/a.jpg',
    '10/8' => 'http://10.0.0.5/a.jpg',
    '172.16/12' => 'http://172.16.0.1/a.jpg',
    '192.168/16' => 'http://192.168.1.1/a.jpg',
    'metadata' => 'http://169.254.169.254/latest/meta-data/',
    'link-local' => 'http://169.254.1.1/a.jpg',
    'cero' => 'http://0.0.0.0/a.jpg',
    'CGNAT' => 'http://100.64.0.1/a.jpg',
    '198.18/15' => 'http://198.18.0.1/a.jpg',
    'multicast' => 'http://224.0.0.1/a.jpg',
    'reservado 240/4' => 'http://240.0.0.1/a.jpg',
    'broadcast' => 'http://255.255.255.255/a.jpg',
    'IPv6 loopback' => 'http://[::1]/a.jpg',
    'IPv6 sin especificar' => 'http://[::]/a.jpg',
    'IPv6 ULA' => 'http://[fc00::1]/a.jpg',
    'IPv6 ULA fd' => 'http://[fd12:3456::1]/a.jpg',
    'IPv6 link-local' => 'http://[fe80::1]/a.jpg',
    'IPv4-mapped loopback' => 'http://[::ffff:127.0.0.1]/a.jpg',
    'IPv4-mapped hex' => 'http://[::ffff:7f00:1]/a.jpg',
    'IPv4-mapped metadata' => 'http://[::ffff:169.254.169.254]/a.jpg',
    'NAT64 a loopback' => 'http://[64:ff9b::7f00:1]/a.jpg',
    '6to4 a privada' => 'http://[2002:0a00:0001::1]/a.jpg',
    'IPv4-compatible' => 'http://[::127.0.0.1]/a.jpg',
    'documentacion IPv6' => 'http://[2001:db8::1]/a.jpg',
    'localhost' => 'http://localhost/a.jpg',
    'localhost con punto' => 'http://localhost./a.jpg',
    'subdominio de localhost' => 'http://a.localhost/a.jpg',
]);

test('UrlGuard: los literales decimales, octales y hexadecimales se rechazan', function (string $url) {
    expect(fn () => guard()->validate($url))->toThrow(UnsafeUrlException::class);
    Http::assertNothingSent();
})->with([
    'decimal' => 'http://2130706433/a.jpg',
    'octal' => 'http://0177.0.0.1/a.jpg',
    'hex' => 'http://0x7f.0.0.1/a.jpg',
    'hex entero' => 'http://0x7f000001/a.jpg',
    'abreviado' => 'http://127.1/a.jpg',
    'octal largo' => 'http://017700000001/a.jpg',
]);

test('UrlGuard: esquemas, credenciales y puertos no permitidos', function (string $url) {
    expect(fn () => guard(['example.com' => [PUBLIC_IP]])->validate($url))->toThrow(UnsafeUrlException::class);
    Http::assertNothingSent();
})->with([
    'ftp' => 'ftp://example.com/a.jpg',
    'file' => 'file:///etc/passwd',
    'gopher' => 'gopher://example.com/',
    'data' => 'data:text/plain;base64,AAAA',
    'javascript' => 'javascript:alert(1)',
    'sin esquema' => '//example.com/a.jpg',
    'usuario' => 'http://user@example.com/a.jpg',
    'usuario y clave' => 'http://user:pass@example.com/a.jpg',
    'arroba para enganar' => 'http://example.com@127.0.0.1/a.jpg',
    'puerto 22' => 'http://example.com:22/a.jpg',
    'puerto 8080' => 'http://example.com:8080/a.jpg',
    'puerto 3306' => 'https://example.com:3306/a.jpg',
    'barra invertida' => 'http://example.com\\@127.0.0.1/a.jpg',
    'espacio y salto' => "http://example.com/a b\nHost: x",
    'vacia' => '',
]);

test('UrlGuard: los puertos 80 y 443 se admiten', function () {
    $g = guard(['example.com' => [PUBLIC_IP]]);

    expect($g->validate('http://example.com/a.jpg')->port)->toBe(80)
        ->and($g->validate('http://example.com:80/a.jpg')->port)->toBe(80)
        ->and($g->validate('https://example.com:443/a.jpg')->port)->toBe(443);
});

test('UrlGuard: TODAS las IPs deben ser publicas (una mezcla se rechaza)', function () {
    $g = guard(['mix.example.com' => [PUBLIC_IP, '10.0.0.1'], 'v6.example.com' => ['2606:2800:220:1:248:1893:25c8:1946', '::1']]);

    expect(fn () => $g->validate('http://mix.example.com/a.jpg'))->toThrow(UnsafeUrlException::class)
        ->and(fn () => $g->validate('http://v6.example.com/a.jpg'))->toThrow(UnsafeUrlException::class);
});

test('UrlGuard: un host que no resuelve se rechaza', function () {
    expect(fn () => guard()->validate('http://no-existe.example.com/a.jpg'))->toThrow(UnsafeUrlException::class);
});

test('UrlGuard: una IPv6 publica se admite y se fija entre corchetes', function () {
    $g = guard(['v6.example.com' => ['2606:2800:220:1:248:1893:25c8:1946']]);
    $v = $g->validate('https://v6.example.com/a.jpg');

    expect($g->curlOptions($v)[CURLOPT_RESOLVE])->toBe(['v6.example.com:443:[2606:2800:220:1:248:1893:25c8:1946]']);
});

test('UrlGuard: IDN se normaliza a punycode antes de resolver', function () {
    $seen = [];
    $g = guard(function (string $host) use (&$seen) {
        $seen[] = $host;

        return [PUBLIC_IP];
    });

    $v = $g->validate("https://b\u{fc}cher.example/a.jpg");

    expect($v->host)->toBe('xn--bcher-kva.example')->and($seen)->toBe(['xn--bcher-kva.example']);
});

test('UrlGuard: isPublicIp en los bordes de los rangos', function (string $ip, bool $public) {
    expect(UrlGuard::isPublicIp($ip))->toBe($public);
})->with([
    ['8.8.8.8', true], ['1.1.1.1', true], ['100.63.255.255', true], ['100.128.0.0', true], ['172.15.255.255', true], ['172.32.0.0', true],
    ['169.253.255.255', true], ['198.17.255.255', true], ['198.20.0.0', true], ['223.255.255.255', true],
    ['100.64.0.0', false], ['100.127.255.255', false], ['172.16.0.0', false], ['172.31.255.255', false], ['192.0.0.1', false],
    ['2606:2800:220:1:248:1893:25c8:1946', true], ['::ffff:8.8.8.8', true], ['64:ff9b::808:808', true], ['2002:0808:0808::1', true],
    ['::ffff:10.0.0.1', false], ['64:ff9b::a00:1', false], ['2002:7f00:1::', false], ['ff02::1', false], ['fec0::1', false],
    ['no-es-una-ip', false], ['', false],
]);

test('UrlGuard: fija la IP validada (CURLOPT_RESOLVE) y limita los protocolos y el proxy', function () {
    $g = guard(['example.com' => [PUBLIC_IP]]);
    $v = $g->validate('http://example.com/a.jpg');
    $opts = $g->requestOptions($v);

    // En la clave `curl` SOLO CURLOPT_RESOLVE: Guzzle 8 rechaza el resto (proxy, redirecciones, protocolos, progreso).
    expect($g->curlOptions($v))->toBe([CURLOPT_RESOLVE => ['example.com:80:'.PUBLIC_IP]])
        ->and($opts['curl'])->toBe($g->curlOptions($v))
        ->and($opts['proxy'])->toBe('')
        ->and($opts['allow_redirects'])->toBeFalse()
        ->and($opts['protocols'])->toBe(['http', 'https'])
        ->and($opts['decode_content'])->toBeFalse();
});

test('UrlGuard (revision): las IPs de plataforma de Azure se bloquean y «privada» y «no resuelve» dan el mismo mensaje (CWE-204)', function () {
    expect(UrlGuard::isPublicIp('168.63.129.16'))->toBeFalse()->and(UrlGuard::isPublicIp('168.63.129.17'))->toBeTrue();

    $g = guard(['interno.example.com' => ['10.0.0.5'], 'azure.example.com' => ['168.63.129.16']]);
    $messages = [];
    foreach (['http://interno.example.com/a', 'http://azure.example.com/a', 'http://no-existe.example.com/a', 'http://localhost/a'] as $url) {
        try {
            $g->validate($url);
        } catch (UnsafeUrlException $e) {
            $messages[] = $e->getMessage();
        }
    }

    expect($messages)->toHaveCount(4)->and(array_unique($messages))->toHaveCount(1);
});

test('UrlGuard: DNS rebinding — cada validacion vuelve a resolver y se rechaza la respuesta que pasa a ser privada', function () {
    $answers = [[PUBLIC_IP], ['127.0.0.1']];
    $g = guard(function () use (&$answers) {
        return array_shift($answers) ?? ['127.0.0.1'];
    });

    $first = $g->validate('http://rebind.example.com/a.jpg');
    expect($first->ip)->toBe(PUBLIC_IP);

    // La segunda resolucion (otra pasada del job) ya apunta a loopback: no se confia en la cache de la primera.
    expect(fn () => $g->validate('http://rebind.example.com/a.jpg'))->toThrow(UnsafeUrlException::class);
});

test('UrlGuard: la conexion se pide a la IP validada de la primera resolucion, no a otra posterior', function () {
    $calls = 0;
    $g = guard(function () use (&$calls) {
        return $calls++ === 0 ? [PUBLIC_IP] : ['10.0.0.9'];
    });
    $seen = [];

    Http::fake(['rebind.example.com/*' => Http::response('ok')]);
    $g->follow('http://rebind.example.com/a.jpg', function ($v, $opts) use (&$seen, $g) {
        $seen[] = $opts[CURLOPT_RESOLVE];

        return $g->client($v)->get($v->url);
    });

    expect($seen)->toBe([['rebind.example.com:80:'.PUBLIC_IP]])->and($calls)->toBe(1);
});

/** Respuesta de redireccion. */
function redirectTo(string $location, int $status = 302)
{
    return Http::response('', $status, ['Location' => $location]);
}

test('UrlGuard: sigue una redireccion a otro host publico revalidandolo', function () {
    $g = guard(['a.example.com' => [PUBLIC_IP], 'b.example.com' => ['93.184.216.35']]);
    Http::fake([
        'a.example.com/*' => redirectTo('https://b.example.com/final.jpg'),
        'b.example.com/*' => Http::response('FIN', 200),
    ]);
    $pinned = [];

    $response = $g->follow('http://a.example.com/x.jpg', function ($v, $opts) use (&$pinned, $g) {
        $pinned[] = $opts[CURLOPT_RESOLVE][0];

        return $g->client($v)->get($v->url);
    });

    expect($response->body())->toBe('FIN')->and($pinned)->toBe(['a.example.com:80:'.PUBLIC_IP, 'b.example.com:443:93.184.216.35']);
});

test('UrlGuard: una redireccion a una IP interna se rechaza SIN pedirla (CA-15)', function (string $location) {
    $g = guard(['a.example.com' => [PUBLIC_IP], 'sneaky.example.com' => ['169.254.169.254']]);
    Http::fake(['a.example.com/*' => redirectTo($location)]);

    expect(fn () => $g->follow('http://a.example.com/x.jpg', fn ($v, $o) => $g->client($v)->get($v->url)))
        ->toThrow(UnsafeUrlException::class);

    Http::assertSentCount(1); // solo la primera: la IP interna nunca se pide
})->with([
    'loopback' => 'http://127.0.0.1/secreto',
    'metadata' => 'http://169.254.169.254/latest/meta-data/',
    'IPv6 loopback' => 'http://[::1]/',
    'IPv4-mapped' => 'http://[::ffff:10.0.0.1]/',
    'localhost' => 'http://localhost/',
    'host que resuelve a metadata' => 'http://sneaky.example.com/',
    'esquema file' => 'file:///etc/passwd',
    'esquema ftp' => 'ftp://example.com/',
    'credenciales' => 'http://u:p@a.example.com/',
    'puerto no permitido' => 'http://a.example.com:6379/',
    'decimal' => 'http://2130706433/',
]);

test('UrlGuard: las redirecciones relativas se resuelven contra la URL actual y se revalidan', function () {
    $g = guard(['a.example.com' => [PUBLIC_IP]]);
    Http::fake([
        'a.example.com/x.jpg' => redirectTo('/otra/ruta.jpg', 301),
        'a.example.com/otra/ruta.jpg' => redirectTo('final.jpg', 307),
        'a.example.com/otra/final.jpg' => Http::response('OK', 200),
    ]);

    $r = $g->follow('http://a.example.com/x.jpg', fn ($v, $o) => $g->client($v)->get($v->url));

    expect($r->body())->toBe('OK');
});

test('UrlGuard: mas de 5 redirecciones se rechazan', function () {
    $g = guard(['a.example.com' => [PUBLIC_IP]]);
    Http::fake(['a.example.com/*' => redirectTo('http://a.example.com/otra')]);

    expect(fn () => $g->follow('http://a.example.com/x', fn ($v, $o) => $g->client($v)->get($v->url)))
        ->toThrow(UnsafeUrlException::class, 'redirige demasiadas veces');

    Http::assertSentCount(6); // la original + 5 saltos; el sexto no se sigue
});

test('UrlGuard: una respuesta no redirigida se devuelve tal cual', function () {
    $g = guard(['a.example.com' => [PUBLIC_IP]]);
    Http::fake(['a.example.com/*' => Http::response('x', 404)]);

    $r = $g->follow('http://a.example.com/x', fn ($v, $o) => $g->client($v)->get($v->url));

    expect($r)->toBeInstanceOf(Response::class)->and($r->status())->toBe(404);
});

// ---- T-41: revision de seguridad SSRF ----

test('UrlGuard (revision): la peticion se hace a la URL reconstruida con lo validado, sin fragmento ni userinfo oculto', function () {
    $g = guard(['example.com' => [PUBLIC_IP]]);

    expect($g->validate('HTTP://Example.COM/a/b.jpg?x=1#frag')->url)->toBe('http://example.com/a/b.jpg?x=1')
        ->and($g->validate('https://example.com')->url)->toBe('https://example.com/')
        ->and($g->validate('https://example.com:443/a')->url)->toBe('https://example.com:443/a');
});

test('UrlGuard (revision): un fragmento que imita un userinfo no cambia el host validado ni viaja en la peticion', function () {
    $v = guard(['example.com' => [PUBLIC_IP]])->validate('http://example.com#@127.0.0.1/a.jpg');

    expect($v->host)->toBe('example.com')->and($v->url)->toBe('http://example.com/')->and($v->ip)->toBe(PUBLIC_IP);
});

test('UrlGuard (revision): hosts con formas ambiguas se rechazan', function (string $url) {
    expect(fn () => guard(['example.com' => [PUBLIC_IP]])->validate($url))->toThrow(UnsafeUrlException::class);
    Http::assertNothingSent();
})->with([
    'host codificado en porcentaje' => 'http://%31%32%37.0.0.1/a.jpg',
    'zona IPv6' => 'http://[fe80::1%25eth0]/a.jpg',
    'guion bajo' => 'http://my_host.example.com/a.jpg',
    'IPv4 con punto final' => 'http://127.0.0.1./a.jpg',
    'IPv4 octal con cero inicial' => 'http://010.0.0.1/a.jpg',
    'doble arroba' => 'http://a@b@example.com/a.jpg',
    'tabulador' => "http://exam\tple.com/a.jpg",
    'nulo' => "http://example.com/a\0.jpg",
    'IPv6 abreviado de loopback' => 'http://[0:0:0:0:0:0:0:1]/a.jpg',
    'IPv6 mapeado en decimal' => 'http://[::ffff:0:127.0.0.1]/a.jpg',
    'cabecera inyectada' => "http://example.com/a.jpg\r\nHost: 127.0.0.1",
]);

test('UrlGuard (revision): una redireccion con salto de esquema o IP numerica no pasa', function (string $location) {
    $g = guard(['a.example.com' => [PUBLIC_IP]]);
    Http::fake(['a.example.com/*' => redirectTo($location)]);

    expect(fn () => $g->follow('http://a.example.com/x', fn ($v, $o) => $g->client($v)->get($v->url)))->toThrow(UnsafeUrlException::class);
    Http::assertSentCount(1);
})->with([
    'javascript' => 'javascript:alert(1)',
    'gopher' => 'gopher://a.example.com/_x',
    'IP hexadecimal' => 'http://0x7f000001/',
    'IPv6 con zona' => 'http://[fe80::1%25eth0]/',
]);

test('UrlGuard (revision): ningun candidato de la tabla de IPs privadas llega a hacer una peticion', function () {
    $internal = ['127.0.0.1', '127.255.255.254', '10.1.2.3', '172.16.5.5', '192.168.0.10', '169.254.169.254', '100.64.1.1', '0.0.0.0', '::1', '::', 'fe80::abcd', 'fd00::1', '::ffff:192.168.1.1'];
    $dns = [];
    foreach ($internal as $i => $ip) {
        $dns["h{$i}.example.com"] = [$ip];
    }
    $g = guard($dns);

    foreach ($internal as $i => $ip) {
        expect(fn () => $g->follow("http://h{$i}.example.com/a.jpg", fn ($v, $o) => $g->client($v)->get($v->url)))->toThrow(UnsafeUrlException::class);
    }

    Http::assertNothingSent();
});
