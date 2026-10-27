<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Services\MediaStorage;
use GdImage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recompresion de imagenes por encima del tope de una red (Bluesky: `social.bluesky.image_max_bytes`, 2 MB). Crea una COPIA
 * derivada JPEG en el mismo disco, cacheada por media, tope y version de las reglas de media; la original nunca se toca.
 *
 * Estrategia: calidad 85 -> 75 -> 65 -> 55 y, si no basta, se reduce el lado largo un 15 % por pasada hasta un minimo de 400 px.
 * Si no baja del tope devuelve null y el guardado falla con un mensaje claro. Un PNG con transparencia se aplana sobre blanco.
 *
 * NUNCA corre dentro de una peticion web: lo usa el publicador (cola) y la validacion en vivo solo mira las dimensiones con
 * `tooLargeToShrink()`. Antes de cargar nada se comprueba el tope de pixeles y la memoria libre (un error fatal de GD no se puede
 * capturar), y la generacion de cada copia va bajo un bloqueo por media para que no se hagan varias a la vez. La orientacion EXIF se
 * aplica antes de reescalar (GD no conserva el EXIF, asi que la copia saldria girada).
 */
class ImageDownscaler
{
    private const QUALITIES = [85, 75, 65, 55];

    private const MIN_LONG_SIDE = 400;

    /** Tope de pixeles que se descodifican (evita una bomba de descompresion: 100 MP ~ 400 MB de RAM). */
    public const MAX_PIXELS = 50_000_000;

    /** Memoria que necesita GD por pixel en el peor momento (origen + lienzo + giro a 4 B cada uno, con margen). */
    public const BYTES_PER_PIXEL = 16;

    public function __construct(private readonly MediaStorage $storage) {}

    /**
     * Ruta de la copia que cabe en `$maxBytes`, creandola si hace falta. Null si la imagen no se puede reducir hasta el tope.
     *
     * @return array{disk: string, path: string, size: int, mime: string, derived: bool}|null
     */
    public function fit(MediaAsset $asset, int $maxBytes): ?array
    {
        return $this->fitFile((string) $asset->disk, (string) $asset->path, (int) $asset->id, (int) $asset->size, (string) $asset->mime, $maxBytes);
    }

    /** @return array{disk: string, path: string, size: int, mime: string, derived: bool}|null */
    public function fitFile(string $disk, string $path, int $id, int $size, string $mime, int $maxBytes): ?array
    {
        if ($size > 0 && $size <= $maxBytes) {
            return ['disk' => $disk, 'path' => $path, 'size' => $size, 'mime' => $mime, 'derived' => false];
        }

        $target = $this->derivedPath($id, $maxBytes);

        if (($existing = $this->existing($target, $maxBytes)) !== null) {
            return $existing;
        }

        try {
            // Un bloqueo por media y tope: dos publicaciones a la vez no recomprimen la misma imagen en paralelo (la segunda espera
            // y encuentra la copia hecha).
            return Cache::lock('downscale:'.$id.':'.$maxBytes, 120)->block((int) config('media_specs.downscale_lock_wait', 60), function () use ($path, $target, $maxBytes, $id) {
                if (($existing = $this->existing($target, $maxBytes)) !== null) {
                    return $existing;
                }

                try {
                    $bytes = $this->shrink((string) $this->storage->disk()->get($path), $maxBytes);
                } catch (Throwable $e) {
                    Log::warning('media.downscale_failed', ['media_id' => $id, 'exception' => $e::class]);

                    return null;
                }

                if ($bytes === null) {
                    return null;
                }

                $this->storage->disk()->put($target, $bytes);

                return ['disk' => $this->storage->diskName(), 'path' => $target, 'size' => strlen($bytes), 'mime' => 'image/jpeg', 'derived' => true];
            });
        } catch (LockTimeoutException) {
            return null;
        }
    }

    /** Ruta de la copia derivada: por media, tope y version de las reglas. */
    public function derivedPath(int $id, int $maxBytes): string
    {
        return 'derived/images/'.$id.'-'.$maxBytes.'-v'.preg_replace('/[^0-9A-Za-z]/', '', (string) config('media_specs.version')).'.jpg';
    }

    /**
     * ¿Es una imagen demasiado grande para recomprimirla (tope de pixeles o memoria libre)? Solo mira dimensiones: no carga nada, asi
     * que sirve en la validacion en vivo. Con dimensiones desconocidas devuelve false (lo comprobara el publicador antes de cargarla).
     */
    public static function tooLargeToShrink(?int $width, ?int $height, ?int $freeBytes = null): bool
    {
        if (! $width || ! $height) {
            return false;
        }

        $pixels = $width * $height;

        return $pixels > self::MAX_PIXELS || $pixels * self::BYTES_PER_PIXEL > ($freeBytes ?? self::freeMemory());
    }

    /** Memoria que aun puede usar este proceso (PHP_INT_MAX si no tiene limite). */
    public static function freeMemory(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return PHP_INT_MAX;
        }

        $limit = (int) $raw * match (strtolower(substr($raw, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return max(0, $limit - memory_get_usage(true));
    }

    /** Orientacion EXIF (1-8) de un JPEG; 1 si no tiene o no se puede leer. */
    public static function exifOrientation(string $bytes): int
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            return 1;
        }

        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $exif = @exif_read_data($stream, 'IFD0');
            $value = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

            return $value >= 1 && $value <= 8 ? $value : 1;
        } catch (Throwable) {
            return 1;
        } finally {
            fclose($stream);
        }
    }

    /** Un giro de 90 grados (orientaciones 5-8) intercambia ancho y alto. */
    public static function swapsAxes(int $orientation): bool
    {
        return $orientation >= 5 && $orientation <= 8;
    }

    /** @return array{disk: string, path: string, size: int, mime: string, derived: bool}|null La copia ya hecha, si sigue cabiendo en el tope actual. */
    private function existing(string $target, int $maxBytes): ?array
    {
        $store = $this->storage->disk();

        if (! $store->exists($target)) {
            return null;
        }

        $size = (int) $store->size($target);

        return $size > 0 && $size <= $maxBytes
            ? ['disk' => $this->storage->diskName(), 'path' => $target, 'size' => $size, 'mime' => 'image/jpeg', 'derived' => true]
            : null;
    }

    /** JPEG que cabe en `$maxBytes`, o null si no se logra. */
    private function shrink(string $original, int $maxBytes): ?string
    {
        $info = $original === '' ? false : @getimagesizefromstring($original);

        // Antes de descodificar: tope de pixeles y memoria libre (descontados los bytes del propio fichero). Un error fatal por
        // memoria no se puede capturar y tumbaria el job.
        if ($info === false || self::tooLargeToShrink((int) $info[0], (int) $info[1], max(0, self::freeMemory() - strlen($original)))) {
            return null;
        }

        $orientation = $info[2] === IMAGETYPE_JPEG ? self::exifOrientation($original) : 1;
        $source = @imagecreatefromstring($original);
        unset($original);

        if ($source === false) {
            return null;
        }

        $source = $this->orient($source, $orientation);

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            while (true) {
                $canvas = $this->flatten($source, $width, $height);
                try {
                    foreach (self::QUALITIES as $quality) {
                        ob_start();
                        imagejpeg($canvas, null, $quality);
                        $jpeg = (string) ob_get_clean();

                        if (strlen($jpeg) <= $maxBytes) {
                            return $jpeg;
                        }
                    }
                } finally {
                    imagedestroy($canvas);
                }

                $width = (int) floor($width * 0.85);
                $height = (int) floor($height * 0.85);
                if (max($width, $height) < self::MIN_LONG_SIDE) {
                    return null;
                }
            }
        } finally {
            imagedestroy($source);
        }
    }

    /** Aplica la orientacion EXIF (1-8): la copia ya no lleva EXIF, asi que los pixeles tienen que quedar derechos. */
    private function orient(GdImage $image, int $orientation): GdImage
    {
        // Tabla EXIF: 2 espejo H; 3 giro 180; 4 espejo V; 5 transpuesta (espejo H + 270 CW); 6 giro 90 CW; 7 transversa (espejo H + 90 CW);
        // 8 giro 270 CW. `imagerotate` gira en sentido antihorario, de ahi los signos.
        $flip = match ($orientation) {
            2, 5, 7 => IMG_FLIP_HORIZONTAL,
            4 => IMG_FLIP_VERTICAL,
            default => null,
        };
        $angle = match ($orientation) {
            3 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };

        if ($flip !== null) {
            imageflip($image, $flip);
        }

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated !== false) {
                imagedestroy($image);
                $image = $rotated;
            }
        }

        return $image;
    }

    /** Copia a `$width` x `$height` sobre fondo blanco (JPEG no tiene transparencia). */
    private function flatten(GdImage $source, int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $canvas;
    }
}
