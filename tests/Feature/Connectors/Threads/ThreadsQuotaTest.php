<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Threads\ThreadsPublisher;
use App\Enums\ContentType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ThreadsFake;

const THQ_UID = ThreadsFake::USER_ID;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->account = ThreadsFake::account();
    $this->publisher = app(ThreadsPublisher::class);
});

function thqMedia(int $id): PublishMedia
{
    return new PublishMedia(id: $id, kind: 'image', mime: 'image/jpeg', url: 'https://signed.example/m'.$id.'.jpg', width: 1080, height: 1080);
}

function thqRequest(ContentType $type, ?string $text, array $media = [], ?string $reference = null, ?CarbonImmutable $since = null, array $progress = []): PublishRequest
{
    return new PublishRequest(targetId: 1, type: $type, text: $text, media: $media, reference: $reference, since: $since, progress: $progress);
}

function thqSent(string $method, string $suffix): array
{
    $out = [];
    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        if ($request->method() === $method && str_ends_with(parse_url($request->url(), PHP_URL_PATH), $suffix)) {
            $out[] = $request;
        }
    }

    return $out;
}

function thqLimit(int $used, int $total = 250): array
{
    return ['data' => [['quota_usage' => $used, 'config' => ['quota_total' => $total, 'quota_duration' => 86400]]]];
}

test('con el limite agotado se aplica QuotaPolicy sin crear contenedor ni publicar (CA-09)', function () {
    Http::fake([
        'graph.threads.com/v1.0/'.THQ_UID.'/threads_publishing_limit*' => Http::response(thqLimit(250)),
    ]);

    try {
        $this->publisher->publish($this->account, thqRequest(ContentType::ThreadsText, 'Hola'));
        $this->fail('debia lanzar la cuota');
    } catch (QuotaExceededException $e) {
        expect($e->errorCode)->toBe('cuota')->and($e->getMessage())->toContain('250')->and($e->retryAt)->not->toBeNull();
    }

    expect(thqSent('POST', '/threads'))->toBe([])->and(thqSent('POST', '/threads_publish'))->toBe([]);
});

test('la cuota se consulta con los campos quota_usage y config', function () {
    Http::fake([
        'graph.threads.com/v1.0/'.THQ_UID.'/threads_publishing_limit*' => Http::response(thqLimit(10)),
        'graph.threads.com/v1.0/'.THQ_UID.'/threads' => Http::response(['id' => 'CONT1']),
        'graph.threads.com/v1.0/CONT1*' => Http::response(['status' => 'IN_PROGRESS']),
    ]);

    $this->publisher->publish($this->account, thqRequest(ContentType::ThreadsText, 'Hola'));

    $query = thqSent('GET', '/threads_publishing_limit')[0]->url();
    expect($query)->toContain('fields=quota_usage%2Cconfig');
});

test('un carrusel nuevo consulta la cuota una vez; uno reanudado, no', function () {
    Http::fake([
        'graph.threads.com/v1.0/'.THQ_UID.'/threads_publishing_limit*' => Http::response(thqLimit(250)),
    ]);

    expect(fn () => $this->publisher->publish($this->account, thqRequest(ContentType::ThreadsCarousel, 'x', [thqMedia(1), thqMedia(2)])))
        ->toThrow(QuotaExceededException::class);
    expect(thqSent('POST', '/threads'))->toBe([]);

    // Con progreso ya guardado (hijos creados) no se vuelve a preguntar: la cuota se gasto al empezar.
    Http::fake([
        'graph.threads.com/v1.0/C1*' => Http::response(['status' => 'IN_PROGRESS']),
    ]);
    $r = $this->publisher->publish($this->account, thqRequest(ContentType::ThreadsCarousel, 'x', [thqMedia(1), thqMedia(2)], 'carousel', null, ['children' => ['C1', 'C2'], 'ready' => []]));
    expect($r->isProcessing())->toBeTrue();
});

test('si la cuota no se puede consultar por un fallo pasajero se intenta publicar igual', function () {
    Http::fake([
        'graph.threads.com/v1.0/'.THQ_UID.'/threads_publishing_limit*' => Http::response('boom', 503),
        'graph.threads.com/v1.0/'.THQ_UID.'/threads' => Http::response(['id' => 'CONT1']),
        'graph.threads.com/v1.0/CONT1*' => Http::response(['status' => 'IN_PROGRESS']),
    ]);

    $r = $this->publisher->publish($this->account, thqRequest(ContentType::ThreadsText, 'Hola'));

    expect($r->isProcessing())->toBeTrue();
});

test('si la consulta de cuota dice que la credencial no vale, se pide reconectar', function () {
    Http::fake([
        'graph.threads.com/v1.0/'.THQ_UID.'/threads_publishing_limit*' => Http::response(['error' => ['message' => 'bad token', 'code' => 190]], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->account, thqRequest(ContentType::ThreadsText, 'Hola')))->toThrow(ReconnectRequiredException::class);
});

test('si la API no da el total se usa el limite configurado de 250', function () {
    Http::fake([
        'graph.threads.com/v1.0/'.THQ_UID.'/threads_publishing_limit*' => Http::response(['data' => [['quota_usage' => 250]]]),
    ]);

    expect(fn () => $this->publisher->publish($this->account, thqRequest(ContentType::ThreadsText, 'Hola')))->toThrow(QuotaExceededException::class);
});

test('findPublished: un reintento tras un timeout encuentra la publicacion por su texto y no crea otra (CA-09)', function () {
    $since = CarbonImmutable::parse('2026-11-01 11:55:00');
    Http::fake([
        'graph.threads.com/v1.0/'.THQ_UID.'/threads?*' => Http::response(['data' => [
            ['id' => 'OTRO', 'text' => 'Otro texto', 'permalink' => 'https://www.threads.com/@a/post/otro', 'timestamp' => '2026-11-01T12:00:00+0000'],
            ['id' => 'VIEJO', 'text' => 'Hola Threads', 'permalink' => 'https://www.threads.com/@a/post/viejo', 'timestamp' => '2026-10-20T12:00:00+0000'],
            ['id' => 'MIO', 'text' => 'Hola Threads', 'permalink' => 'https://www.threads.com/@a/post/mio', 'timestamp' => '2026-11-01T12:00:05+0000'],
        ]]),
    ]);

    $found = $this->publisher->findPublished($this->account, thqRequest(ContentType::ThreadsText, 'Hola Threads', [], null, $since));

    expect($found?->isPublished())->toBeTrue()->and($found->externalId)->toBe('MIO')->and($found->url)->toBe('https://www.threads.com/@a/post/mio');
    expect(thqSent('POST', '/threads'))->toBe([])->and(thqSent('POST', '/threads_publish'))->toBe([]);

    $list = thqSent('GET', '/threads')[0]->url();
    expect($list)->toContain('fields=id%2Ctext%2Cpermalink%2Ctimestamp')->toContain('limit=25');
});

test('findPublished: sin constancia devuelve null; sin texto no se puede reconocer', function () {
    Http::fake([
        'graph.threads.com/v1.0/'.THQ_UID.'/threads?*' => Http::response(['data' => [
            ['id' => 'OTRO', 'text' => 'Otro', 'timestamp' => '2026-11-01T12:00:00+0000'],
        ]]),
    ]);

    expect($this->publisher->findPublished($this->account, thqRequest(ContentType::ThreadsText, 'Hola', [], null, CarbonImmutable::parse('2026-11-01 11:55:00'))))->toBeNull();
    expect($this->publisher->findPublished($this->account, thqRequest(ContentType::ThreadsImage, '', [thqMedia(1)], null, CarbonImmutable::parse('2026-11-01 11:55:00'))))->toBeNull();
});

test('findPublished: un contenedor FINISHED o IN_PROGRESS se devuelve como en proceso, sin crear otro', function (string $status) {
    Http::fake(['graph.threads.com/v1.0/CONT1*' => Http::response(['status' => $status])]);

    $r = $this->publisher->findPublished($this->account, thqRequest(ContentType::ThreadsVideo, 'x', [thqMedia(1)], 'CONT1'));

    expect($r->isProcessing())->toBeTrue()->and($r->reference)->toBe('CONT1')->and($r->raw)->toMatchArray(['not_published' => true]);
})->with(['FINISHED', 'IN_PROGRESS']);

test('findPublished: un contenedor PUBLISHED se resuelve a la publicacion reciente con ese texto', function () {
    Http::fake([
        'graph.threads.com/v1.0/CONT1*' => Http::response(['status' => 'PUBLISHED']),
        'graph.threads.com/v1.0/'.THQ_UID.'/threads?*' => Http::response(['data' => [
            ['id' => 'MIO', 'text' => 'Mi video', 'permalink' => 'https://www.threads.com/@a/post/mio', 'timestamp' => '2026-11-01T12:00:05+0000'],
        ]]),
    ]);

    $r = $this->publisher->findPublished($this->account, thqRequest(ContentType::ThreadsVideo, 'Mi video', [thqMedia(1)], 'CONT1', CarbonImmutable::parse('2026-11-01 11:55:00')));

    expect($r->isPublished())->toBeTrue()->and($r->externalId)->toBe('MIO');
});

test('un contenedor PUBLISHED que no se localiza se da por publicado, nunca por fallido', function () {
    Http::fake([
        'graph.threads.com/v1.0/CONT1*' => Http::response(['status' => 'PUBLISHED']),
        'graph.threads.com/v1.0/'.THQ_UID.'/threads?*' => Http::response(['data' => []]),
    ]);

    $r = $this->publisher->poll($this->account, thqRequest(ContentType::ThreadsVideo, 'Mi video', [thqMedia(1)], 'CONT1', CarbonImmutable::parse('2026-11-01 11:55:00')));

    expect($r->isPublished())->toBeTrue()->and($r->externalId)->toBe('CONT1')->and($r->note)->not->toBeNull();
});

test('findPublished sin presupuesto para otra consulta no publica a ciegas: es transitorio', function () {
    config(['publishing.step_budget_seconds' => 1, 'social.http_timeout' => 15]);
    Http::fake(['graph.threads.com/v1.0/CONT1*' => Http::response(['status' => 'PUBLISHED'])]);
    $request = thqRequest(ContentType::ThreadsVideo, 'Mi video', [thqMedia(1)], 'CONT1', CarbonImmutable::parse('2026-11-01 11:55:00'));

    // La primera consulta (estado del contenedor) agota el presupuesto: la busqueda de recientes se aplaza al siguiente sondeo.
    $r = $this->publisher->findPublished($this->account, $request);

    expect($r->isProcessing())->toBeTrue()->and($r->reference)->toBe('CONT1');
    expect(thqSent('GET', '/threads'))->toBe([]);

    $other = thqRequest(ContentType::ThreadsText, 'Hola', [], null, CarbonImmutable::parse('2026-11-01 11:55:00'));
    $other->budget()->spent();
    expect(fn () => $this->publisher->findPublished($this->account, $other))->toThrow(TransientPublishException::class);
});
