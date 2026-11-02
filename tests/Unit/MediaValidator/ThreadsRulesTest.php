<?php

use App\Connectors\Network;
use App\Enums\ContentType;
use App\Models\MediaAsset;
use App\Services\MediaValidator;
use App\Support\TextLength;
use Tests\TestCase;

uses(TestCase::class);

function thImage(array $attrs = []): MediaAsset
{
    return new MediaAsset($attrs + [
        'original_name' => 'foto.jpg', 'mime' => 'image/jpeg', 'kind' => 'image', 'declared_size' => 500_000,
        'size' => 500_000, 'width' => 1200, 'height' => 900, 'status' => MediaAsset::STATUS_READY,
    ]);
}

function thVideo(array $attrs = []): MediaAsset
{
    return new MediaAsset($attrs + [
        'original_name' => 'v.mp4', 'mime' => 'video/mp4', 'kind' => 'video', 'declared_size' => 20_000_000,
        'size' => 20_000_000, 'width' => 1080, 'height' => 1920, 'duration' => 30.0, 'fps' => 30.0, 'codec' => 'h264', 'audio_codec' => 'aac',
        'status' => MediaAsset::STATUS_READY,
    ]);
}

function thCheck(ContentType $type, array $media, ?string $text): array
{
    return app(MediaValidator::class)->validate($type, $media, $text);
}

test('Threads tiene sus cuatro tipos de contenido y ninguno cae en otra red', function () {
    $types = ContentType::forNetwork(Network::Threads);

    expect(array_map(fn (ContentType $t) => $t->value, $types))->toBe(['threads_text', 'threads_image', 'threads_video', 'threads_carousel']);
    foreach ($types as $type) {
        expect($type->network())->toBe(Network::Threads)->and($type->label())->not->toBe('')->and($type->description())->not->toBe('')
            ->and(config('media_specs.types'))->toHaveKey($type->specKey());
    }
});

test('texto de 500 caracteres se acepta y 501 se rechaza; el modo de conteo es el de Threads', function () {
    expect(config('media_specs.types.threads_text.text.count'))->toBe(TextLength::THREADS)
        ->and(thCheck(ContentType::ThreadsText, [], str_repeat('a', 500)))->toBe([])
        ->and(thCheck(ContentType::ThreadsText, [], str_repeat('a', 501))[0])->toBe('El texto tiene 501 caracteres y una publicacion de texto de Threads admite como maximo 500.');
});

test('cada emoji cuenta sus bytes UTF-8 en el limite de 500', function () {
    // El cohete son 4 bytes: 124 cohetes = 496 y 125 = 500 (caben); 126 = 504 (no cabe), aunque sean 126 caracteres.
    expect(thCheck(ContentType::ThreadsText, [], str_repeat("\u{1F680}", 125)))->toBe([])
        ->and(thCheck(ContentType::ThreadsText, [], str_repeat("\u{1F680}", 126))[0])->toContain('504 caracteres')
        // Un texto mixto: 496 letras + 1 cohete (4 bytes) = 500.
        ->and(thCheck(ContentType::ThreadsText, [], str_repeat('a', 496)."\u{1F680}"))->toBe([])
        ->and(thCheck(ContentType::ThreadsText, [], str_repeat('a', 497)."\u{1F680}"))->not->toBe([]);
});

test('el texto es obligatorio en el tipo texto; en los de media es opcional', function () {
    expect(thCheck(ContentType::ThreadsText, [], '   ')[0])->toContain('necesita texto')
        ->and(thCheck(ContentType::ThreadsImage, [thImage()], null))->toBe([])
        ->and(thCheck(ContentType::ThreadsVideo, [thVideo()], ''))->toBe([]);
});

test('imagen: JPEG o PNG de hasta 8 MB y proporcion hasta 10:1', function () {
    expect(thCheck(ContentType::ThreadsImage, [thImage()], 'x'))->toBe([])
        ->and(thCheck(ContentType::ThreadsImage, [thImage(['mime' => 'image/png'])], 'x'))->toBe([])
        ->and(thCheck(ContentType::ThreadsImage, [thImage(['size' => 8 * 1024 * 1024])], 'x'))->toBe([])
        ->and(thCheck(ContentType::ThreadsImage, [thImage(['size' => 8 * 1024 * 1024 + 1])], 'x')[0])->toContain('8 MB')
        ->and(thCheck(ContentType::ThreadsImage, [thImage(['mime' => 'image/webp'])], 'x')[0])->toContain('solo admite JPEG, PNG')
        ->and(thCheck(ContentType::ThreadsImage, [thImage(['width' => 5000, 'height' => 400])], 'x')[0])->toContain('proporcion')
        ->and(thCheck(ContentType::ThreadsImage, [], 'x')[0])->toContain('necesita al menos un archivo');
});

test('video: MP4 o MOV, H.264/HEVC, 23-60 fps, hasta 300 s y 1 GB', function () {
    expect(thCheck(ContentType::ThreadsVideo, [thVideo(['duration' => 300])], 'x'))->toBe([])
        ->and(thCheck(ContentType::ThreadsVideo, [thVideo(['mime' => 'video/quicktime', 'codec' => 'hevc', 'fps' => 60.0])], 'x'))->toBe([])
        ->and(thCheck(ContentType::ThreadsVideo, [thVideo(['duration' => 300.5])], 'x')[0])->toContain('como maximo 5 min')
        ->and(thCheck(ContentType::ThreadsVideo, [thVideo(['fps' => 22.0])], 'x')[0])->toContain('al menos 23 fps')
        ->and(thCheck(ContentType::ThreadsVideo, [thVideo(['fps' => 61.0])], 'x')[0])->toContain('como maximo 60 fps')
        ->and(thCheck(ContentType::ThreadsVideo, [thVideo(['codec' => 'vp9'])], 'x')[0])->toContain('codec vp9')
        ->and(thCheck(ContentType::ThreadsVideo, [thVideo(['mime' => 'video/webm'])], 'x')[0])->toContain('solo admite MP4, MOV')
        ->and(thCheck(ContentType::ThreadsVideo, [thVideo(['size' => 1024 ** 3 + 1])], 'x')[0])->toContain('1 GB')
        ->and(thCheck(ContentType::ThreadsVideo, [thVideo(['width' => 2160])], 'x')[0])->toContain('1920')
        ->and(thCheck(ContentType::ThreadsVideo, [thVideo(['duration' => 0])], 'x'))->not->toBe([]);
});

test('carrusel: de 2 a 20 elementos, mezcla de imagenes y videos', function () {
    expect(thCheck(ContentType::ThreadsCarousel, array_fill(0, 2, thImage()), 'x'))->toBe([])
        ->and(thCheck(ContentType::ThreadsCarousel, array_fill(0, 20, thImage()), 'x'))->toBe([])
        ->and(thCheck(ContentType::ThreadsCarousel, [thImage(), thVideo()], 'x'))->toBe([])
        ->and(thCheck(ContentType::ThreadsCarousel, [thImage()], 'x')[0])->toContain('necesita al menos 2 archivos')
        ->and(thCheck(ContentType::ThreadsCarousel, array_fill(0, 21, thImage()), 'x')[0])->toContain('como maximo 20 archivos');
});

test('el tipo de Threads solo se ofrece con la red disponible', function () {
    config(['social.threads.enabled' => true, 'services.threads.client_id' => '']);
    expect(ContentType::ThreadsText->isOffered())->toBeFalse();

    config(['services.threads.client_id' => 'THAPP1']);
    expect(ContentType::ThreadsText->isOffered())->toBeTrue();

    config(['social.threads.enabled' => false]);
    expect(ContentType::ThreadsCarousel->isOffered())->toBeFalse();
});

test('el compositor y create_post comparten reglas: el espejo de JS cuenta igual que el servidor', function () {
    $emoji = "a\u{1F680}b";
    expect(TextLength::count($emoji, TextLength::THREADS))->toBe(6); // a(1) + cohete(4) + b(1)
});
