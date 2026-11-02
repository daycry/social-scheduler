<?php

use App\Enums\ContentType;
use App\Models\MediaAsset;
use App\Services\MediaValidator;

// T-68 · C-04b: un GIF de Giphy llega como MP4 (source=giphy) y se valida como el resto del vídeo. Los de menos de 3 s no valen en Reels ni
// en TikTok (`min_duration: 3` en config/media_specs.php); Bluesky y Threads lo aceptan como vídeo normal.

/** MP4 de Giphy ya importado (metadatos de ffprobe simulados). */
function giphyMp4(float $seconds, int $bytes = 900_000, array $extra = []): MediaAsset
{
    return new MediaAsset($extra + [
        'original_name' => 'giphy-abc123.mp4', 'mime' => 'video/mp4', 'kind' => 'video', 'source' => 'giphy', 'declared_size' => $bytes, 'size' => $bytes,
        'width' => 480, 'height' => 480, 'duration' => $seconds, 'fps' => 24.0, 'codec' => 'h264', 'audio_codec' => null, 'status' => MediaAsset::STATUS_READY,
    ]);
}

test('un GIF de menos de 3 s se rechaza en Reels de Instagram y Facebook y en TikTok con el mínimo exigido', function (ContentType $type) {
    $errors = app(MediaValidator::class)->validate($type, [giphyMp4(2.0)], null);

    expect(implode(' ', $errors))->toContain('2 s')->toContain('al menos 3 s');
})->with([ContentType::InstagramReel, ContentType::FacebookReel, ContentType::TikTokVideo]);

test('un GIF de 3 s o más sí vale en Reels y TikTok', function (ContentType $type) {
    expect(app(MediaValidator::class)->validate($type, [giphyMp4(3.2)], null))->toBe([]);
})->with([ContentType::InstagramReel, ContentType::TikTokVideo]);

test('las demás redes aceptan el GIF corto: Bluesky y Threads como vídeo, YouTube Shorts y Facebook vídeo', function (ContentType $type) {
    expect(app(MediaValidator::class)->validate($type, [giphyMp4(2.0)], null))->toBe([]);
})->with([ContentType::BlueskyVideo, ContentType::ThreadsVideo, ContentType::YouTubeShort, ContentType::FacebookVideo]);

test('en Bluesky el GIF respeta el tope de vídeo de la instalación (video_max_bytes)', function () {
    $cap = (int) config('social.bluesky.video_max_bytes');
    $validator = app(MediaValidator::class);

    expect($validator->validate(ContentType::BlueskyVideo, [giphyMp4(5.0, $cap)], null))->toBe([])
        ->and(implode(' ', $validator->validate(ContentType::BlueskyVideo, [giphyMp4(5.0, $cap + 1_000_000)], null)))->toContain('Bluesky');
});

test('ninguna red acepta image/gif: el GIF solo entra como MP4', function () {
    $gif = new MediaAsset(['original_name' => 'a.gif', 'mime' => 'image/gif', 'kind' => 'image', 'declared_size' => 1000, 'size' => 1000, 'width' => 100, 'height' => 100, 'status' => MediaAsset::STATUS_READY]);

    foreach ([ContentType::InstagramImage, ContentType::BlueskyPost, ContentType::ThreadsImage, ContentType::FacebookPhoto] as $type) {
        expect(app(MediaValidator::class)->validate($type, [$gif], null))->not->toBe([]);
    }
});
