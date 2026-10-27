<?php

use App\Enums\ContentType;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\ImageDownscaler;
use App\Services\MediaValidator;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'social.bluesky.image_max_bytes' => 2_000_000]);
});

/** Ruido aleatorio: no comprime, asi que un JPEG de calidad 100 de 1600x1600 pesa ~5 MB. */
function noisyJpeg(int $side = 1600, int $quality = 100): string
{
    mt_srand(7);
    $im = imagecreatetruecolor($side, $side);
    for ($y = 0; $y < $side; $y++) {
        for ($x = 0; $x < $side; $x++) {
            imagesetpixel($im, $x, $y, (mt_rand(0, 255) << 16) | (mt_rand(0, 255) << 8) | mt_rand(0, 255));
        }
    }
    ob_start();
    imagejpeg($im, null, $quality);

    return (string) ob_get_clean();
}

function storedImage(string $bytes, string $mime = 'image/jpeg'): MediaAsset
{
    $asset = MediaAsset::factory()->for(User::factory()->create())->create([
        'disk' => 'local', 'path' => 'media/test/original.'.($mime === 'image/png' ? 'png' : 'jpg'), 'mime' => $mime,
        'size' => strlen($bytes), 'declared_size' => strlen($bytes), 'width' => 1600, 'height' => 1600,
    ]);
    Storage::disk('local')->put($asset->path, $bytes);

    return $asset;
}

test('una imagen de mas de 3 MB produce una copia derivada dentro del tope', function () {
    $asset = storedImage(noisyJpeg());
    expect($asset->size)->toBeGreaterThan(3_000_000);

    $fit = app(ImageDownscaler::class)->fit($asset, 2_000_000);

    expect($fit)->not->toBeNull()
        ->and($fit['derived'])->toBeTrue()->and($fit['size'])->toBeLessThanOrEqual(2_000_000)->and($fit['mime'])->toBe('image/jpeg')
        ->and(Storage::disk('local')->size($fit['path']))->toBe($fit['size']);
    // La original no se toca.
    expect(Storage::disk('local')->size($asset->path))->toBe($asset->size);
});

test('una imagen que ya cabe se devuelve tal cual, sin copia', function () {
    $asset = storedImage(noisyJpeg(200, 80));

    $fit = app(ImageDownscaler::class)->fit($asset, 2_000_000);

    expect($fit['derived'])->toBeFalse()->and($fit['path'])->toBe($asset->path);
    expect(Storage::disk('local')->allFiles('derived'))->toBe([]);
});

test('la copia se reutiliza por media y version de regla, no se recalcula', function () {
    $asset = storedImage(noisyJpeg());
    $downscaler = app(ImageDownscaler::class);

    $first = $downscaler->fit($asset, 2_000_000);
    $mtime = Storage::disk('local')->lastModified($first['path']);
    // Si volviera a procesar, fallaria: se borra la original y la copia cacheada debe bastar.
    Storage::disk('local')->delete($asset->path);
    $second = $downscaler->fit($asset, 2_000_000);

    expect($second['path'])->toBe($first['path'])->and($second['size'])->toBe($first['size'])
        ->and(Storage::disk('local')->lastModified($second['path']))->toBe($mtime);

    config(['media_specs.version' => '2027-01-01']);
    expect($downscaler->derivedPath($asset->id, 2_000_000))->not->toBe($first['path']);
});

test('un tope mas bajo genera otra copia', function () {
    $asset = storedImage(noisyJpeg());
    $downscaler = app(ImageDownscaler::class);

    $a = $downscaler->fit($asset, 2_000_000);
    $b = $downscaler->fit($asset, 1_000_000);

    expect($b['path'])->not->toBe($a['path'])->and($b['size'])->toBeLessThanOrEqual(1_000_000);
});

test('un PNG se convierte a JPEG sobre fondo blanco', function () {
    mt_srand(3);
    $im = imagecreatetruecolor(1400, 1400);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    for ($y = 0; $y < 1400; $y++) {
        for ($x = 0; $x < 1400; $x++) {
            imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 100)));
        }
    }
    ob_start();
    imagepng($im, null, 1);
    $asset = storedImage((string) ob_get_clean(), 'image/png');

    $fit = app(ImageDownscaler::class)->fit($asset, 2_000_000);

    expect($asset->size)->toBeGreaterThan(2_000_000)
        ->and($fit['mime'])->toBe('image/jpeg')->and($fit['size'])->toBeLessThanOrEqual(2_000_000);
});

test('si no puede bajar del tope, el guardado falla con un mensaje claro', function () {
    config(['social.bluesky.image_max_bytes' => 600]); // ningun JPEG de >= 400 px cabe en 600 bytes con ruido
    $asset = storedImage(noisyJpeg(900));

    $errors = app(MediaValidator::class)->validate(ContentType::BlueskyPost, [$asset], 'hola');

    expect($errors)->toHaveCount(1)->and($errors[0])->toContain('no se ha podido reducirla')->toContain('admite como maximo');
});

test('un fichero que no es una imagen valida falla sin romper', function () {
    $asset = storedImage('esto no es una imagen '.str_repeat('x', 2_500_000));

    expect(app(ImageDownscaler::class)->fit($asset, 2_000_000))->toBeNull();
});

test('una imagen grande que se puede reducir pasa la validacion y avisa de la recompresion', function () {
    $asset = storedImage(noisyJpeg());
    $validator = app(MediaValidator::class);

    expect($validator->validate(ContentType::BlueskyPost, [$asset], 'hola'))->toBe([]);

    $warnings = $validator->warnings(ContentType::BlueskyPost, [$asset]);
    expect($warnings)->toHaveCount(1)->and($warnings[0])->toContain('copia recomprimida')->toContain('la original no se modifica');
});
