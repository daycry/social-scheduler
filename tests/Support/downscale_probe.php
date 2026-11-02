<?php

/*
 | Sonda del test N1: reduce una foto en un proceso PHP APARTE con el memory_limit que le pase quien lo lanza (256M como en produccion).
 | Imprime una linea JSON con el resultado y el pico de memoria de PHP. No forma parte de la aplicacion.
 */

use App\Services\Media\ImageDownscaler;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'downscale-probe-'.bin2hex(random_bytes(4));
mkdir($root);
config([
    'filesystems.media' => 'probe', 'filesystems.disks.probe' => ['driver' => 'local', 'root' => $root],
    'cache.default' => 'array', 'media.ffmpeg' => getenv('FFMPEG_BINARY') ?: config('media.ffmpeg'),
]);

$input = $argv[1];
Storage::disk('probe')->put('original.jpg', fopen($input, 'rb'));

try {
    $fit = app(ImageDownscaler::class)->fitFile('probe', 'original.jpg', 1, (int) filesize($input), 'image/jpeg', 2_000_000);
    $dims = $fit ? getimagesize($root.DIRECTORY_SEPARATOR.$fit['path']) : [0, 0];
    echo json_encode([
        'memory_limit' => ini_get('memory_limit'), 'derived' => $fit['derived'] ?? null, 'size' => $fit['size'] ?? null,
        'width' => $dims[0] ?? 0, 'height' => $dims[1] ?? 0, 'peak_bytes' => memory_get_peak_usage(true),
    ]);
} catch (Throwable $e) {
    echo json_encode(['exception' => $e::class, 'message' => $e->getMessage(), 'peak_bytes' => memory_get_peak_usage(true)]);
}
