<?php

use App\Enums\ContentType;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\ImageDownscaler;
use App\Services\MediaValidator;
use Illuminate\Support\Facades\Cache;
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

test('si no puede bajar del tope, la copia no se crea (lo ve el publicador, no la validacion en vivo)', function () {
    config(['social.bluesky.image_max_bytes' => 600]); // ningun JPEG de >= 400 px cabe en 600 bytes con ruido
    $asset = storedImage(noisyJpeg(900));

    expect(app(MediaValidator::class)->validate(ContentType::BlueskyPost, [$asset], 'hola'))->toBe([])
        ->and(app(ImageDownscaler::class)->fit($asset, 600))->toBeNull();
});

test('B-2: una foto de 24 MP no se carga en la peticion: la validacion no recomprime ni da error de memoria', function () {
    // El fichero ni existe en el disco: si la validacion intentara abrirlo o recomprimirlo, el resultado seria otro.
    $asset = MediaAsset::factory()->for(User::factory()->create())->create([
        'disk' => 'local', 'path' => 'media/test/24mp.jpg', 'mime' => 'image/jpeg', 'size' => 9_000_000, 'declared_size' => 9_000_000, 'width' => 6000, 'height' => 4000,
    ]);
    $before = memory_get_peak_usage(true);

    $errors = app(MediaValidator::class)->validate(ContentType::BlueskyPost, [$asset], 'hola');
    $warnings = app(MediaValidator::class)->warnings(ContentType::BlueskyPost, [$asset]);

    expect($errors)->toBe([])->and($warnings)->toHaveCount(1)->and($warnings[0])->toContain('copia recomprimida')
        ->and(memory_get_peak_usage(true) - $before)->toBeLessThan(20_000_000)
        ->and(Storage::disk('local')->exists('derived'))->toBeFalse();
});

test('B-2: una imagen por encima del tope de pixeles o de la memoria se rechaza con un mensaje, sin cargarla', function () {
    $asset = MediaAsset::factory()->for(User::factory()->create())->create([
        'disk' => 'local', 'path' => 'media/test/huge.jpg', 'mime' => 'image/jpeg', 'size' => 9_000_000, 'declared_size' => 9_000_000, 'width' => 12000, 'height' => 9000,
    ]);

    $errors = app(MediaValidator::class)->validate(ContentType::BlueskyPost, [$asset], 'hola');

    expect($errors)->toHaveCount(1)->and($errors[0])->toContain('demasiado grande')->toContain('12000 x 9000');
    // Memoria: 24 MP necesitan ~384 MB; con 256 MB libres no se carga, con 1 GB si.
    expect(ImageDownscaler::tooLargeToShrink(6000, 4000, 256 * 1024 * 1024))->toBeTrue()
        ->and(ImageDownscaler::tooLargeToShrink(6000, 4000, 1024 * 1024 * 1024))->toBeFalse()
        ->and(ImageDownscaler::tooLargeToShrink(null, null))->toBeFalse();
});

test('B-2: fit no carga una imagen que no cabe en memoria (null, sin error fatal)', function () {
    // PNG de 9000 x 6000 (54 MP) diminuto: la cabecera es valida pero supera el tope de pixeles.
    $png = file_get_contents('data://image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACMAAAAXCAYAAABJfsxdAAAAFklEQVR4nGP8z8Dwn4EIwESMolGFhAEAj8UBBvqw6WIAAAAASUVORK5CYII=');
    $png = substr($png, 0, 16).pack('N', 9000).pack('N', 6000).substr($png, 24);
    $asset = storedImage(str_repeat('x', 10).$png, 'image/png');
    $asset->forceFill(['size' => 2_500_000])->save();

    expect(app(ImageDownscaler::class)->fit($asset, 2_000_000))->toBeNull();
});

/** JPEG de $w x $h con la mitad izquierda roja y la derecha azul, con la orientacion EXIF indicada (APP1 big-endian). */
function exifJpeg(int $w, int $h, int $orientation): string
{
    mt_srand(3);
    $im = imagecreatetruecolor($w, $h);
    $red = (int) imagecolorallocate($im, 255, 0, 0);
    $blue = (int) imagecolorallocate($im, 0, 0, 255);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, $h - 1, $red);
    imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, $h - 1, $blue);
    ob_start();
    imagejpeg($im, null, 95);
    $jpeg = (string) ob_get_clean();

    $tiff = "MM\x00*\x00\x00\x00\x08".pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('n', $orientation)."\x00\x00".pack('N', 0);
    $payload = "Exif\x00\x00".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
}

test('B-3: la copia aplica la orientacion EXIF 6 antes de reescalar (una foto vertical no sale girada)', function () {
    config(['social.bluesky.image_max_bytes' => 5_000]);
    $bytes = exifJpeg(800, 600, 6); // se guarda apaisada, con Orientation=6 (hay que girarla 90 grados a la derecha)
    expect(ImageDownscaler::exifOrientation($bytes))->toBe(6);
    $asset = storedImage($bytes);
    $asset->forceFill(['width' => 800, 'height' => 600])->save();

    $fit = app(ImageDownscaler::class)->fit($asset, 5_000);

    expect($fit)->not->toBeNull()->and($fit['derived'])->toBeTrue();
    $copy = imagecreatefromstring(Storage::disk('local')->get($fit['path']));
    [$w, $h] = [imagesx($copy), imagesy($copy)];
    $top = imagecolorsforindex($copy, imagecolorat($copy, intdiv($w, 2), intdiv($h, 4)));
    $bottom = imagecolorsforindex($copy, imagecolorat($copy, intdiv($w, 2), intdiv($h * 3, 4)));

    // Vertical (el alto supera al ancho) y con la mitad roja (la izquierda de la original) ARRIBA tras girar a la derecha.
    expect($h)->toBeGreaterThan($w)->and($top['red'])->toBeGreaterThan(180)->and($top['blue'])->toBeLessThan(80)
        ->and($bottom['blue'])->toBeGreaterThan(180)->and($bottom['red'])->toBeLessThan(80);
    // La copia ya no lleva EXIF: no se vuelve a girar.
    expect(ImageDownscaler::exifOrientation(Storage::disk('local')->get($fit['path'])))->toBe(1);
});

test('B-3: la orientacion EXIF se lee y los giros de 90 grados intercambian ejes', function () {
    foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $o) {
        expect(ImageDownscaler::exifOrientation(exifJpeg(40, 30, $o)))->toBe($o)->and(ImageDownscaler::swapsAxes($o))->toBe($o >= 5);
    }
    expect(ImageDownscaler::exifOrientation('no es un jpeg'))->toBe(1);
});

test('D-2: la copia se genera bajo un bloqueo por media: si otro proceso la esta haciendo, no se compite con GD', function () {
    config(['media_specs.downscale_lock_wait' => 1]);
    $asset = storedImage(noisyJpeg(600, 100));
    $cap = 400_000;
    $lock = Cache::lock('downscale:'.$asset->id.':'.$cap, 30);
    expect($lock->get())->toBeTrue();

    $started = microtime(true);
    expect(app(ImageDownscaler::class)->fit($asset, $cap))->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(10)
        ->and(Storage::disk('local')->exists(app(ImageDownscaler::class)->derivedPath($asset->id, $cap)))->toBeFalse();

    $lock->release();
    expect(app(ImageDownscaler::class)->fit($asset, $cap))->not->toBeNull();
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
