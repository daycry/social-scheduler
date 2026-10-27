<?php

use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Threads\ThreadsPublisher;
use App\Enums\ContentType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ThreadsFake;

const TH_UID = ThreadsFake::USER_ID;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->account = ThreadsFake::account();
    $this->publisher = app(ThreadsPublisher::class);
    thCheckpoints(reset: true);
    // Cuota con holgura para todos los tests de este fichero (la cuota se prueba en ThreadsQuotaTest).
    Http::fake(['graph.threads.com/v1.0/'.TH_UID.'/threads_publishing_limit*' => Http::response(['data' => [['quota_usage' => 1, 'config' => ['quota_total' => 250, 'quota_duration' => 86400]]]])]);
});

/** Registro de los `checkpoint` que recibe la peticion (referencia y progreso guardados en el acto). */
function thCheckpoints(?array $add = null, bool $reset = false): array
{
    static $saved = [];
    if ($reset) {
        $saved = [];
    }
    if ($add !== null) {
        $saved[] = $add;
    }

    return $saved;
}

function thMedia(int $id, string $kind = 'image'): PublishMedia
{
    return new PublishMedia(id: $id, kind: $kind, mime: $kind === 'video' ? 'video/mp4' : 'image/jpeg', url: 'https://signed.example/m'.$id.($kind === 'video' ? '.mp4' : '.jpg'), width: 1080, height: 1080, duration: $kind === 'video' ? 20.0 : null, fps: $kind === 'video' ? 30.0 : null);
}

function thRequest(ContentType $type, ?string $text, array $media = [], ?string $reference = null, array $progress = []): PublishRequest
{
    return new PublishRequest(
        targetId: 1, type: $type, text: $text, media: $media, reference: $reference, progress: $progress,
        checkpoint: function (?string $ref, ?array $prog) {
            thCheckpoints(['reference' => $ref, 'progress' => $prog]);
        },
    );
}

/** Peticiones enviadas cuyo path acaba en $suffix y cuyo metodo es $method. */
function thSent(string $method, string $suffix): array
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

function thFakeText(string $status = 'FINISHED', array $extra = []): void
{
    Http::fake($extra + [
        'graph.threads.com/v1.0/'.TH_UID.'/threads_publish' => Http::response(['id' => 'POST1']),
        'graph.threads.com/v1.0/'.TH_UID.'/threads' => Http::response(['id' => 'CONT1']),
        'graph.threads.com/v1.0/CONT1*' => Http::response(['id' => 'CONT1', 'status' => $status]),
        'graph.threads.com/v1.0/POST1*' => Http::response(['id' => 'POST1', 'permalink' => 'https://www.threads.com/@ana.threads/post/abc']),
    ]);
}

test('implementa el contrato de publicar y de sondear estado', function () {
    expect($this->publisher)->toBeInstanceOf(Publisher::class)->toBeInstanceOf(StatusPoller::class);
});

test('texto: crea el contenedor TEXT, comprueba el estado y publica con threads_publish', function () {
    thFakeText();

    $result = $this->publisher->publish($this->account, thRequest(ContentType::ThreadsText, 'Hola Threads'));

    expect($result->isPublished())->toBeTrue()->and($result->externalId)->toBe('POST1')
        ->and($result->url)->toBe('https://www.threads.com/@ana.threads/post/abc');
    $create = thSent('POST', '/threads')[0];
    expect($create->data())->toMatchArray(['media_type' => 'TEXT', 'text' => 'Hola Threads', 'access_token' => 'TH-LONG-TOKEN']);
    expect(thSent('POST', '/threads_publish')[0]->data())->toMatchArray(['creation_id' => 'CONT1', 'access_token' => 'TH-LONG-TOKEN']);
    expect(thCheckpoints()[0]['reference'])->toBe('CONT1');
});

test('el token no viaja en la URL de los POST', function () {
    thFakeText();

    $this->publisher->publish($this->account, thRequest(ContentType::ThreadsText, 'Hola'));

    foreach (thSent('POST', '/threads') as $r) {
        expect($r->url())->not->toContain('TH-LONG-TOKEN');
    }
});

test('imagen: el contenedor IMAGE lleva image_url y el texto opcional', function () {
    thFakeText();

    $this->publisher->publish($this->account, thRequest(ContentType::ThreadsImage, 'Pie', [thMedia(7)]));

    expect(thSent('POST', '/threads')[0]->data())->toMatchArray(['media_type' => 'IMAGE', 'image_url' => 'https://signed.example/m7.jpg', 'text' => 'Pie']);
});

test('video: el contenedor VIDEO lleva video_url y, sin texto, no manda text', function () {
    thFakeText('IN_PROGRESS');

    $result = $this->publisher->publish($this->account, thRequest(ContentType::ThreadsVideo, null, [thMedia(8, 'video')]));

    $data = thSent('POST', '/threads')[0]->data();
    expect($data)->toMatchArray(['media_type' => 'VIDEO', 'video_url' => 'https://signed.example/m8.mp4'])->not->toHaveKey('text');
    // Un video se procesa: queda en proceso con el contenedor como referencia, sin publicar.
    expect($result->isProcessing())->toBeTrue()->and($result->reference)->toBe('CONT1');
    expect(thSent('POST', '/threads_publish'))->toBe([]);
});

test('poll: con FINISHED publica el contenedor', function () {
    thFakeText('FINISHED');

    $done = $this->publisher->poll($this->account, thRequest(ContentType::ThreadsVideo, 'x', [thMedia(8, 'video')], 'CONT1'));

    expect($done->isPublished())->toBeTrue()->and($done->externalId)->toBe('POST1');
    expect(thSent('POST', '/threads_publish'))->toHaveCount(1);
});

test('poll: con IN_PROGRESS sigue en proceso con la misma referencia y sin publicar', function () {
    thFakeText('IN_PROGRESS');

    $again = $this->publisher->poll($this->account, thRequest(ContentType::ThreadsVideo, 'x', [thMedia(8, 'video')], 'CONT1'));

    expect($again->isProcessing())->toBeTrue()->and($again->reference)->toBe('CONT1');
    expect(thSent('POST', '/threads_publish'))->toBe([]);
});

test('poll: un contenedor PUBLISHED se da por publicado (sin publicar otra vez)', function () {
    Http::fake([
        'graph.threads.com/v1.0/CONT1*' => Http::response(['id' => 'CONT1', 'status' => 'PUBLISHED']),
        'graph.threads.com/v1.0/'.TH_UID.'/threads?*' => Http::response(['data' => []]),
    ]);

    $r = $this->publisher->poll($this->account, thRequest(ContentType::ThreadsVideo, 'x', [thMedia(8, 'video')], 'CONT1'));

    expect($r->isPublished())->toBeTrue();
    expect(thSent('POST', '/threads_publish'))->toBe([]);
});

test('poll: ERROR y EXPIRED son un error permanente con el motivo traducido', function (string $status, string $message, string $needle) {
    Http::fake(['graph.threads.com/v1.0/CONT1*' => Http::response(['id' => 'CONT1', 'status' => $status, 'error_message' => $message])]);

    expect(fn () => $this->publisher->poll($this->account, thRequest(ContentType::ThreadsVideo, 'x', [thMedia(8, 'video')], 'CONT1')))
        ->toThrow(PermanentPublishException::class, $needle);
})->with([
    'descarga' => ['ERROR', 'FAILED_DOWNLOADING_VIDEO', 'no pudo descargar'],
    'fps' => ['ERROR', 'INVALID_FRAME_RATE', 'fotogramas'],
    'duracion' => ['ERROR', 'INVALID_DURATION', 'duracion'],
    'proporcion' => ['ERROR', 'INVALID_ASPEC_RATIO', 'proporcion'],
    'desconocido' => ['ERROR', 'UNKNOWN', 'no pudo procesar'],
    'caducado' => ['EXPIRED', '', 'caduco'],
]);

test('carrusel de 3 imagenes: 3 hijos, 1 padre, se publica el padre y queda publicada (CA-08)', function () {
    $ids = ['C1', 'C2', 'C3', 'PARENT'];
    Http::fake([
        'graph.threads.com/v1.0/'.TH_UID.'/threads_publish' => Http::response(['id' => 'POST9']),
        'graph.threads.com/v1.0/'.TH_UID.'/threads' => function () use (&$ids) {
            return Http::response(['id' => array_shift($ids)]);
        },
        'graph.threads.com/v1.0/POST9*' => Http::response(['id' => 'POST9', 'permalink' => 'https://www.threads.com/@ana.threads/post/car']),
        'graph.threads.com/v1.0/*' => Http::response(['status' => 'FINISHED']),
    ]);

    $result = $this->publisher->publish($this->account, thRequest(ContentType::ThreadsCarousel, 'Mi carrusel', [thMedia(1), thMedia(2), thMedia(3)]));

    expect($result->isPublished())->toBeTrue()->and($result->externalId)->toBe('POST9');
    $creates = thSent('POST', '/threads');
    expect($creates)->toHaveCount(4);
    foreach ([0, 1, 2] as $i) {
        expect($creates[$i]->data())->toMatchArray(['media_type' => 'IMAGE', 'is_carousel_item' => 'true', 'image_url' => 'https://signed.example/m'.($i + 1).'.jpg'])->not->toHaveKey('text');
    }
    expect($creates[3]->data())->toMatchArray(['media_type' => 'CAROUSEL', 'children' => 'C1,C2,C3', 'text' => 'Mi carrusel']);
    expect(thSent('POST', '/threads_publish')[0]->data()['creation_id'])->toBe('PARENT');
    // Cada ID se guarda en el acto (checkpoint) antes del paso siguiente.
    $saved = array_values(array_filter(array_map(fn ($c) => $c['progress']['children'] ?? null, thCheckpoints())));
    expect(end($saved))->toBe(['C1', 'C2', 'C3']);
});

test('carrusel mixto: un video entre las imagenes crea su hijo VIDEO', function () {
    $ids = ['C1', 'C2', 'PARENT'];
    Http::fake([
        'graph.threads.com/v1.0/'.TH_UID.'/threads_publish' => Http::response(['id' => 'POST9']),
        'graph.threads.com/v1.0/'.TH_UID.'/threads' => function () use (&$ids) {
            return Http::response(['id' => array_shift($ids)]);
        },
        'graph.threads.com/v1.0/*' => Http::response(['status' => 'FINISHED', 'permalink' => 'https://www.threads.com/x']),
    ]);

    $this->publisher->publish($this->account, thRequest(ContentType::ThreadsCarousel, null, [thMedia(1), thMedia(2, 'video')]));

    $creates = thSent('POST', '/threads');
    expect($creates[1]->data())->toMatchArray(['media_type' => 'VIDEO', 'video_url' => 'https://signed.example/m2.mp4', 'is_carousel_item' => 'true']);
});

test('carrusel: si un hijo sigue procesandose el paso guarda el avance y poll lo retoma sin recrear hijos', function () {
    $statuses = ['IN_PROGRESS', 'FINISHED'];
    Http::fake([
        'graph.threads.com/v1.0/'.TH_UID.'/threads' => Http::sequence()->push(['id' => 'C1'])->push(['id' => 'C2'])->push(['id' => 'PARENT']),
        'graph.threads.com/v1.0/'.TH_UID.'/threads_publish' => Http::response(['id' => 'POST9']),
        'graph.threads.com/v1.0/C1*' => Http::response(['status' => 'FINISHED']),
        'graph.threads.com/v1.0/C2*' => function () use (&$statuses) {
            return Http::response(['status' => array_shift($statuses)]);
        },
        'graph.threads.com/v1.0/PARENT*' => Http::response(['status' => 'FINISHED']),
        'graph.threads.com/v1.0/POST9*' => Http::response(['permalink' => 'https://www.threads.com/x']),
    ]);

    $first = $this->publisher->publish($this->account, thRequest(ContentType::ThreadsCarousel, 'x', [thMedia(1), thMedia(2)]));

    expect($first->isProcessing())->toBeTrue()->and($first->reference)->toBe('carousel')
        ->and($first->progress)->toMatchArray(['children' => ['C1', 'C2'], 'ready' => ['C1']]);

    $second = $this->publisher->poll($this->account, thRequest(ContentType::ThreadsCarousel, 'x', [thMedia(1), thMedia(2)], 'carousel', $first->progress));

    expect($second->isPublished())->toBeTrue();
    expect(thSent('POST', '/threads'))->toHaveCount(3); // 2 hijos + 1 padre: ninguno recreado
});

test('carrusel: un hijo en ERROR falla de forma permanente con el motivo', function () {
    Http::fake([
        'graph.threads.com/v1.0/'.TH_UID.'/threads' => Http::sequence()->push(['id' => 'C1'])->push(['id' => 'C2']),
        'graph.threads.com/v1.0/C1*' => Http::response(['status' => 'FINISHED']),
        'graph.threads.com/v1.0/C2*' => Http::response(['status' => 'ERROR', 'error_message' => 'FAILED_DOWNLOADING_VIDEO']),
    ]);

    expect(fn () => $this->publisher->publish($this->account, thRequest(ContentType::ThreadsCarousel, 'x', [thMedia(1), thMedia(2)])))
        ->toThrow(PermanentPublishException::class, 'no pudo descargar');
});

test('tipos y tamanos no admitidos se rechazan antes de llamar a la red', function (ContentType $type, Closure $media, ?string $text) {
    Http::fake();

    expect(fn () => $this->publisher->publish($this->account, thRequest($type, $text, $media())))->toThrow(PermanentPublishException::class);
    Http::assertNothingSent();
})->with([
    'carrusel de 1' => [ContentType::ThreadsCarousel, fn () => [thMedia(1)], 'x'],
    'imagen sin media' => [ContentType::ThreadsImage, fn () => [], 'x'],
    'texto vacio' => [ContentType::ThreadsText, fn () => [], ''],
    'tipo de otra red' => [ContentType::InstagramImage, fn () => [thMedia(1)], 'x'],
]);

test('carrusel de 21 elementos se rechaza', function () {
    Http::fake();
    $media = array_map(fn ($i) => thMedia($i), range(1, 21));

    expect(fn () => $this->publisher->publish($this->account, thRequest(ContentType::ThreadsCarousel, 'x', $media)))->toThrow(PermanentPublishException::class, '2 y 20');
    Http::assertNothingSent();
});

test('credencial revocada: pide reconectar; 5xx, red y limite de uso: transitorio', function (array $response, int $status, string $exception) {
    Http::fake(['graph.threads.com/v1.0/'.TH_UID.'/threads' => Http::response($response, $status)]);

    expect(fn () => $this->publisher->publish($this->account, thRequest(ContentType::ThreadsText, 'x')))->toThrow($exception);
})->with([
    'token invalido' => [['error' => ['message' => 'Invalid OAuth access token', 'type' => 'OAuthException', 'code' => 190]], 400, ReconnectRequiredException::class],
    'servidor caido' => [['error' => ['message' => 'boom', 'code' => 2]], 503, TransientPublishException::class],
    'limite de uso' => [['error' => ['message' => 'limite', 'code' => 4]], 400, TransientPublishException::class],
    'demasiadas peticiones' => [['error' => ['message' => 'slow down', 'code' => 0]], 429, TransientPublishException::class],
    'permisos' => [['error' => ['message' => '(#200) Permissions error', 'code' => 200]], 403, PermanentPublishException::class],
    'contenido rechazado' => [['error' => ['message' => 'The text is too long', 'code' => 100]], 400, PermanentPublishException::class],
]);

test('un corte de red al crear el contenedor es transitorio', function () {
    Http::fake(['graph.threads.com/*' => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => $this->publisher->publish($this->account, thRequest(ContentType::ThreadsText, 'x')))->toThrow(TransientPublishException::class);
});

test('sin referencia, poll falla de forma permanente', function () {
    expect(fn () => $this->publisher->poll($this->account, thRequest(ContentType::ThreadsText, 'x')))->toThrow(PermanentPublishException::class);
});

test('con THREADS_TOKEN_HEADER el token va en Authorization: Bearer y no en la URL ni en el cuerpo', function () {
    config(['social.threads.token_in_header' => true]);
    thFakeText();

    $this->publisher->publish($this->account, thRequest(ContentType::ThreadsText, 'Hola'));

    $create = thSent('POST', '/threads')[0];
    expect($create->header('Authorization'))->toBe(['Bearer TH-LONG-TOKEN'])->and($create->data())->not->toHaveKey('access_token');
    foreach (Http::recorded() as [$request]) {
        expect($request->url())->not->toContain('TH-LONG-TOKEN');
    }
});
