<?php

use App\Jobs\ImportRemoteMedia;
use App\Jobs\RunCanvaJob;
use App\Models\Canva\CanvaConnection;
use App\Models\MediaAsset;
use App\Services\Canva\CanvaConnections;
use App\Services\Canva\CanvaException;
use App\Services\Canva\CanvaJobs;
use App\Services\MediaUploadService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/CanvaTestSupport.php';

// T-16 · Revision de dos lentes, intento 1: errores transitorios del token, revocacion, trabajos que no se quedan colgados e idempotencia.

beforeEach(function () {
    canvaEnvironment();
    [$this->user, $this->brand] = canvaUserAndBrand();
});

function canvaFresh(): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
}

test('1: solo invalid_grant caduca la conexion; 5xx, 429 y 400 sin invalid_grant son transitorios o no la caducan', function (int $status, array $body, bool $expires, string $reason) {
    canvaFresh();
    Http::fake(['api.canva.com/rest/v1/oauth/token' => Http::response($body, $status)]);
    $connection = canvaConnect($this->user, $this->brand, expires: now()->subMinute());

    $error = null;
    try {
        app(CanvaConnections::class)->accessToken($connection);
    } catch (CanvaException $e) {
        $error = $e;
    }

    expect($error?->reason)->toBe($reason)->and($connection->fresh()->status)->toBe($expires ? 'expired' : 'active')->and($connection->fresh()->refresh_token)->toBe('rt-live');
})->with([
    'invalid_grant 400' => [400, ['error' => 'invalid_grant'], true, 'unauthorized'],
    'invalid_grant 401' => [401, ['error' => 'invalid_grant'], true, 'unauthorized'],
    '500' => [500, [], false, 'unavailable'],
    '503' => [503, [], false, 'unavailable'],
    '429' => [429, [], false, 'rate_limited'],
    '400 sin invalid_grant' => [400, ['error' => 'invalid_request'], false, 'token_error'],
]);

test('1: una red caida al refrescar es transitoria y deja la conexion activa', function () {
    canvaFresh();
    Http::fake(['api.canva.com/rest/v1/oauth/token' => fn () => throw new ConnectionException('timeout')]);
    $connection = canvaConnect($this->user, $this->brand, expires: now()->subMinute());

    expect(fn () => app(CanvaConnections::class)->accessToken($connection))->toThrow(CanvaException::class, 'No se pudo contactar');
    expect($connection->fresh()->status)->toBe('active');
});

test('1: un fallo transitorio del job se relanza para reintentar y no da el trabajo por perdido', function () {
    canvaFresh();
    Http::fake(['api.canva.com/rest/v1/exports' => Http::response([], 503)]);
    Queue::fake();
    $connection = canvaConnect($this->user, $this->brand);
    $job = app(CanvaJobs::class)->startExport($this->user, $this->brand, $connection, 'DAF123abcde', 'png');

    expect(fn () => app(CanvaJobs::class)->process($job->id))->toThrow(CanvaException::class);
    expect($job->fresh()->status)->not->toBe('failed');
    (new RunCanvaJob($job->id))->failed(new RuntimeException('agotado'));
    expect($job->fresh()->status)->toBe('failed')->and($job->fresh()->error)->toContain('Inténtalo de nuevo');
});

test('2: desconectar revoca el refresh token VIGENTE aunque cambiara tras cargar la conexion', function () {
    canvaFresh();
    Http::fake(['api.canva.com/rest/v1/oauth/revoke' => Http::response('', 200)]);
    $stale = canvaConnect($this->user, $this->brand);
    CanvaConnection::withoutUserScope()->whereKey($stale->id)->first()->forceFill(['refresh_token' => 'rt-rotated'])->save();

    app(CanvaConnections::class)->disconnect($this->user, $this->brand);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/revoke') && $r['token'] === 'rt-rotated');
    expect(CanvaConnection::withoutUserScope()->count())->toBe(0);
});

test('3: reconectar una marca revoca los tokens anteriores y una revocacion fallida no impide conectar', function () {
    canvaFresh();
    Http::fake([
        'api.canva.com/rest/v1/oauth/token' => Http::response(['access_token' => 'at-new', 'refresh_token' => 'rt-new', 'expires_in' => 14400, 'scope' => implode(' ', config('services.canva.scopes'))]),
        'api.canva.com/rest/v1/users/me/*' => Http::response(['profile' => ['display_name' => 'Ana'], 'capabilities' => []]),
        'api.canva.com/rest/v1/oauth/revoke' => Http::response('', 500),
    ]);
    canvaConnect($this->user, $this->brand);

    $connection = app(CanvaConnections::class)->complete($this->user, $this->brand, 'code', 'verifier', 'https://x.test/cb');

    expect($connection->access_token)->toBe('at-new')->and(CanvaConnection::withoutUserScope()->count())->toBe(1);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/revoke') && $r['token'] === 'rt-live');
});

test('4: desconectar mientras hay un trabajo lo marca fallido y un trabajo sin conexion no queda colgado', function () {
    canvaFresh();
    Http::fake(['api.canva.com/rest/v1/oauth/revoke' => Http::response('', 200)]);
    Queue::fake();
    $connection = canvaConnect($this->user, $this->brand);
    $job = app(CanvaJobs::class)->startExport($this->user, $this->brand, $connection, 'DAF123abcde', 'png');

    app(CanvaConnections::class)->disconnect($this->user, $this->brand);

    expect($job->fresh()->status)->toBe('failed')->and($job->fresh()->error_code)->toBe('not_connected');
    expect(app(CanvaJobs::class)->process($job->id))->toBeFalse();
});

test('4: failed() deja el trabajo fallido con un mensaje neutro y no toca uno ya terminado', function () {
    Queue::fake();
    $connection = canvaConnect($this->user, $this->brand);
    $active = app(CanvaJobs::class)->startExport($this->user, $this->brand, $connection, 'DAF123abcde', 'png');
    $done = app(CanvaJobs::class)->startExport($this->user, $this->brand, $connection, 'DAF123abcde', 'png');
    $done->forceFill(['status' => 'ready'])->save();

    (new RunCanvaJob($active->id))->failed(new LockTimeoutException('x'));
    (new RunCanvaJob($done->id))->failed(new RuntimeException('x'));

    expect($active->fresh()->status)->toBe('failed')->and($active->fresh()->error)->not->toContain('Lock')->and($done->fresh()->status)->toBe('ready');
});

test('5: reintentar la exportacion no duplica recursos: reutiliza los ya creados y no deja huerfanos', function () {
    canvaFresh();
    $files = ['https://export-download.canva.com/a/p1.png?sig=1', 'https://export-download.canva.com/a/p2.png?sig=2'];
    Http::fake([
        'api.canva.com/rest/v1/exports' => Http::response(['job' => ['id' => 'job-1', 'status' => 'in_progress']]),
        'api.canva.com/rest/v1/exports/job-1' => fn () => Http::response(['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => $files]]),
        'export-download.canva.com/*' => fn () => Http::response(canvaPng(), 200, ['Content-Type' => 'image/png']),
    ]);
    Queue::fake();
    $connection = canvaConnect($this->user, $this->brand);
    $job = app(CanvaJobs::class)->startExport($this->user, $this->brand, $connection, 'DAF123abcde', 'png', ['title' => 'Carrusel']);
    app(CanvaJobs::class)->process($job->id); // crea el job remoto

    // Un intento anterior creo el primer recurso y murio antes de acabar.
    $first = app(MediaUploadService::class)->beginImport($this->user, 'canva', 'https://export-download.canva.com/a/p1.png', 'Carrusel (1).png');
    $job->refresh()->forceFill(['output_media_ids' => [$first->id]])->save();

    app(CanvaJobs::class)->process($job->id);
    $job->refresh();

    expect(MediaAsset::withoutUserScope()->where('source', 'canva')->count())->toBe(2)->and($job->output_media_ids)->toHaveCount(2)->and($job->output_media_ids[0])->toBe($first->id);
    Queue::assertPushed(ImportRemoteMedia::class, 2);
});
