<?php

use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Support\StepBudget;
use App\Enums\ContentType;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\ImageDownscaler;
use App\Services\MediaValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

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
    // N1: la validacion no mira la memoria del proceso web (la del worker es otra): solo el tope de pixeles.
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

test('B-2: una imagen por encima del tope de pixeles se rechaza con un mensaje, sin cargarla', function () {
    $asset = MediaAsset::factory()->for(User::factory()->create())->create([
        'disk' => 'local', 'path' => 'media/test/huge.jpg', 'mime' => 'image/jpeg', 'size' => 9_000_000, 'declared_size' => 9_000_000, 'width' => 12000, 'height' => 9000,
    ]);

    $errors = app(MediaValidator::class)->validate(ContentType::BlueskyPost, [$asset], 'hola');

    expect($errors)->toHaveCount(1)->and($errors[0])->toContain('demasiado grande')->toContain('12000 x 9000');
    // Solo el tope de pixeles (50 MP), nunca la memoria libre del proceso que pregunta.
    expect(ImageDownscaler::exceedsPixelCap(6000, 4000))->toBeFalse()
        ->and(ImageDownscaler::exceedsPixelCap(8000, 6250))->toBeFalse()
        ->and(ImageDownscaler::exceedsPixelCap(8001, 6250))->toBeTrue()
        ->and(ImageDownscaler::exceedsPixelCap(null, null))->toBeFalse();
    // El respaldo GD (sin ffmpeg) si necesita memoria: 24 MP piden ~384 MB.
    expect(ImageDownscaler::fitsInMemory(6000, 4000, 256 * 1024 * 1024))->toBeFalse()
        ->and(ImageDownscaler::fitsInMemory(6000, 4000, 1024 * 1024 * 1024))->toBeTrue();
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

test('D-2/N3: si otro proceso esta reduciendo la imagen se espera el bloqueo y, agotada la espera, el error es transitorio', function () {
    config(['media_specs.downscale_lock_wait' => 1]);
    $asset = storedImage(noisyJpeg(600, 100));
    $cap = 400_000;
    $lock = Cache::lock('downscale:'.$asset->id.':'.$cap, 30);
    expect($lock->get())->toBeTrue();

    $started = microtime(true);
    expect(fn () => app(ImageDownscaler::class)->fit($asset, $cap))->toThrow(TransientPublishException::class, 'reduciendo');
    expect(microtime(true) - $started)->toBeLessThan(10)
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

/** Ejecuta `tests/Support/downscale_probe.php` en un proceso PHP HIJO con memory_limit=256M (el de produccion). */
function downscaleChild(string $input, array $env = []): array
{
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=256M', base_path('tests/Support/downscale_probe.php'), $input], base_path(), $env + ['APP_ENV' => 'testing']);
    $process->setTimeout(240);
    $process->run();
    $json = json_decode(trim($process->getOutput()), true);

    return is_array($json) ? $json : ['error' => 'sin salida: '.$process->getOutput().$process->getErrorOutput()];
}

/** JPEG de 24 MP (6000 x 4000) con ruido, hecho con ffmpeg (GD no podria crearlo en 256 MB). Null si no hay ffmpeg. */
function bigPhoto(string $name): ?string
{
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.$name;
    $process = new Process([
        (string) config('media.ffmpeg'), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=s=6000x4000:d=1', '-vf', 'noise=alls=30:allf=t', '-frames:v', '1', '-q:v', '2', $file,
    ]);
    $process->setTimeout(120);

    try {
        $process->run();
    } catch (Throwable) {
        return null;
    }

    return $process->isSuccessful() && is_file($file) ? $file : null;
}

test('N1: con memory_limit=256M real una foto de 24 MP (>2 MB) se reduce dentro del tope y la memoria de PHP queda baja', function () {
    $photo = bigPhoto('photo24.jpg');
    if ($photo === null) {
        $this->markTestSkipped('ffmpeg no disponible para crear la foto de prueba.');
    }
    expect(filesize($photo))->toBeGreaterThan(2_000_000);

    $result = downscaleChild($photo);
    @unlink($photo);

    expect($result)->not->toHaveKey('error')->not->toHaveKey('exception')
        ->and($result['memory_limit'])->toBe('256M')
        ->and($result['derived'])->toBeTrue()->and($result['size'])->toBeLessThanOrEqual(2_000_000)
        ->and($result['width'] * $result['height'])->toBeLessThan(6000 * 4000)
        ->and($result['peak_bytes'])->toBeLessThan(64 * 1024 * 1024); // GD habria pedido ~384 MB
});

test('N1: sin ffmpeg la foto de 24 MP falla con un mensaje claro (GD no cabe en 256 MB) y no con un error fatal', function () {
    $photo = bigPhoto('photo24b.jpg');
    if ($photo === null) {
        $this->markTestSkipped('ffmpeg no disponible para crear la foto de prueba.');
    }

    $result = downscaleChild($photo, ['FFMPEG_BINARY' => 'ffmpeg-que-no-existe']);
    @unlink($photo);

    expect($result['exception'] ?? null)->toBe(PermanentPublishException::class)
        ->and($result['message'])->toContain('instala ffmpeg');
});

test('N1: sin ffmpeg una imagen que cabe en memoria se reduce con GD', function () {
    config(['media.ffmpeg' => 'ffmpeg-que-no-existe']);
    $asset = storedImage(noisyJpeg());

    $fit = app(ImageDownscaler::class)->fit($asset, 2_000_000);

    expect($fit['derived'])->toBeTrue()->and($fit['size'])->toBeLessThanOrEqual(2_000_000);
});

test('N1: un PNG con transparencia se aplana sobre blanco con ffmpeg', function () {
    $im = imagecreatetruecolor(1200, 900);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
    mt_srand(5);
    for ($i = 0; $i < 600_000; $i++) {
        imagesetpixel($im, mt_rand(600, 1199), mt_rand(0, 899), imagecolorallocatealpha($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), 0));
    }
    ob_start();
    imagepng($im, null, 1);
    $asset = storedImage((string) ob_get_clean(), 'image/png');

    $fit = app(ImageDownscaler::class)->fit($asset, 400_000);
    $copy = imagecreatefromstring(Storage::disk('local')->get($fit['path']));
    $px = imagecolorsforindex($copy, imagecolorat($copy, 10, 10));

    expect($fit['mime'])->toBe('image/jpeg')->and($px['red'])->toBeGreaterThan(240)->and($px['green'])->toBeGreaterThan(240)->and($px['blue'])->toBeGreaterThan(240);
});

/** JPEG de 800x600 con cuatro cuadrantes (arriba izq. rojo, arriba dcha. verde, abajo izq. azul, abajo dcha. blanco) y la orientacion EXIF dada. */
function quadrantJpeg(int $orientation): string
{
    $im = imagecreatetruecolor(800, 600);
    imagefilledrectangle($im, 0, 0, 399, 299, (int) imagecolorallocate($im, 255, 0, 0));
    imagefilledrectangle($im, 400, 0, 799, 299, (int) imagecolorallocate($im, 0, 255, 0));
    imagefilledrectangle($im, 0, 300, 399, 599, (int) imagecolorallocate($im, 0, 0, 255));
    imagefilledrectangle($im, 400, 300, 799, 599, (int) imagecolorallocate($im, 255, 255, 255));
    ob_start();
    imagejpeg($im, null, 95);
    $jpeg = (string) ob_get_clean();

    $tiff = "MM\x00*\x00\x00\x00\x08".pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('n', $orientation)."\x00\x00".pack('N', 0);
    $payload = "Exif\x00\x00".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
}

/** Dimensiones y color (r,g,b) de cada esquina de la copia: [ancho, alto, [tl, tr, bl, br]]. */
function cornersOf(string $bytes): array
{
    $im = imagecreatefromstring($bytes);
    [$w, $h] = [imagesx($im), imagesy($im)];
    $at = function (int $x, int $y) use ($im): string {
        $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
        $q = fn (int $v): int => $v > 127 ? 1 : 0;

        return $q($c['red']).$q($c['green']).$q($c['blue']);
    };

    return [$w, $h, [$at(intdiv($w, 8), intdiv($h, 8)), $at($w - intdiv($w, 8), intdiv($h, 8)), $at(intdiv($w, 8), $h - intdiv($h, 8)), $at($w - intdiv($w, 8), $h - intdiv($h, 8))]];
}

test('B-3 (T-22): las 8 orientaciones EXIF salen derechas con ffmpeg (-noautorotate + filtro explicito) y coinciden con la via GD', function () {
    $ffmpeg = (string) config('media.ffmpeg');
    $probe = new Process([$ffmpeg, '-version']);
    try {
        $probe->run();
    } catch (Throwable) {
        $this->markTestSkipped('ffmpeg no disponible.');
    }
    if (! $probe->isSuccessful()) {
        $this->markTestSkipped('ffmpeg no disponible.');
    }

    // Esquina (arriba izq. de la original, roja = 100) segun la orientacion: 1 TL, 2 TR, 3 BR, 4 BL, 5 TL, 6 TR, 7 BR, 8 BL.
    $redAt = [1 => 0, 2 => 1, 3 => 3, 4 => 2, 5 => 0, 6 => 1, 7 => 3, 8 => 2];
    $gdResults = [];
    foreach (range(1, 8) as $o) {
        $bytes = quadrantJpeg($o);
        config(['media.ffmpeg' => 'ffmpeg-que-no-existe']);
        DB::table('media_assets')->delete();
        $asset = storedImage($bytes);
        $gd = app(ImageDownscaler::class)->fit($asset, 6_000);
        expect($gd)->not->toBeNull()->and($gd['derived'])->toBeTrue();
        $gdResults[$o] = cornersOf(Storage::disk('local')->get($gd['path']));
    }

    foreach (range(1, 8) as $o) {
        config(['media.ffmpeg' => $ffmpeg]);
        DB::table('media_assets')->delete();
        $asset = storedImage(quadrantJpeg($o));
        $fit = app(ImageDownscaler::class)->fit($asset, 6_000);
        expect($fit)->not->toBeNull()->and($fit['derived'])->toBeTrue();
        [$w, $h, $corners] = cornersOf(Storage::disk('local')->get($fit['path']));

        expect(ImageDownscaler::swapsAxes($o) ? $h > $w : $w > $h)->toBeTrue("orientacion $o: dimensiones {$w}x{$h}")
            ->and(array_search('100', $corners, true))->toBe($redAt[$o], "orientacion $o: el rojo esta en otra esquina")
            ->and($corners)->toBe($gdResults[$o][2], "orientacion $o: ffmpeg y GD difieren")
            ->and($w > $h)->toBe($gdResults[$o][0] > $gdResults[$o][1]);
    }
});

test('T-22: si el presupuesto del paso se agota el reductor lanza un error transitorio y no deja copia a medias', function () {
    config(['publishing.step_budget_seconds' => 1]);
    $budget = StepBudget::start();
    $budget->spent(); // ya se hizo una llamada: allows() deja de ser incondicional
    config(['social.http_timeout' => 15]); // el peor caso de otra llamada (15 s) no cabe en 1 s
    $asset = storedImage(noisyJpeg());

    expect(fn () => app(ImageDownscaler::class)->fit($asset, 2_000_000, $budget))->toThrow(TransientPublishException::class, 'Sin tiempo')
        ->and(Storage::disk('local')->exists(app(ImageDownscaler::class)->derivedPath($asset->id, 2_000_000)))->toBeFalse();
});
