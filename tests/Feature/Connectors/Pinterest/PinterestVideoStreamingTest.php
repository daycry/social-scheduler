<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Pinterest\PinterestPublisher;
use App\Enums\ContentType;
use App\Models\MediaAsset;
use App\Services\MediaValidator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NewNetworksFake;

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    NewNetworksFake::pinterest();
});

function psRequest(PublishMedia $media): PublishRequest
{
    return new PublishRequest(targetId: 1, type: ContentType::PinterestVideo, text: 'V', media: [$media], options: ['pinterest_board_id' => '549755885175'], since: now()->subMinute());
}

/** @param array<string, mixed> $seen se rellena, DURANTE la peticion, con el campo `file` (Guzzle cierra el stream al enviarlo). */
function psFake(array &$seen = []): void
{
    Http::fake([
        'api.pinterest.com/v5/media' => Http::response(['media_id' => '8100', 'upload_url' => 'https://pinterest-media-upload.s3-accelerate.amazonaws.com/', 'upload_parameters' => ['key' => 'k1']], 201),
        'pinterest-media-upload.s3-accelerate.amazonaws.com/*' => function (Request $r) use (&$seen) {
            $file = collect($r->data())->firstWhere('name', 'file');
            $seen = ['contents' => get_debug_type($file['contents'] ?? null), 'mime' => $file['headers']['Content-Type'] ?? null, 'names' => collect($r->data())->pluck('name')->all()];

            return Http::response('', 204);
        },
    ]);
}

test('el video se sube como stream multipart, no como string', function () {
    $seen = [];
    psFake($seen);
    Storage::disk('local')->put('m/s.mp4', str_repeat('A', 4096));
    $media = new PublishMedia(id: 5, kind: 'video', mime: 'video/mp4', url: 'https://signed.example/s.mp4', size: 4096, disk: 'local', path: 'm/s.mp4');

    $result = app(PinterestPublisher::class)->publish(NewNetworksFake::pinterestAccount(), psRequest($media));

    expect($result->isProcessing())->toBeTrue();
    expect($seen)->toBe(['contents' => 'App\Connectors\Support\KnownSizeStream', 'mime' => 'video/mp4', 'names' => ['key', 'file']]);
});

test('un fichero grande no hace crecer la memoria con su tamano', function () {
    $seen = [];
    psFake($seen);
    $size = 48 * 1024 * 1024;
    $path = Storage::disk('local')->path('m/big.mp4');
    @mkdir(dirname($path), 0777, true);
    $h = fopen($path, 'wb');
    $chunk = str_repeat('B', 1024 * 1024);
    for ($i = 0; $i < 48; $i++) {
        fwrite($h, $chunk);
    }
    fclose($h);
    unset($chunk);
    $media = new PublishMedia(id: 5, kind: 'video', mime: 'video/mp4', url: 'https://signed.example/b.mp4', size: $size, disk: 'local', path: 'm/big.mp4');
    $publisher = app(PinterestPublisher::class);
    $account = NewNetworksFake::pinterestAccount();
    $request = psRequest($media);

    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $publisher->publish($account, $request);
    $growth = memory_get_peak_usage() - $before;

    // Cargar el fichero entero costaria >= 48 MB (y el doble al armar el multipart); en streaming no depende del tamano.
    expect($growth)->toBeLessThan(16 * 1024 * 1024);
});

test('el timeout de la subida cabe en el job y un video por encima del tope se rechaza en la validacion', function () {
    expect(config('social.pinterest.upload_timeout'))->toBeGreaterThan(60)->toBeLessThan(110);

    $video = fn (int $bytes) => new MediaAsset([
        'kind' => 'video', 'mime' => 'video/mp4', 'size' => $bytes, 'width' => 720, 'height' => 1280, 'duration' => 30.0,
        'fps' => 30.0, 'status' => MediaAsset::STATUS_READY,
    ]);
    $validator = app(MediaValidator::class);

    expect($validator->validate(ContentType::PinterestVideo, [$video(128 * 1024 * 1024)], null))->toBe([])
        ->and($validator->validate(ContentType::PinterestVideo, [$video(129 * 1024 * 1024)], null))->not->toBe([]);
});
