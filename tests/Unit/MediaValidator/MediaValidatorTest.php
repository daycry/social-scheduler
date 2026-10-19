<?php

use App\Enums\ContentType;
use App\Models\MediaAsset;
use App\Services\MediaValidator;
use Tests\TestCase;

uses(TestCase::class);

/** MediaAsset en memoria (sin BD). */
function imgAsset(array $attrs = []): MediaAsset
{
    return new MediaAsset($attrs + [
        'original_name' => 'foto.jpg', 'mime' => 'image/jpeg', 'kind' => 'image', 'declared_size' => 1_000_000,
        'size' => 1_000_000, 'width' => 1080, 'height' => 1080, 'status' => MediaAsset::STATUS_READY,
    ]);
}

function vidAsset(array $attrs = []): MediaAsset
{
    return imgAsset($attrs + [
        'original_name' => 'v.mp4', 'mime' => 'video/mp4', 'kind' => 'video', 'size' => 20_000_000, 'declared_size' => 20_000_000,
        'width' => 1080, 'height' => 1920, 'duration' => 30.0, 'fps' => 30.0, 'codec' => 'h264', 'audio_codec' => 'aac',
    ]);
}

function checkMedia(ContentType $type, array $media, ?string $text = 'hola'): array
{
    return app(MediaValidator::class)->validate($type, $media, $text);
}

test('MediaValidator: hay reglas versionadas para los 13 tipos de contenido', function () {
    $types = config('media_specs.types');

    expect(config('media_specs.version'))->not->toBeEmpty()->and($types)->toHaveCount(13);
    foreach (ContentType::cases() as $type) {
        expect($types)->toHaveKey($type->specKey())->and($types)->toHaveKey($type->specKey('video'));
    }
    expect(array_keys($types))->toContain('instagram_story_image', 'instagram_story_video', 'tiktok_photo');
});

// Un caso valido por cada uno de los 13 tipos.
test('MediaValidator: acepta contenido valido en los 13 tipos', function (string $key, Closure $media) {
    $type = ContentType::tryFrom($key) ?? ContentType::InstagramStory;
    expect(checkMedia($type, $media()))->toBe([]);
})->with([
    'ig imagen' => ['instagram_image', fn () => [imgAsset()]],
    'ig carrusel' => ['instagram_carousel', fn () => [imgAsset(), vidAsset(['duration' => 20, 'width' => 1080, 'height' => 1080])]],
    'ig reel' => ['instagram_reel', fn () => [vidAsset(['duration' => 600])]],
    'ig historia imagen' => ['instagram_story', fn () => [imgAsset(['width' => 1080, 'height' => 1920])]],
    'ig historia video' => ['instagram_story', fn () => [vidAsset(['duration' => 45])]],
    'fb texto' => ['facebook_text', fn () => []],
    'fb foto' => ['facebook_photo', fn () => [imgAsset(['mime' => 'image/png'])]],
    'fb video' => ['facebook_video', fn () => [vidAsset(['width' => 1920, 'height' => 1080, 'duration' => 900])]],
    'fb reel' => ['facebook_reel', fn () => [vidAsset(['duration' => 60])]],
    'yt video' => ['youtube_video', fn () => [vidAsset(['width' => 1920, 'height' => 1080, 'duration' => 3600])]],
    'yt short' => ['youtube_short', fn () => [vidAsset(['duration' => 59])]],
    'tiktok video' => ['tiktok_video', fn () => [vidAsset(['duration' => 120])]],
    'tiktok fotos' => ['tiktok_photo', fn () => array_fill(0, 3, imgAsset(['mime' => 'image/webp']))],
]);

// Un caso invalido por cada tipo, con el limite exacto en el mensaje.
test('MediaValidator: rechaza indicando el limite incumplido en los 13 tipos', function (ContentType $type, Closure $media, ?string $text, string $needle) {
    $errors = checkMedia($type, $media(), $text);

    expect($errors)->not->toBeEmpty()
        ->and(implode(' | ', $errors))->toContain($needle);
})->with([
    'ig imagen png' => [ContentType::InstagramImage, fn () => [imgAsset(['mime' => 'image/png'])], 'x', 'solo admite JPEG'],
    'ig imagen panoramica' => [ContentType::InstagramImage, fn () => [imgAsset(['width' => 3000, 'height' => 500])], 'x', 'proporcion'],
    'ig carrusel 1 solo' => [ContentType::InstagramCarousel, fn () => [imgAsset()], 'x', 'necesita al menos 2 archivos'],
    'ig carrusel 11' => [ContentType::InstagramCarousel, fn () => array_fill(0, 11, imgAsset()), 'x', 'como maximo 10 archivos'],
    'ig reel largo (CA-05)' => [ContentType::InstagramReel, fn () => [vidAsset(['duration' => 1200])], 'x', 'dura 20 min y un reel de Instagram admite como maximo 15 min'],
    'ig reel corto' => [ContentType::InstagramReel, fn () => [vidAsset(['duration' => 2])], 'x', 'al menos 3 s'],
    'ig reel imagen' => [ContentType::InstagramReel, fn () => [imgAsset()], 'x', 'solo admite video'],
    'ig historia larga' => [ContentType::InstagramStory, fn () => [vidAsset(['duration' => 90])], 'x', 'como maximo 60 s'],
    'ig historia 2 archivos' => [ContentType::InstagramStory, fn () => [imgAsset(), imgAsset()], 'x', 'como maximo 1 archivo'],
    'fb texto vacio' => [ContentType::FacebookText, fn () => [], '   ', 'necesita texto'],
    'fb texto con foto' => [ContentType::FacebookText, fn () => [imgAsset()], 'x', 'no admite imagenes ni videos'],
    'fb foto sin media' => [ContentType::FacebookPhoto, fn () => [], 'x', 'necesita al menos un archivo'],
    'fb video largo' => [ContentType::FacebookVideo, fn () => [vidAsset(['duration' => 1500])], 'x', 'como maximo 20 min'],
    'fb reel horizontal' => [ContentType::FacebookReel, fn () => [vidAsset(['width' => 1920, 'height' => 1080])], 'x', 'proporcion'],
    'fb reel pocos fps' => [ContentType::FacebookReel, fn () => [vidAsset(['fps' => 15.0])], 'x', 'al menos 24 fps'],
    'yt video corto sin texto grande' => [ContentType::YouTubeVideo, fn () => [vidAsset()], str_repeat('a', 5001), 'como maximo 5000'],
    'yt short largo' => [ContentType::YouTubeShort, fn () => [vidAsset(['duration' => 200])], 'x', 'como maximo 3 min.'],
    'yt short horizontal' => [ContentType::YouTubeShort, fn () => [vidAsset(['width' => 1920, 'height' => 1080])], 'x', 'proporcion'],
    'tiktok 22 fps' => [ContentType::TikTokVideo, fn () => [vidAsset(['fps' => 22.0])], 'x', 'va a 22 fps y un video de TikTok necesita al menos 23 fps'],
    'tiktok largo' => [ContentType::TikTokVideo, fn () => [vidAsset(['duration' => 700])], 'x', 'como maximo 10 min'],
    'tiktok fotos png' => [ContentType::TikTokPhoto, fn () => [imgAsset(['mime' => 'image/png'])], 'x', 'solo admite JPEG, WebP'],
    'tiktok 36 fotos' => [ContentType::TikTokPhoto, fn () => array_fill(0, 36, imgAsset()), 'x', 'como maximo 35'],
]);

test('MediaValidator: casos limite exactos (el limite se acepta, un poco mas se rechaza)', function () {
    expect(checkMedia(ContentType::InstagramReel, [vidAsset(['duration' => 900])]))->toBe([])
        ->and(checkMedia(ContentType::InstagramReel, [vidAsset(['duration' => 900.5])]))->not->toBe([])
        ->and(checkMedia(ContentType::TikTokVideo, [vidAsset(['fps' => 23.0])]))->toBe([])
        ->and(checkMedia(ContentType::TikTokVideo, [vidAsset(['fps' => 22.99])]))->not->toBe([])
        ->and(checkMedia(ContentType::InstagramCarousel, array_fill(0, 10, imgAsset())))->toBe([])
        ->and(checkMedia(ContentType::InstagramImage, [imgAsset(['size' => 8 * 1024 * 1024])]))->toBe([])
        ->and(checkMedia(ContentType::InstagramImage, [imgAsset(['size' => 8 * 1024 * 1024 + 1])]))->not->toBe([])
        ->and(checkMedia(ContentType::InstagramImage, [imgAsset()], str_repeat('a', 2200)))->toBe([])
        ->and(checkMedia(ContentType::InstagramImage, [imgAsset()], str_repeat('a', 2201)))->not->toBe([]);
});

test('MediaValidator: una media sin subir o sin metadatos de video se rechaza', function () {
    expect(checkMedia(ContentType::InstagramImage, [imgAsset(['status' => MediaAsset::STATUS_PENDING])]))->toContain('el archivo aun no esta subido o no es valido.');
    expect(implode(' ', checkMedia(ContentType::InstagramReel, [vidAsset(['duration' => null])])))->toContain('No se pudo leer la duracion');
    expect(implode(' ', checkMedia(ContentType::TikTokVideo, [vidAsset(['fps' => null])])))->toContain('tasa de fotogramas');
});

test('MediaValidator: la historia resuelve su regla segun la media y assertValid lanza con todos los motivos', function () {
    expect(ContentType::InstagramStory->specKey('video'))->toBe('instagram_story_video')
        ->and(ContentType::InstagramStory->specKey('image'))->toBe('instagram_story_image');

    $v = app(MediaValidator::class);
    expect(fn () => $v->assertValid(ContentType::InstagramReel, [vidAsset(['duration' => 1200, 'fps' => 10.0])], 'x'))
        ->toThrow(InvalidArgumentException::class, 'admite como maximo 15 min');
});
