<?php

use App\Connectors\Bluesky\BlueskyPublisher;
use App\Connectors\Bluesky\Tid;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Enums\ContentType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BlueskyFake;

const VIDEO_BLOB = ['$type' => 'blob', 'ref' => ['$link' => 'bafkreivideo'], 'mimeType' => 'video/mp4', 'size' => 123456];

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'social.bluesky.default_langs' => ['es']]);
    Storage::disk('local')->put('media/test/v.mp4', str_repeat('V', 300_000));
    $this->account = BlueskyFake::account();
    $this->publisher = app(BlueskyPublisher::class);
    $this->checkpoints = [];
});

function bskyVideoMedia(): PublishMedia
{
    return new PublishMedia(id: 11, kind: 'video', mime: 'video/mp4', url: 'https://signed.example/v', width: 1080, height: 1920, duration: 30.0, size: 300_000, disk: 'local', path: 'media/test/v.mp4');
}

function bskyVideoRequest(array $progress = [], ?string $reference = null, ?Closure $checkpoint = null): PublishRequest
{
    return new PublishRequest(targetId: 1, type: ContentType::BlueskyVideo, text: 'Mi video #bluesky', media: [bskyVideoMedia()], reference: $reference, progress: $progress, checkpoint: $checkpoint);
}

function fakeBskyVideoServices(array $over = []): void
{
    Http::fake($over + [
        'pds.example.com/xrpc/com.atproto.server.getServiceAuth*' => Http::response(['token' => 'SERVICE-TOKEN']),
        'video.bsky.app/xrpc/app.bsky.video.getUploadLimits' => Http::response(['canUpload' => true, 'remainingDailyVideos' => 24, 'remainingDailyBytes' => 1_000_000_000]),
        'video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response(['jobId' => 'job-123', 'did' => BlueskyFake::DID, 'state' => 'JOB_STATE_CREATED']),
        'video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::response(['jobStatus' => ['jobId' => 'job-123', 'state' => 'JOB_STATE_COMPLETED', 'blob' => VIDEO_BLOB]]),
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://'.BlueskyFake::DID.'/app.bsky.feed.post/3kvideorkey22', 'cid' => 'cid']),
    ]);
}

test('publicar el video sube en streaming con el token de servicio y deja el destino procesando con la rkey', function () {
    $uploadedBytes = null;
    fakeBskyVideoServices(['video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => function ($request) use (&$uploadedBytes) {
        $uploadedBytes = strlen($request->body()); // el cuerpo es un flujo que se cierra tras enviarlo: se mide al recibirlo
        $this->assertSame('video/mp4', $request->header('Content-Type')[0]);

        return Http::response(['jobId' => 'job-123', 'did' => BlueskyFake::DID, 'state' => 'JOB_STATE_CREATED']);
    }]);

    $request = bskyVideoRequest(checkpoint: function (?string $reference, ?array $progress) {
        $this->checkpoints[] = [$reference, $progress];
    });
    $result = $this->publisher->publish($this->account, $request);

    expect($result->isProcessing())->toBeTrue()->and($result->reference)->toBe('job-123')
        ->and(Tid::isValid($result->progress['rkey']))->toBeTrue()->and($result->progress['phase'])->toBe('processing');
    // La rkey se persiste ANTES de subir (checkpoint) y es la misma del resultado.
    expect($this->checkpoints)->toHaveCount(1)->and($this->checkpoints[0][1]['rkey'])->toBe($result->progress['rkey']);

    $upload = Http::recorded()->first(fn ($pair) => str_contains($pair[0]->url(), 'uploadVideo'))[0];
    expect($upload->url())->toContain('did='.rawurlencode(BlueskyFake::DID))->toContain('name=video.mp4')
        ->and($upload->hasHeader('Authorization', 'Bearer SERVICE-TOKEN'))->toBeTrue()
        ->and($upload->hasHeader('Content-Type', 'video/mp4'))->toBeTrue()
        ->and($uploadedBytes)->toBe(300_000);

    // aud del token de subida: DID web del PDS de la cuenta; el de los limites, el del servicio de video.
    $auds = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'getServiceAuth'))->map(fn ($pair) => urldecode($pair[0]->url()))->values();
    expect($auds[0])->toContain('aud=did:web:video.bsky.app')->toContain('lxm=app.bsky.video.getUploadLimits')
        ->and($auds[1])->toContain('aud=did:web:pds.example.com')->toContain('lxm=com.atproto.repo.uploadBlob');
});

test('el sondeo pasa de procesando a publicada con el enlace de bsky.app', function () {
    fakeBskyVideoServices([
        'video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::sequence()
            ->push(['jobStatus' => ['jobId' => 'job-123', 'state' => 'JOB_STATE_ENCODING', 'progress' => 40]])
            ->push(['jobStatus' => ['jobId' => 'job-123', 'state' => 'JOB_STATE_COMPLETED', 'blob' => VIDEO_BLOB]]),
    ]);
    $rkey = Tid::generate();
    $progress = ['rkey' => $rkey, 'phase' => 'processing'];

    $first = $this->publisher->poll($this->account, bskyVideoRequest($progress, 'job-123'));
    expect($first->isProcessing())->toBeTrue()->and($first->reference)->toBe('job-123')->and($first->progress['rkey'])->toBe($rkey);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'createRecord'));

    $second = $this->publisher->poll($this->account, bskyVideoRequest($progress, 'job-123'));
    expect($second->isPublished())->toBeTrue()
        ->and($second->url)->toBe('https://bsky.app/profile/ana.example.com/post/3kvideorkey22');

    $sent = Http::recorded()->first(fn ($pair) => str_contains($pair[0]->url(), 'createRecord'))[0];
    expect($sent['rkey'])->toBe($rkey)->and($sent['record']['embed']['$type'])->toBe('app.bsky.embed.video')
        ->and($sent['record']['embed']['video'])->toBe(VIDEO_BLOB)
        ->and($sent['record']['embed']['aspectRatio'])->toBe(['width' => 1080, 'height' => 1920])
        ->and($sent['record']['text'])->toBe('Mi video #bluesky')->and($sent['record']['facets'])->toHaveCount(1);
});

test('un trabajo FAILED se traduce con su codigo', function () {
    fakeBskyVideoServices(['video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::response(['jobStatus' => ['jobId' => 'job-123', 'state' => 'JOB_STATE_FAILED', 'error' => 'VideoTooLong', 'message' => 'Video is too long']])]);

    $result = $this->publisher->poll($this->account, bskyVideoRequest(['rkey' => Tid::generate()], 'job-123'));

    expect($result->isFailed())->toBeTrue()->and($result->message)->toContain('VideoTooLong')->toContain('Video is too long');
});

test('email sin verificar: error permanente con un mensaje claro y sin subir nada', function () {
    fakeBskyVideoServices(['video.bsky.app/xrpc/app.bsky.video.getUploadLimits' => Http::response(['canUpload' => false, 'error' => 'unconfirmed_email', 'message' => 'You must verify your email'])]);

    expect(fn () => $this->publisher->publish($this->account, bskyVideoRequest()))
        ->toThrow(PermanentPublishException::class, 'email de la cuenta verificado');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'uploadVideo'));
});

test('canUpload falso por otro motivo: mensaje del servicio', function () {
    fakeBskyVideoServices(['video.bsky.app/xrpc/app.bsky.video.getUploadLimits' => Http::response(['canUpload' => false, 'message' => 'Account restricted'])]);

    expect(fn () => $this->publisher->publish($this->account, bskyVideoRequest()))->toThrow(PermanentPublishException::class, 'Account restricted');
});

test('cupo diario agotado: QuotaExceededException', function () {
    fakeBskyVideoServices(['video.bsky.app/xrpc/app.bsky.video.getUploadLimits' => Http::response(['canUpload' => true, 'remainingDailyVideos' => 0, 'remainingDailyBytes' => 5_000_000_000])]);

    expect(fn () => $this->publisher->publish($this->account, bskyVideoRequest()))->toThrow(QuotaExceededException::class);

    fakeBskyVideoServices(['video.bsky.app/xrpc/app.bsky.video.getUploadLimits' => Http::response(['canUpload' => true, 'remainingDailyVideos' => 5, 'remainingDailyBytes' => 100])]);
    expect(fn () => $this->publisher->publish($this->account, bskyVideoRequest()))->toThrow(QuotaExceededException::class);
});

test('un video repetido (already_exists) reutiliza el trabajo existente', function () {
    fakeBskyVideoServices(['video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response(['error' => 'already_exists', 'message' => 'Video already processed', 'jobId' => 'job-old', 'state' => 'JOB_STATE_COMPLETED'], 409)]);

    $result = $this->publisher->publish($this->account, bskyVideoRequest());

    expect($result->isProcessing())->toBeTrue()->and($result->reference)->toBe('job-old');
});

test('el reintento tras agotar el tiempo de subida reutiliza la rkey guardada', function () {
    fakeBskyVideoServices();
    $rkey = Tid::generate();

    $result = $this->publisher->publish($this->account, bskyVideoRequest(['rkey' => $rkey, 'phase' => 'uploading']));

    expect($result->progress['rkey'])->toBe($rkey);
});

test('un fichero de video que ya no existe es un error permanente', function () {
    fakeBskyVideoServices();
    Storage::disk('local')->delete('media/test/v.mp4');

    expect(fn () => $this->publisher->publish($this->account, bskyVideoRequest()))->toThrow(PermanentPublishException::class);
});

test('sondear sin referencia es un error permanente', function () {
    Http::fake();

    expect(fn () => $this->publisher->poll($this->account, bskyVideoRequest()))->toThrow(PermanentPublishException::class);
});
