<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Google\YouTubeClient;
use App\Connectors\Google\YouTubeQuota;
use App\Connectors\Google\YouTubeResumableUpload;
use App\Connectors\Google\YouTubeVideoMetadata;
use App\Connectors\Google\YouTubeVideoStatus;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function ytcAccount(): SocialAccount
{
    return new SocialAccount(['external_id' => 'UCabc', 'access_token' => 'tok']);
}

test('YouTubeClient: traduce los errores de la API a excepciones de dominio', function (int $status, array $body, string $expected) {
    Http::fake(['googleapis.com/*' => Http::response($body, $status)]);

    expect(fn () => (new YouTubeClient)->get(ytcAccount(), 'videos', ['id' => 'X']))->toThrow($expected);
})->with([
    '401 reconectar' => [401, ['error' => ['message' => 'x']], ReconnectRequiredException::class],
    '403 permisos' => [403, ['error' => ['message' => 'x', 'errors' => [['reason' => 'forbidden']]]], PermanentPublishException::class],
    '500 transitorio' => [500, ['error' => ['message' => 'x']], TransientPublishException::class],
    'cuota agotada' => [403, ['error' => ['message' => 'x', 'errors' => [['reason' => 'quotaExceeded']]]], QuotaExceededException::class],
    'limite de subidas' => [403, ['error' => ['message' => 'x', 'errors' => [['reason' => 'uploadLimitExceeded']]]], QuotaExceededException::class],
]);

test('YouTubeClient: quotaExceeded marca el cupo local como agotado', function () {
    Http::fake(['googleapis.com/*' => Http::response(['error' => ['errors' => [['reason' => 'quotaExceeded']]]], 403)]);
    $quota = new YouTubeQuota;

    expect(fn () => (new YouTubeClient($quota))->get(ytcAccount(), 'videos', []))->toThrow(QuotaExceededException::class)
        ->and($quota->used())->toBe($quota->limit());
});

test('YouTubeClient: una llamada sin pasada de subida usa su tope propio y con la pasada en marcha se acota a la marca dura', function () {
    config(['social.http_timeout' => 15, 'social.youtube.pass_seconds' => 100]);
    $client = new YouTubeClient;

    expect($client->pass()->callTimeout(15.0))->toBe(15.0);

    $client->pass()->begin(microtime(true) - 99.5);
    expect($client->pass()->callTimeout(15.0))->toBeLessThanOrEqual(1.0);
});

test('YouTubeResumableUpload: sin sesion guardada, resume pide reprogramar y forgetSession borra la sesion de cache', function () {
    $client = new YouTubeClient;
    $upload = new YouTubeResumableUpload($client, new YouTubeVideoStatus($client));
    $media = new PublishMedia(1, 'video', 'video/mp4', 'https://cdn.test/v.mp4', disk: 'local', path: 'v.mp4');
    $request = new PublishRequest(88, ContentType::YouTubeVideo, 'Titulo', [$media], [], PublishResult::UPLOADING);

    expect(fn () => $upload->resume(ytcAccount(), $request))->toThrow(PermanentPublishException::class, 'sesion de subida');

    Cache::put('youtube:upload:88', 'https://www.googleapis.com/upload?id=1', now()->addDay());
    YouTubeResumableUpload::forgetSession(88);

    expect(Cache::get('youtube:upload:88'))->toBeNull();
});

test('YouTubeVideoMetadata: el tag propio va primero, es opaco y estable por destino', function () {
    config(['app.key' => 'youtube-opacity-fixture-41']);
    $tag = YouTubeVideoMetadata::targetTag(77);

    // Un HMAC opaco sí puede contener «77» por azar; esta clave es un contraejemplo estable.
    expect($tag)->toMatch('/^ss-[a-f0-9]{16}$/D')
        ->and(YouTubeVideoMetadata::targetTag(77))->toBe($tag)
        ->and(YouTubeVideoMetadata::targetTag(78))->not->toBe($tag)
        ->and(YouTubeVideoMetadata::tagsFor(77, ['php', 'PHP', 'a,b']))->toBe([$tag, 'php', 'ab']);

    config(['app.key' => 'youtube-opacity-fixture-other']);
    expect(YouTubeVideoMetadata::targetTag(77))->not->toBe($tag);
});
