<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

afterEach(fn () => Request::setTrustedHosts([]));

function cspLogSpy(): MockInterface
{
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('csp')->andReturn($logger);

    return $logger;
}

test('las cabeceras nuevas requieren producción y HTTPS', function () {
    config(['app.url' => 'https://metricool.test', 'security.hsts_max_age' => 86400]);
    $this->get('https://metricool.test/')
        ->assertHeaderMissing('Strict-Transport-Security')
        ->assertHeaderMissing('Permissions-Policy')
        ->assertHeaderMissing('Content-Security-Policy-Report-Only');

    $this->app['env'] = 'production';
    $this->get('http://metricool.test/', ['X-Forwarded-Proto' => 'https'])
        ->assertHeaderMissing('Strict-Transport-Security')
        ->assertHeaderMissing('Permissions-Policy')
        ->assertHeaderMissing('Content-Security-Policy-Report-Only');
});

test('la política de observación usa un nonce nuevo que comparten Vite y Ziggy', function () {
    $this->app['env'] = 'production';
    config(['app.url' => 'https://metricool.test', 'security.hsts_max_age' => 86400]);
    $this->withVite();
    $hotFile = tmpfile();
    fwrite($hotFile, 'https://assets.example.test');
    fflush($hotFile);
    Vite::useHotFile(stream_get_meta_data($hotFile)['uri']);

    try {
        $response = $this->get('https://metricool.test/')->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=86400')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->assertHeader('Reporting-Endpoints', 'metricool-csp="https://metricool.test/security/csp-report"')
            ->assertHeaderMissing('Content-Security-Policy');
        $policy = $response->headers->get('Content-Security-Policy-Report-Only', '');
        expect($policy)->toContain("object-src 'none'", 'report-uri /security/csp-report', 'report-to metricool-csp');
        expect(preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $matches))->toBe(1);
        $nonce = $matches[1];
        expect(Vite::cspNonce())->toBeNull();
        $html = $response->getContent();
        expect($html)->toMatch('/<script[^>]* nonce="'.preg_quote($nonce, '/').'"[^>]*>[^<]*Ziggy/s');
        expect($html)->toMatch('/<script[^>]*(?:src="[^"]+\/resources\/js\/app\.js")[^>]* nonce="'.preg_quote($nonce, '/').'"/');

        $next = $this->get('https://metricool.test/')->assertOk();
        expect($next->headers->get('Content-Security-Policy-Report-Only'))->not->toContain("'nonce-{$nonce}'");
    } finally {
        fclose($hotFile);
    }
});

test('se conservan la CSP aplicada y los permisos propios de otras respuestas', function () {
    $this->app['env'] = 'production';
    config(['app.url' => 'https://metricool.test', 'security.hsts_max_age' => null]);
    Route::get('/site-policy-fixture', fn () => response('<html></html>')
        ->header('Content-Security-Policy', "default-src 'none'; sandbox")
        ->header('Permissions-Policy', 'fullscreen=()'));

    $this->get('https://metricool.test/site-policy-fixture')->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertHeader('Permissions-Policy', 'fullscreen=()')
        ->assertHeaderMissing('Strict-Transport-Security')
        ->assertHeaderMissing('Content-Security-Policy-Report-Only');
});

test('el receptor procesa informes modernos y antiguos sin registrar URL completas ni muestras', function () {
    $this->app['env'] = 'production';
    config(['app.url' => 'https://metricool.test']);
    $log = cspLogSpy();
    $blocked = 'https://cdn.example.test/private/credential?token=test-secret';
    $legacy = ['csp-report' => [
        'effective-directive' => 'script-src-elem', 'blocked-uri' => $blocked,
        'document-uri' => 'https://metricool.test/private?token=test-secret', 'script-sample' => 'private sample',
    ]];
    $modern = [
        ['type' => 'deprecation', 'body' => ['effectiveDirective' => 'style-src', 'blockedURL' => 'eval']],
        ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'style-src-attr', 'blockedURL' => 'inline']],
    ];
    $endpoint = 'https://metricool.test/security/csp-report';
    $this->call('POST', $endpoint, server: ['CONTENT_TYPE' => 'application/csp-report'], content: json_encode($legacy))->assertNoContent();
    $this->call('POST', $endpoint, server: ['CONTENT_TYPE' => 'application/reports+json'], content: json_encode($modern))->assertNoContent();

    $log->shouldHaveReceived('notice')->twice();
    $log->shouldHaveReceived('notice')->with('security.csp_violation', ['directive' => 'script-src-elem', 'blocked' => 'https://cdn.example.test'])->once();
    $log->shouldHaveReceived('notice')->with('security.csp_violation', ['directive' => 'style-src-attr', 'blocked' => 'inline'])->once();
});

test('el receptor rechaza formatos y tamaños inválidos y no se activa en HTTP', function () {
    $this->app['env'] = 'production';
    config(['app.url' => 'https://metricool.test']);
    $log = cspLogSpy();
    $endpoint = 'https://metricool.test/security/csp-report';
    $this->call('POST', $endpoint, server: ['CONTENT_TYPE' => 'text/plain'], content: '{}')->assertUnsupportedMediaType();
    $this->call('POST', $endpoint, server: ['CONTENT_TYPE' => 'application/csp-report'], content: '[')->assertBadRequest();
    $this->call('POST', $endpoint, server: ['CONTENT_TYPE' => 'application/reports+json'], content: str_repeat(' ', 17_000))->assertStatus(413);
    $this->call('POST', str_replace('https:', 'http:', $endpoint), server: ['CONTENT_TYPE' => 'application/csp-report'], content: '{}')->assertNotFound();

    $log->shouldNotHaveReceived('notice');
});

test('HTTPS reenviado solo activa las cabeceras desde un proxy de confianza', function () {
    $this->app['env'] = 'production';
    config([
        'app.url' => 'https://metricool.test',
        'app.trusted_proxies' => '10.9.8.7',
        'security.hsts_max_age' => 86400,
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->get('http://metricool.test/', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=86400');
});

test('el receptor limita los informes por IP y el número de registros de un lote', function () {
    $this->app['env'] = 'production';
    config(['app.url' => 'https://metricool.test']);
    $log = cspLogSpy();
    $endpoint = 'https://metricool.test/security/csp-report';
    $report = ['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'style-src', 'blockedURL' => 'inline']];
    $body = json_encode(array_fill(0, 12, $report));
    $server = ['CONTENT_TYPE' => 'application/reports+json'];
    $this->call('POST', $endpoint, server: $server, content: $body)->assertNoContent();
    $log->shouldHaveReceived('notice')->times(10);

    foreach (range(1, 29) as $attempt) {
        $this->call('POST', $endpoint, server: $server, content: '[]')->assertNoContent();
    }
    $this->call('POST', $endpoint, server: $server, content: $body)->assertTooManyRequests();
    $log->shouldHaveReceived('notice')->times(10);
});

test('cero emite la revocación de HSTS por HTTPS', function () {
    $this->app['env'] = 'production';
    config(['app.url' => 'https://metricool.test', 'security.hsts_max_age' => 0]);

    $this->get('https://metricool.test/')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=0');
});

test('el registro de CSP tiene un canal propio aunque el general filtre los avisos', function () {
    expect(config('logging.channels.csp.level'))->toBe('notice')
        ->and(config('logging.channels.csp.driver'))->toBe('daily');
});

test('la recepción CSP respeta el presupuesto global además de cada IP', function () {
    $this->app['env'] = 'production';
    config(['app.url' => 'https://metricool.test', 'security.csp_report_global_per_minute' => 2]);
    $endpoint = 'https://metricool.test/security/csp-report';
    $report = json_encode(['csp-report' => [
        'effective-directive' => 'img-src',
        'blocked-uri' => 'https://assets.example.test',
    ]]);
    foreach (['192.0.2.1', '192.0.2.2'] as $address) {
        $this->call('POST', $endpoint, server: ['REMOTE_ADDR' => $address, 'CONTENT_TYPE' => 'application/csp-report'], content: $report)
            ->assertNoContent();
    }
    $this->call('POST', $endpoint, server: ['REMOTE_ADDR' => '192.0.2.3', 'CONTENT_TYPE' => 'application/csp-report'], content: $report)
        ->assertTooManyRequests();
});

test('HSTS descarta booleanos, valores negativos y edades mayores de dos años', function () {
    $this->app['env'] = 'production';
    config(['app.url' => 'https://metricool.test']);
    foreach ([true, -1, 63072001] as $invalid) {
        config(['security.hsts_max_age' => $invalid]);
        $this->get('https://metricool.test/')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }
});

test('la observación reconoce HTML sin distinguir mayúsculas y se puede desactivar', function () {
    $this->app['env'] = 'production';
    config(['app.url' => 'https://metricool.test']);
    Route::get('/html-content-type-fixture', fn () => response('<html></html>', 200, ['Content-Type' => 'Text/HTML']));
    $this->get('https://metricool.test/html-content-type-fixture')->assertOk()
        ->assertHeader('Content-Security-Policy-Report-Only');

    config(['security.csp_report_only' => false]);
    $this->get('https://metricool.test/html-content-type-fixture')->assertOk()
        ->assertHeaderMissing('Content-Security-Policy-Report-Only');
    $this->call('POST', 'https://metricool.test/security/csp-report', server: ['CONTENT_TYPE' => 'application/csp-report'], content: '{}')
        ->assertNotFound();
});
