<?php

use App\Analytics\Metric;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\RangeInsightsReader;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Connectors\Threads\ThreadsInsights;
use App\Models\PostTarget;
use App\Services\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ThreadsFake;

const THI_UID = ThreadsFake::USER_ID;

beforeEach(function () {
    Http::preventStrayRequests();
    CarbonImmutable::setTestNow('2026-11-10 12:00:00');
    $this->account = ThreadsFake::account();
    $this->reader = app(ThreadsInsights::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function thiUserResponse(array $over = []): array
{
    $series = fn (string $name, int $value, string $end) => ['name' => $name, 'period' => 'day', 'values' => [['value' => $value, 'end_time' => $end]]];
    $total = fn (string $name, int $value) => ['name' => $name, 'period' => 'day', 'total_value' => ['value' => $value]];

    return ['data' => array_values($over + [
        'views' => $series('views', 40, '2026-11-02T08:00:00+0000'),
        'likes' => $total('likes', 12),
        'replies' => $total('replies', 3),
        'reposts' => $total('reposts', 2),
        'quotes' => $total('quotes', 1),
        'clicks' => ['name' => 'clicks', 'period' => 'day', 'link_total_values' => [['value' => 5, 'link_url' => 'https://a.test'], ['value' => 6, 'link_url' => 'https://b.test']]],
    ])];
}

function thiSent(string $suffix): array
{
    $out = [];
    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        if ($request->method() === 'GET' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), $suffix)) {
            $out[] = $request;
        }
    }

    return $out;
}

function thiQuery(Request $r): array
{
    parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

    return $q;
}

test('es un lector de insights de rango registrado para Threads', function () {
    expect($this->reader)->toBeInstanceOf(RangeInsightsReader::class);
    app()->forgetInstance(ConnectorRegistry::class);

    expect(app(ConnectorRegistry::class)->insightsFor(Network::Threads))->toBeInstanceOf(ThreadsInsights::class);
});

test('cuenta de un dia cerrado: views son visitas al perfil, no visualizaciones; el resto se mapea segun el diseno', function () {
    Http::fake(['graph.threads.com/v1.0/'.THI_UID.'/threads_insights*' => Http::response(thiUserResponse())]);

    $m = $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-01'));

    expect($m)->toHaveKeys(Metric::ACCOUNT)
        ->and($m[Metric::PROFILE_VIEWS])->toBe(40)
        ->and($m[Metric::VIEWS])->toBeNull() // Threads no da visualizaciones de cuenta
        ->and($m[Metric::LIKES])->toBe(12)->and($m[Metric::COMMENTS])->toBe(3)
        ->and($m[Metric::SHARES])->toBe(3) // reposts 2 + quotes 1
        ->and($m[Metric::CLICKS])->toBe(11) // suma de los enlaces
        ->and($m[Metric::INTERACTIONS])->toBe(18) // likes + comentarios + compartidos
        ->and($m[Metric::REACH])->toBeNull()->and($m[Metric::FOLLOWERS])->toBeNull();
});

test('la consulta pide el dia en la zona de la red con since y until en Unix', function () {
    Http::fake(['graph.threads.com/v1.0/'.THI_UID.'/threads_insights*' => Http::response(thiUserResponse())]);

    $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-01'));

    $q = thiQuery(thiSent('/threads_insights')[0]);
    $start = CarbonImmutable::parse('2026-11-01 00:00', 'America/Los_Angeles');
    expect($q['since'])->toBe((string) $start->getTimestamp())->and($q['until'])->toBe((string) $start->addDay()->getTimestamp())
        ->and($q['metric'])->toBe('views,likes,replies,reposts,quotes,clicks');
});

test('una metrica ausente es null (no disponible), un 0 devuelto es 0', function () {
    $response = thiUserResponse();
    $response['data'] = array_values(array_filter($response['data'], fn ($i) => $i['name'] !== 'replies' && $i['name'] !== 'clicks'));
    $response['data'][] = ['name' => 'quotes', 'period' => 'day', 'total_value' => ['value' => 0]];
    Http::fake(['graph.threads.com/v1.0/'.THI_UID.'/threads_insights*' => Http::response($response)]);

    $m = $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-01'));

    expect($m[Metric::COMMENTS])->toBeNull()->and($m[Metric::CLICKS])->toBeNull()
        ->and($m[Metric::SHARES])->toBe(2) // quotes 0 devuelto (la ultima gana) + reposts 2
        ->and($m[Metric::INTERACTIONS])->toBe(14);
});

test('de la serie diaria se elige el valor cuyo end_time cierra ese dia', function () {
    $response = thiUserResponse(['views' => ['name' => 'views', 'period' => 'day', 'values' => [
        ['value' => 9, 'end_time' => '2026-11-01T07:00:00+0000'],
        ['value' => 77, 'end_time' => '2026-11-02T07:00:00+0000'],
    ]]]);
    Http::fake(['graph.threads.com/v1.0/'.THI_UID.'/threads_insights*' => Http::response($response)]);

    // El 1 de noviembre en el Pacifico (PDT, UTC-7) termina el 2 a las 07:00 UTC.
    expect($this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-01'))[Metric::PROFILE_VIEWS])->toBe(77);
});

test('el ultimo dia cerrado incluye seguidores (followers_count, sin rango); un dia pasado, no', function () {
    Http::fake([
        'graph.threads.com/v1.0/'.THI_UID.'/threads_insights*' => function (Request $r) {
            return Http::response(str_contains($r->url(), 'followers_count')
                ? ['data' => [['name' => 'followers_count', 'total_value' => ['value' => 321]]]]
                : thiUserResponse());
        },
    ]);

    $recent = $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-09'));
    expect($recent[Metric::FOLLOWERS])->toBe(321);
    $followers = array_values(array_filter(thiSent('/threads_insights'), fn ($r) => str_contains($r->url(), 'followers_count')))[0];
    expect(thiQuery($followers))->not->toHaveKey('since')->not->toHaveKey('until');

    Http::fake(['graph.threads.com/v1.0/'.THI_UID.'/threads_insights*' => Http::response(thiUserResponse())]);
    $old = $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-01'));
    expect($old[Metric::FOLLOWERS])->toBeNull();
});

test('antes del 13-04-2024 la API no da datos: todo null y ninguna llamada', function () {
    Http::fake();

    $m = $this->reader->accountInsights($this->account, CarbonImmutable::parse('2024-04-12'));

    expect(Metric::isEmpty($m))->toBeTrue();
    Http::assertNothingSent();
});

test('si Threads rechaza el conjunto se piden las metricas una a una y las rechazadas quedan null', function () {
    Http::fake([
        'graph.threads.com/v1.0/'.THI_UID.'/threads_insights*' => function (Request $r) {
            $q = thiQuery($r);
            if (str_contains($q['metric'], ',')) {
                return Http::response(['error' => ['message' => 'Unsupported metric', 'code' => 100]], 400);
            }

            return Http::response($q['metric'] === 'clicks'
                ? ['error' => ['message' => 'no permitido', 'code' => 100]]
                : ['data' => [['name' => $q['metric'], 'period' => 'day', 'total_value' => ['value' => 4]]]], $q['metric'] === 'clicks' ? 400 : 200);
        },
    ]);

    $m = $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-01'));

    expect($m[Metric::LIKES])->toBe(4)->and($m[Metric::CLICKS])->toBeNull();
});

test('credenciales invalidas y fallos pasajeros se propagan, no se guardan como no disponible', function (int $code, string $exception) {
    Http::fake(['graph.threads.com/v1.0/'.THI_UID.'/threads_insights*' => Http::response(['error' => ['message' => 'x', 'code' => $code]], 400)]);

    expect(fn () => $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-01')))->toThrow($exception);
})->with([
    'token invalido' => [190, ReconnectRequiredException::class],
    'limite de uso' => [4, TransientPublishException::class],
]);

test('rango: una entrada por dia con el mapa completo, dia a dia', function () {
    Http::fake(['graph.threads.com/v1.0/'.THI_UID.'/threads_insights*' => Http::response(thiUserResponse())]);

    expect($this->reader->maxRangeDays($this->account))->toBe(0);
    $range = $this->reader->accountInsightsRange($this->account, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-03'));

    expect(array_keys($range))->toBe(['2026-11-01', '2026-11-02', '2026-11-03'])
        ->and($range['2026-11-02'])->toHaveKeys(Metric::ACCOUNT)->and($range['2026-11-02'][Metric::LIKES])->toBe(12);
    expect(thiSent('/threads_insights'))->toHaveCount(3);
});

test('post: views, likes, respuestas y compartidos (reposts + citas + shares) se mapean; INTERACTIONS es la suma', function () {
    Http::fake([
        'graph.threads.com/v1.0/POST1/insights*' => function (Request $r) {
            $values = ['views' => 100, 'likes' => 10, 'replies' => 4, 'reposts' => 3, 'quotes' => 2, 'shares' => 1];
            $names = explode(',', thiQuery($r)['metric']);

            return Http::response(['data' => array_map(fn ($n) => ['name' => $n, 'period' => 'lifetime', 'values' => [['value' => $values[$n]]]], $names)]);
        },
    ]);

    $m = $this->reader->postInsights($this->account, 'POST1');

    expect($m)->toHaveKeys(Metric::POST)
        ->and($m[Metric::VIEWS])->toBe(100)->and($m[Metric::LIKES])->toBe(10)->and($m[Metric::COMMENTS])->toBe(4)
        ->and($m[Metric::SHARES])->toBe(6)->and($m[Metric::INTERACTIONS])->toBe(20)
        ->and($m[Metric::REACH])->toBeNull()->and($m[Metric::SAVED])->toBeNull();
});

test('post: views y shares figuran en desarrollo; si la API no los da quedan null, nunca 0', function () {
    Http::fake([
        'graph.threads.com/v1.0/POST1/insights*' => function (Request $r) {
            $names = explode(',', thiQuery($r)['metric']);
            if (array_intersect($names, ['views', 'shares'])) {
                return Http::response(['error' => ['message' => 'metric in development', 'code' => 100]], 400);
            }
            $values = ['likes' => 10, 'replies' => 4, 'reposts' => 3, 'quotes' => 2];

            return Http::response(['data' => array_map(fn ($n) => ['name' => $n, 'period' => 'lifetime', 'values' => [['value' => $values[$n]]]], $names)]);
        },
    ]);

    $m = $this->reader->postInsights($this->account, 'POST1');

    expect($m[Metric::VIEWS])->toBeNull()->and($m[Metric::LIKES])->toBe(10)->and($m[Metric::SHARES])->toBe(5)->and($m[Metric::INTERACTIONS])->toBe(19);
});

test('post de otra persona (REPOST_FACADE) devuelve datos vacios: todo null', function () {
    Http::fake(['graph.threads.com/v1.0/POST1/insights*' => Http::response(['data' => []])]);

    expect(Metric::isEmpty($this->reader->postInsights($this->account, 'POST1')))->toBeTrue();
});

test('canReadPost: solo con ID de publicacion y no con el ID de un contenedor', function () {
    $ok = new PostTarget(['external_id' => '1789']);
    $none = new PostTarget(['external_id' => null]);
    $container = new PostTarget(['external_id' => 'CONT1', 'progress' => ['external_id_kind' => 'container']]);

    expect($this->reader->canReadPost($ok))->toBeTrue()->and($this->reader->canReadPost($none))->toBeFalse()->and($this->reader->canReadPost($container))->toBeFalse();
});

test('Threads declara sus metricas, flujos esperados, zona del dia e historico', function () {
    expect(AnalyticsService::SUPPORTED['threads'])->toContain(Metric::FOLLOWERS, Metric::PROFILE_VIEWS, Metric::LIKES, Metric::COMMENTS, Metric::SHARES, Metric::CLICKS)
        ->and(AnalyticsService::SUPPORTED['threads'])->not->toContain(Metric::VIEWS)
        ->and(Metric::EXPECTED_FLOWS)->toHaveKey('threads')
        ->and(Metric::EXPECTED_FLOWS['threads'])->toContain(Metric::PROFILE_VIEWS)->not->toContain(Metric::CLICKS)
        ->and(config('social.analytics.timezones'))->toHaveKey('threads')
        ->and(AnalyticsService::LABELS)->toHaveKey(Metric::CLICKS);
});

test('se recuerda que metrica rechazo Threads: la siguiente lectura la omite y pide el resto en una sola peticion', function () {
    Http::fake([
        'graph.threads.com/v1.0/'.THI_UID.'/threads_insights*' => function (Request $r) {
            $q = thiQuery($r);
            if (str_contains($q['metric'], 'clicks')) {
                return Http::response(['error' => ['message' => 'no permitido', 'code' => 100]], 400);
            }
            $data = array_map(fn ($n) => ['name' => $n, 'period' => 'day', 'total_value' => ['value' => 4]], explode(',', $q['metric']));

            return Http::response(['data' => $data]);
        },
    ]);

    $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-01'));
    $first = count(thiSent('/threads_insights'));
    $m = $this->reader->accountInsights($this->account, CarbonImmutable::parse('2026-11-02'));
    $second = count(thiSent('/threads_insights')) - $first;

    expect($first)->toBeGreaterThan(2)->and($second)->toBe(1)->and($m[Metric::LIKES])->toBe(4)->and($m[Metric::CLICKS])->toBeNull();
});
