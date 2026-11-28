<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Pinterest\PinterestPublisher;
use App\Enums\ContentType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NewNetworksFake;

/** Stream que se comporta como el socket HTTP de R2/S3: `fstat` no da tamano (0). */
class PinterestSocketLikeWrapper
{
    public static string $bytes = '';

    public $context;

    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->pos = 0;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $chunk = substr(self::$bytes, $this->pos, $count);
        $this->pos += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen(self::$bytes);
    }

    public function stream_stat(): array
    {
        return ['size' => 0];
    }

    public function stream_close(): void {}
}

beforeEach(function () {
    Storage::fake('local');
    NewNetworksFake::pinterest();
    if (! in_array('pinsock', stream_get_wrappers(), true)) {
        stream_wrapper_register('pinsock', PinterestSocketLikeWrapper::class);
    }
});

/**
 * Registro de lo que saldria por la red: el cuerpo se LEE (con el tamano que declararia Guzzle) dentro del fake, mientras el stream sigue
 * abierto, y el timeout se toma de las opciones de la peticion con un middleware global.
 */
function pinRealHandler(): object
{
    $state = new stdClass;
    $state->log = [];
    $state->uploadStatus = [];
    Http::globalMiddleware(fn (callable $handler) => function ($request, array $options) use ($handler, $state) {
        $state->timeout = $options['timeout'] ?? null;

        return $handler($request, $options);
    });
    Http::fake(function (Request $r) use ($state) {
        $uri = $r->url();
        $guzzle = $r->toPsrRequest();
        $size = $guzzle->getBody()->getSize();
        $body = '';
        while (! $guzzle->getBody()->eof()) {   // una sola pasada, como el socket real: un stream remoto no se rebobina
            $body .= $guzzle->getBody()->read(65536);
        }
        $state->log[] = ['method' => $r->method(), 'uri' => $uri, 'body' => $body, 'length' => $size, 'timeout' => $state->timeout ?? null];
        if (str_ends_with($uri, '/v5/media')) {
            return Http::response(['media_id' => '8200', 'upload_url' => 'https://pinterest-media-upload.s3-accelerate.amazonaws.com/', 'upload_parameters' => ['key' => 'k9']], 201);
        }

        return Http::response('', array_shift($state->uploadStatus) ?? 204);
    });

    return $state;
}

function pinRequest(PublishMedia $media, ?string $reference = null, array $progress = []): PublishRequest
{
    return new PublishRequest(targetId: 1, type: ContentType::PinterestVideo, text: 'V', media: [$media], options: ['pinterest_board_id' => '549755885175'], reference: $reference, since: now()->subMinute(), progress: $progress);
}

function pinUploads(object $state): array
{
    return array_values(array_filter($state->log, fn ($l) => str_contains($l['uri'], 'amazonaws.com')));
}

test('un stream remoto sin tamano conocido envia el video completo, con Content-Length', function () {
    $state = pinRealHandler();
    $bytes = str_repeat('Z', 300_000);
    PinterestSocketLikeWrapper::$bytes = $bytes;
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('size')->andReturn(strlen($bytes));
    $disk->shouldReceive('readStream')->andReturn(fopen('pinsock://video', 'rb'));
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk);
    $media = new PublishMedia(id: 5, kind: 'video', mime: 'video/mp4', url: 'https://signed.example/s.mp4', size: strlen($bytes), disk: 'r2', path: 'm/s.mp4');

    app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), pinRequest($media));

    $upload = pinUploads($state)[0];
    expect($upload['body'])->toContain($bytes)->and((int) $upload['length'])->toBeGreaterThan(strlen($bytes));
});

test('la subida acota su timeout a lo que queda del presupuesto del paso', function () {
    config(['publishing.step_budget_seconds' => 60]);
    $state = pinRealHandler();
    Storage::disk('local')->put('m/t.mp4', 'BYTES');
    $media = new PublishMedia(id: 5, kind: 'video', mime: 'video/mp4', url: 'https://signed.example/t.mp4', size: 5, disk: 'local', path: 'm/t.mp4');

    app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), pinRequest($media));

    expect(pinUploads($state)[0]['timeout'])->toBeLessThanOrEqual(60)->toBeGreaterThan(0);
});

test('sin presupuesto suficiente se reprograma sin registrar ningun media', function () {
    config(['publishing.step_budget_seconds' => 20]);
    $state = pinRealHandler();
    Storage::disk('local')->put('m/t.mp4', 'BYTES');
    $media = new PublishMedia(id: 5, kind: 'video', mime: 'video/mp4', url: 'https://signed.example/t.mp4', size: 5, disk: 'local', path: 'm/t.mp4');

    expect(fn () => app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), pinRequest($media)))->toThrow(TransientPublishException::class);
    expect($state->log)->toBe([]);
});

test('un reintento reutiliza el media ya registrado en lugar de registrar otro', function () {
    $state = pinRealHandler();
    Storage::disk('local')->put('m/t.mp4', 'BYTES');
    $media = new PublishMedia(id: 5, kind: 'video', mime: 'video/mp4', url: 'https://signed.example/t.mp4', size: 5, disk: 'local', path: 'm/t.mp4');
    $saved = [];
    $first = new PublishRequest(targetId: 1, type: ContentType::PinterestVideo, text: 'V', media: [$media], options: ['pinterest_board_id' => '549755885175'], since: now()->subMinute(), checkpoint: function ($ref, $progress) use (&$saved) {
        $saved = ['ref' => $ref, 'progress' => $progress];
    });
    app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), $first);
    $registrations = count(array_filter($state->log, fn ($l) => str_ends_with($l['uri'], '/v5/media')));

    $state->log = [];
    $result = app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), pinRequest($media, $saved['ref'], (array) $saved['progress']));

    expect($registrations)->toBe(1)->and(array_filter($state->log, fn ($l) => str_ends_with($l['uri'], '/v5/media')))->toBe([])
        ->and(count(pinUploads($state)))->toBe(1)->and($result->reference)->toBe('8200');
});

test('un video ya programado por encima del tope falla de forma permanente antes de registrar nada', function () {
    $state = pinRealHandler();
    $media = new PublishMedia(id: 5, kind: 'video', mime: 'video/mp4', url: 'https://signed.example/t.mp4', size: 200 * 1024 * 1024, disk: 'local', path: 'm/t.mp4');

    expect(fn () => app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), pinRequest($media)))
        ->toThrow(PermanentPublishException::class, 'supera 128 MB');
    expect($state->log)->toBe([]);
});

function pinMedia(): PublishMedia
{
    Storage::disk('local')->put('m/t.mp4', 'BYTES');

    return new PublishMedia(id: 5, kind: 'video', mime: 'video/mp4', url: 'https://signed.example/t.mp4', size: 5, disk: 'local', path: 'm/t.mp4');
}

function pinSaved(int $age): array
{
    return ['pinterest_upload' => ['url' => 'https://pinterest-media-upload.s3-accelerate.amazonaws.com/old', 'parameters' => ['key' => 'old'], 'issued_at' => now()->timestamp - $age]];
}

function pinRegistrations(object $state): int
{
    return count(array_filter($state->log, fn ($l) => str_ends_with($l['uri'], '/v5/media')));
}

test('el reloj del presupuesto arranca en findPublished, antes de la primera llamada de red del paso', function () {
    config(['publishing.step_budget_seconds' => 31]);   // registro 15 + subida minima 15 = 30
    $state = pinRealHandler();
    $request = pinRequest(pinMedia());
    $publisher = app(PinterestPublisher::class);
    $publisher->findPublished(NewNetworksFake::pinterestAccount(), $request);
    usleep(1_300_000);

    expect(fn () => $publisher->publish(NewNetworksFake::pinterestAccount(), $request))->toThrow(TransientPublishException::class);
    expect(pinRegistrations($state))->toBe(0);
});

test('una URL guardada caducada (por TTL) no se reutiliza: se registra de nuevo', function () {
    $state = pinRealHandler();

    app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), pinRequest(pinMedia(), '8100', pinSaved(3 * 3600)));

    expect(pinRegistrations($state))->toBe(1)->and(pinUploads($state)[0]['uri'])->not->toContain('/old');
});

test('si la URL guardada vigente es rechazada (403), se limpia y se registra de nuevo una sola vez', function () {
    $state = pinRealHandler();
    $state->uploadStatus = [403, 204];

    $result = app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), pinRequest(pinMedia(), '8100', pinSaved(30)));

    expect(pinRegistrations($state))->toBe(1)->and(count(pinUploads($state)))->toBe(2)->and($result->reference)->toBe('8200');
});

test('si la subida con la URL recien registrada es rechazada, falla claro y permanente, sin `permisos` y sin volver a registrar', function () {
    $state = pinRealHandler();
    $state->uploadStatus = [403];
    $saved = [];
    $media = pinMedia();
    $request = new PublishRequest(targetId: 1, type: ContentType::PinterestVideo, text: 'V', media: [$media], options: ['pinterest_board_id' => '549755885175'], since: now()->subMinute(), checkpoint: function ($ref, $progress) use (&$saved) {
        $saved[] = $progress;
    });

    try {
        app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), $request);
        $this->fail('debia fallar');
    } catch (PermanentPublishException $e) {
        expect($e->errorCode)->not->toBe('permisos')->and($e->getMessage())->toContain('rechazo la subida');
    }
    expect(pinRegistrations($state))->toBe(1);   // la URL firmada la limpia PublishOutcomes::failed (FailedProgressScrubTest)
});

test('tras subir bien, el progreso (con la URL firmada) se vacia', function () {
    pinRealHandler();

    $result = app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), pinRequest(pinMedia(), '8100', pinSaved(30)));

    expect($result->progress)->toBe([]);
});

test('el contador de registros persiste y, al pasar de 2, falla sin registrar mas medias', function () {
    $state = pinRealHandler();
    $media = pinMedia();

    expect(fn () => app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), pinRequest($media, null, ['pinterest_registrations' => 2])))
        ->toThrow(PermanentPublishException::class, 'rechazo la subida');
    expect(pinRegistrations($state))->toBe(0);

    // Con una URL guardada vigente que se rechaza y 2 registros ya hechos: tampoco se registra otro.
    $state->log = [];
    $state->uploadStatus = [403];
    expect(fn () => app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), pinRequest($media, '8100', pinSaved(30) + ['pinterest_registrations' => 2])))
        ->toThrow(PermanentPublishException::class);
    expect(pinRegistrations($state))->toBe(0);
});

test('el contador sobrevive al checkpoint que limpia la URL rechazada y se incrementa al registrar', function () {
    $state = pinRealHandler();
    $state->uploadStatus = [403, 204];
    $saved = [];
    $media = pinMedia();
    $request = new PublishRequest(targetId: 1, type: ContentType::PinterestVideo, text: 'V', media: [$media], options: ['pinterest_board_id' => '549755885175'], reference: '8100', since: now()->subMinute(), progress: pinSaved(30) + ['pinterest_registrations' => 1], checkpoint: function ($ref, $progress) use (&$saved) {
        $saved[] = $progress;
    });

    app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), $request);

    expect($saved[0])->toBe(['pinterest_registrations' => 1])
        ->and($saved[1]['pinterest_registrations'])->toBe(2)->and($saved[1])->toHaveKey('pinterest_upload');
});
