<?php

use App\Services\Canva\CanvaJwks;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/CanvaTestSupport.php';

// T-26 · Verificacion de la firma del `correlation_jwt` con las claves publicas de Canva (`GET /connect/keys`, JWKS), RS256 fijo.
// Claves RSA generadas en el propio test y `Http::fake` del endpoint: ninguna peticion real.

beforeEach(function () {
    canvaEnvironment();
    Cache::flush();
    $this->pair = canvaKeyPair('kid-1');
});

function canvaB64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

test('un JWT RS256 con firma, audiencia y caducidad validas se acepta y devuelve sus reclamaciones', function () {
    canvaFakeKeys($this->pair);

    $claims = app(CanvaJwks::class)->verify(canvaSignedJwt($this->pair, 'estado'));

    expect($claims)->toMatchArray(['type' => 'rti', 'correlation_state' => 'estado', 'aud' => 'OC-test-client']);
});

test('se rechaza un JWT con firma de otra clave, caducado, audiencia ajena, sin audiencia ni caducidad, kid desconocido o algoritmo distinto', function (string $case) {
    canvaFakeKeys($this->pair);
    $jwt = match ($case) {
        'firma de otra clave' => canvaSignedJwt(canvaKeyPair('kid-1'), 's'), // mismo kid, otra clave
        'caducado' => canvaSignedJwt($this->pair, 's', ['exp' => now()->subMinute()->timestamp]),
        'audiencia ajena' => canvaSignedJwt($this->pair, 's', ['aud' => 'otra-app']),
        'sin audiencia' => JWT::encode(['type' => 'rti', 'exp' => now()->addHour()->timestamp], $this->pair['pem'], 'RS256', 'kid-1'),
        'sin caducidad' => JWT::encode(['type' => 'rti', 'aud' => 'OC-test-client'], $this->pair['pem'], 'RS256', 'kid-1'),
        'kid desconocido' => canvaSignedJwt(canvaKeyPair('kid-x'), 's'),
        'algoritmo none' => canvaB64('{"alg":"none","typ":"JWT","kid":"kid-1"}').'.'.canvaB64('{"type":"rti","aud":"OC-test-client","exp":'.(time() + 3600).'}').'.',
        'HS256' => JWT::encode(['type' => 'rti', 'aud' => 'OC-test-client', 'exp' => time() + 3600], str_repeat('k', 40), 'HS256', 'kid-1'),
        'basura' => 'esto.no.esunjwt',
    };

    expect(app(CanvaJwks::class)->verify($jwt))->toBeNull();
})->with(['firma de otra clave', 'caducado', 'audiencia ajena', 'sin audiencia', 'sin caducidad', 'kid desconocido', 'algoritmo none', 'HS256', 'basura']);

test('la audiencia puede venir como lista que incluya el client_id', function () {
    canvaFakeKeys($this->pair);

    expect(app(CanvaJwks::class)->verify(canvaSignedJwt($this->pair, 's', ['aud' => ['otra', 'OC-test-client']])))->not->toBeNull();
});

test('las claves se cachean: dos verificaciones piden el endpoint una sola vez y sin credenciales', function () {
    canvaFakeKeys($this->pair);
    $jwks = app(CanvaJwks::class);

    $jwks->verify(canvaSignedJwt($this->pair, 'a'));
    $jwks->verify(canvaSignedJwt($this->pair, 'b'));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.canva.com/rest/v1/connect/keys' && ! $r->hasHeader('Authorization'));
});

test('un kid desconocido renueva las claves (rotacion) y a partir de ahi valida; un kid inventado no provoca una peticion por intento', function () {
    $rotated = canvaKeyPair('kid-2');
    Http::fake(['api.canva.com/rest/v1/connect/keys' => Http::sequence()
        ->push(['keys' => [$this->pair['jwk']]])
        ->push(['keys' => [$this->pair['jwk'], $rotated['jwk']]])]);
    $jwks = app(CanvaJwks::class);

    expect($jwks->verify(canvaSignedJwt($this->pair, 'a')))->not->toBeNull()
        ->and($jwks->verify(canvaSignedJwt($rotated, 'b')))->not->toBeNull();
    Http::assertSentCount(2);

    $forged = canvaKeyPair('kid-falso');
    expect($jwks->verify(canvaSignedJwt($forged, 'c')))->toBeNull()
        ->and($jwks->verify(canvaSignedJwt($forged, 'd')))->toBeNull();
    Http::assertSentCount(2);
});

test('si Canva no responde o devuelve basura, el JWT se rechaza (nunca se acepta sin verificar)', function (callable $fake) {
    $fake();

    expect(app(CanvaJwks::class)->verify(canvaSignedJwt($this->pair, 's')))->toBeNull();
})->with([
    '500' => [fn () => Http::fake(['api.canva.com/rest/v1/connect/keys' => Http::response([], 500)])],
    'sin claves' => [fn () => Http::fake(['api.canva.com/rest/v1/connect/keys' => Http::response(['keys' => []])])],
    'red caida' => [fn () => Http::fake(['api.canva.com/rest/v1/connect/keys' => fn () => throw new ConnectionException('timeout')])],
]);

test('sin client_id configurado no se acepta ningun JWT ni se pide nada', function () {
    config(['services.canva.client_id' => null]);
    canvaFakeKeys($this->pair);

    expect(app(CanvaJwks::class)->verify(canvaSignedJwt($this->pair, 's')))->toBeNull();
    Http::assertNothingSent();
});

test('un reloj desfasado unos segundos no rechaza un retorno legítimo: margen de 60 s en iat y exp, sin tocar el margen global de la librería', function () {
    canvaFakeKeys($this->pair);
    $jwks = app(CanvaJwks::class);

    expect($jwks->verify(canvaSignedJwt($this->pair, 's', ['iat' => now()->addSeconds(30)->timestamp])))->not->toBeNull()
        ->and($jwks->verify(canvaSignedJwt($this->pair, 's', ['exp' => now()->subSeconds(30)->timestamp])))->not->toBeNull()
        ->and($jwks->verify(canvaSignedJwt($this->pair, 's', ['exp' => now()->subSeconds(120)->timestamp])))->toBeNull()
        ->and($jwks->verify(canvaSignedJwt($this->pair, 's', ['iat' => now()->addSeconds(300)->timestamp])))->toBeNull()
        ->and(JWT::$leeway)->toBe(0);
});

test('si Canva falla, el fallo se cachea un minuto: no se pide el JWKS en cada verificación', function () {
    Http::fake(['api.canva.com/rest/v1/connect/keys' => Http::response([], 500)]);
    $jwks = app(CanvaJwks::class);

    $jwks->verify(canvaSignedJwt($this->pair, 'a'));
    $jwks->verify(canvaSignedJwt($this->pair, 'b'));
    Http::assertSentCount(1);

    $this->travel(61)->seconds();
    $jwks->verify(canvaSignedJwt($this->pair, 'c'));
    Http::assertSentCount(2);
});

test('una clave mal formada se descarta y el resto del conjunto sigue sirviendo', function () {
    Http::fake(['api.canva.com/rest/v1/connect/keys' => Http::response(['keys' => [['kty' => 'RSA', 'kid' => 'rota'], $this->pair['jwk']]])]);

    expect(app(CanvaJwks::class)->verify(canvaSignedJwt($this->pair, 's')))->not->toBeNull();
});
