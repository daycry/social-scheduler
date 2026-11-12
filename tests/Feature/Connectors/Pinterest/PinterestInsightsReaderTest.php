<?php

use App\Analytics\Metric;
use App\Analytics\SnapshotCollector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\RangeInsightsReader;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Connectors\Pinterest\PinterestInsightsReader;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\NewNetworksFake;

// Contrato de la API v5 (OpenAPI oficial pinterest/api-description 5.28.0): `GET /v5/user_account/analytics` y `GET /v5/pins/{id}/analytics`
// devuelven `{ "ALL": { daily_metrics: [{date, data_status, metrics}], summary_metrics, lifetime_metrics? } }`.

beforeEach(function () {
    Http::preventStrayRequests();
    CarbonImmutable::setTestNow('2026-11-10 12:00:00');
    $this->account = NewNetworksFake::pinterestAccount(['scopes' => ['boards:read', 'pins:read', 'pins:write', 'user_accounts:read']]);
    $this->reader = app(PinterestInsightsReader::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function pinAccountBody(array $metrics = ['IMPRESSION' => 120, 'ENGAGEMENT' => 9, 'OUTBOUND_CLICK' => 4, 'PIN_CLICK' => 3, 'SAVE' => 2], string $status = 'READY', string $date = '2026-11-09'): array
{
    return ['ALL' => ['daily_metrics' => [['data_status' => $status, 'date' => $date, 'metrics' => $metrics]], 'summary_metrics' => $metrics]];
}

function pinSent(string $suffix): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), $suffix))->values()->all();
}

function pinQuery(Request $r): array
{
    parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

    return $q;
}

test('el lector esta registrado y es de rango', function () {
    expect(app(ConnectorRegistry::class)->insightsFor(Network::Pinterest))->toBeInstanceOf(PinterestInsightsReader::class)
        ->and($this->reader)->toBeInstanceOf(RangeInsightsReader::class);
});

test('la cuenta mapea impresiones, interacciones y clics y deja null lo que no da', function () {
    Http::fake([
        'api.pinterest.com/v5/user_account/analytics*' => Http::response(pinAccountBody()),
        'api.pinterest.com/v5/user_account' => Http::response(['id' => '1', 'username' => 'ana_pins', 'follower_count' => 321, 'pin_count' => 40]),
    ]);

    $out = $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-09'));
    $req = pinSent('/v5/user_account/analytics')[0];
    $q = pinQuery($req);

    expect($out[Metric::VIEWS])->toBe(120)->and($out[Metric::INTERACTIONS])->toBe(9)->and($out[Metric::CLICKS])->toBe(4)
        ->and($out[Metric::FOLLOWERS])->toBe(321)->and($out[Metric::POST_COUNT])->toBe(40)
        ->and($out[Metric::REACH])->toBeNull()->and($out[Metric::LIKES])->toBeNull()->and($out[Metric::WATCH_MINUTES])->toBeNull()
        ->and($q['start_date'])->toBe('2026-11-09')->and($q['end_date'])->toBe('2026-11-09')
        ->and($req->hasHeader('Authorization', 'Bearer PI-TOKEN'))->toBeTrue()
        ->and($req->url())->not->toContain('PI-TOKEN');
});

test('un dia que no es el ultimo cerrado no pide seguidores (la API solo da el valor de hoy)', function () {
    Http::fake(['api.pinterest.com/v5/user_account/analytics*' => Http::response(pinAccountBody(date: '2026-11-05'))]);

    $out = $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-05'));

    expect($out[Metric::VIEWS])->toBe(120)->and($out[Metric::FOLLOWERS])->toBeNull()
        ->and(pinSent('/v5/user_account'))->toBe([]);
});

test('un dia sin cerrar (PROCESSING) o ausente es null, nunca 0', function () {
    Http::fake(['api.pinterest.com/v5/user_account/analytics*' => Http::response(pinAccountBody(['IMPRESSION' => 0], 'PROCESSING', '2026-11-05'))]);
    $processing = $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-05'));

    Http::fake(['api.pinterest.com/v5/user_account/analytics*' => Http::response(['ALL' => ['daily_metrics' => [], 'summary_metrics' => []]])]);
    $empty = $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-05'));

    expect($processing[Metric::VIEWS])->toBeNull()->and($empty[Metric::VIEWS])->toBeNull()->and($empty[Metric::INTERACTIONS])->toBeNull();
});

test('un cero medido y READY se conserva como 0', function () {
    Http::fake(['api.pinterest.com/v5/user_account/analytics*' => Http::response(pinAccountBody(['IMPRESSION' => 0, 'ENGAGEMENT' => 0, 'OUTBOUND_CLICK' => 0], 'READY', '2026-11-05'))]);

    $out = $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-05'));

    expect($out[Metric::VIEWS])->toBe(0)->and($out[Metric::INTERACTIONS])->toBe(0);
});

test('el rango pide una sola consulta y devuelve una entrada por dia', function () {
    $body = ['ALL' => ['daily_metrics' => [
        ['data_status' => 'READY', 'date' => '2026-11-03', 'metrics' => ['IMPRESSION' => 10, 'ENGAGEMENT' => 1, 'OUTBOUND_CLICK' => 0]],
        ['data_status' => 'READY', 'date' => '2026-11-04', 'metrics' => ['IMPRESSION' => 20, 'ENGAGEMENT' => 2, 'OUTBOUND_CLICK' => 1]],
    ], 'summary_metrics' => []]];
    Http::fake(['api.pinterest.com/v5/user_account/analytics*' => Http::response($body)]);

    $out = $this->reader->accountInsightsRange($this->account, CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-05'));

    expect(pinSent('/v5/user_account/analytics'))->toHaveCount(1)
        ->and(array_keys($out))->toBe(['2026-11-03', '2026-11-04', '2026-11-05'])
        ->and($out['2026-11-03'][Metric::VIEWS])->toBe(10)->and($out['2026-11-04'][Metric::CLICKS])->toBe(1)
        ->and($out['2026-11-05'][Metric::VIEWS])->toBeNull();
});

test('la publicacion mapea impresiones, guardados, reacciones y comentarios', function () {
    Http::fake(['api.pinterest.com/v5/pins/777/analytics*' => Http::response(['ALL' => [
        'daily_metrics' => [],
        'lifetime_metrics' => ['TOTAL_COMMENTS' => 3, 'TOTAL_REACTIONS' => 7],
        'summary_metrics' => ['IMPRESSION' => 500, 'SAVE' => 20, 'PIN_CLICK' => 30, 'OUTBOUND_CLICK' => 10],
    ]])]);

    $out = $this->reader->postInsights($this->account, '777');
    $q = pinQuery(pinSent('/v5/pins/777/analytics')[0]);

    expect($out[Metric::VIEWS])->toBe(500)->and($out[Metric::SAVED])->toBe(20)->and($out[Metric::LIKES])->toBe(7)->and($out[Metric::COMMENTS])->toBe(3)
        ->and($out[Metric::INTERACTIONS])->toBe(70)->and($out[Metric::REACH])->toBeNull()->and($out[Metric::SHARES])->toBeNull()
        ->and($q['start_date'])->toBe('2026-08-13')->and($q['end_date'])->toBe('2026-11-09')
        ->and($q['metric_types'])->toContain('IMPRESSION')->toContain('TOTAL_REACTIONS');
});

test('una publicacion sin metricas devuelve null y no ceros', function () {
    Http::fake(['api.pinterest.com/v5/pins/778/analytics*' => Http::response(['ALL' => ['daily_metrics' => [], 'summary_metrics' => []]])]);

    $out = $this->reader->postInsights($this->account, '778');

    expect(array_filter($out, fn ($v) => $v !== null))->toBe([]);
});

test('canReadPost solo con id externo', function () {
    expect($this->reader->canReadPost(new PostTarget(['external_id' => '777'])))->toBeTrue()
        ->and($this->reader->canReadPost(new PostTarget(['external_id' => null])))->toBeFalse();
});

test('401 pide reconectar', function () {
    Http::fake(['api.pinterest.com/v5/user_account/analytics*' => Http::response(['code' => 2, 'message' => 'Authentication failed.'], 401)]);

    expect(fn () => $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-05')))->toThrow(ReconnectRequiredException::class);
});

test('403 es rechazo permanente (sin permiso o sandbox)', function () {
    Http::fake(['api.pinterest.com/v5/user_account/analytics*' => Http::response(['code' => 403, 'message' => 'Not authorized'], 403)]);

    expect(fn () => $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-05')))->toThrow(PermanentPublishException::class);
});

test('429 sube como cuota para que el job reintente', function () {
    Http::fake(['api.pinterest.com/v5/pins/9/analytics*' => Http::response(['code' => 8, 'message' => 'Too many'], 429)]);

    expect(fn () => $this->reader->postInsights($this->account, '9'))->toThrow(QuotaExceededException::class);
});

test('en el sandbox la analitica no existe: el rechazo deja el dia como no disponible en el colector', function () {
    config(['social.pinterest.api_host' => 'https://api-sandbox.pinterest.com']);
    Http::fake(['api-sandbox.pinterest.com/v5/user_account/analytics*' => Http::response(['code' => 403, 'message' => 'Not authorized'], 403)]);

    $snapshot = app(SnapshotCollector::class)->collectAccount($this->account, CarbonImmutable::parse('2026-11-05'));

    expect($snapshot->metrics[Metric::VIEWS] ?? null)->toBeNull();
});
