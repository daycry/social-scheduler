<?php

use App\Enums\ContentType;
use App\Models\MediaAsset;
use App\Services\MediaValidator;
use Tests\TestCase;

uses(TestCase::class);

/** Emoji compuesto: 1 grafema, 7 puntos de codigo, 25 bytes UTF-8. */
const BSKY_FAMILY = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";

function bskyImage(array $attrs = []): MediaAsset
{
    return new MediaAsset($attrs + [
        'original_name' => 'foto.jpg', 'mime' => 'image/jpeg', 'kind' => 'image', 'declared_size' => 500_000,
        'size' => 500_000, 'width' => 1200, 'height' => 900, 'status' => MediaAsset::STATUS_READY,
    ]);
}

function bskyVideo(array $attrs = []): MediaAsset
{
    return new MediaAsset($attrs + [
        'original_name' => 'v.mp4', 'mime' => 'video/mp4', 'kind' => 'video', 'declared_size' => 20_000_000,
        'size' => 20_000_000, 'width' => 1080, 'height' => 1920, 'duration' => 30.0, 'fps' => 30.0, 'status' => MediaAsset::STATUS_READY,
    ]);
}

function bskyCheck(ContentType $type, array $media, ?string $text): array
{
    return app(MediaValidator::class)->validate($type, $media, $text);
}

test('300 grafemas se acepta (con emojis compuestos incluidos) y 301 se rechaza', function () {
    expect(bskyCheck(ContentType::BlueskyPost, [], str_repeat('a', 300)))->toBe([])
        // 100 familias = 100 grafemas y 2.500 bytes: dentro de los dos topes.
        ->and(bskyCheck(ContentType::BlueskyPost, [], str_repeat(BSKY_FAMILY, 100)))->toBe([])
        ->and(bskyCheck(ContentType::BlueskyPost, [], str_repeat('a', 301)))->toHaveCount(1);
});

test('el mensaje de 301 grafemas es el mismo para compositor y create_post (ambos usan MediaValidator)', function () {
    expect(bskyCheck(ContentType::BlueskyPost, [], str_repeat('a', 301))[0])
        ->toBe('El texto tiene 301 caracteres y una publicacion de Bluesky admite como maximo 300.');
});

test('el tope de 3000 bytes del lexicon tambien se aplica', function () {
    // 120 familias = 120 grafemas (< 300) y 3.000 bytes exactos: pasa; una mas, no.
    expect(bskyCheck(ContentType::BlueskyPost, [], str_repeat(BSKY_FAMILY, 120)))->toBe([])
        ->and(bskyCheck(ContentType::BlueskyPost, [], str_repeat(BSKY_FAMILY, 121))[0])->toContain('bytes');
});

test('un post necesita texto o una imagen', function () {
    expect(bskyCheck(ContentType::BlueskyPost, [], ''))->toBe(['Una publicacion de Bluesky necesita texto o al menos una imagen.'])
        ->and(bskyCheck(ContentType::BlueskyPost, [bskyImage()], ''))->toBe([]);
});

test('hasta 4 imagenes y solo imagenes', function () {
    expect(bskyCheck(ContentType::BlueskyPost, array_fill(0, 4, bskyImage()), 'hola'))->toBe([])
        ->and(bskyCheck(ContentType::BlueskyPost, array_fill(0, 5, bskyImage()), 'hola')[0])->toContain('maximo 4')
        ->and(bskyCheck(ContentType::BlueskyPost, [bskyVideo()], 'hola')[0])->toContain('solo admite imagen');
});

test('un video mayor que video_max_bytes se rechaza al guardar con el mensaje claro', function () {
    config(['social.bluesky.video_max_bytes' => 52_428_800]);

    expect(bskyCheck(ContentType::BlueskyVideo, [bskyVideo(['size' => 52_428_800, 'declared_size' => 52_428_800])], 'hola'))->toBe([]);

    $errors = bskyCheck(ContentType::BlueskyVideo, [bskyVideo(['size' => 60_000_000, 'declared_size' => 60_000_000])], 'hola');
    expect($errors)->toBe(['Bluesky solo admite videos de hasta 50 MB en esta instalacion; este pesa 57.22 MB.']);
});

test('el tope de video es configurable', function () {
    config(['social.bluesky.video_max_bytes' => 10_000_000]);

    expect(bskyCheck(ContentType::BlueskyVideo, [bskyVideo()], 'hola')[0])->toContain('9.54 MB');
});

test('el video solo admite MP4 y hasta 3 minutos', function () {
    expect(bskyCheck(ContentType::BlueskyVideo, [bskyVideo(['mime' => 'video/quicktime'])], null)[0])->toContain('MP4')
        ->and(bskyCheck(ContentType::BlueskyVideo, [bskyVideo(['duration' => 200.0])], null)[0])->toContain('3 min');
});

test('el video exige exactamente un archivo', function () {
    expect(bskyCheck(ContentType::BlueskyVideo, [], 'hola'))->not->toBe([]);
});
