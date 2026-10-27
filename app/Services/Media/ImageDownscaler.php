<?php

namespace App\Services\Media;

use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Models\MediaAsset;
use App\Services\MediaStorage;
use GdImage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Recompresion de imagenes por encima del tope de una red (Bluesky: `social.bluesky.image_max_bytes`, 2 MB). Crea una COPIA
 * derivada JPEG en el mismo disco, cacheada por media, tope y version de las reglas de media; la original nunca se toca.
 *
 * Motor principal: `ffmpeg` (binario `media.ffmpeg`, como `ffprobe` en MediaProbe). Descodifica y reduce fuera de PHP con poca memoria
 * (una foto de 24 MP cabe en un proceso PHP de 256 MB porque PHP solo copia ficheros por flujo) y aplica la orientacion EXIF con
 * `autorotate`. Sin ffmpeg se recurre a GD, que carga la imagen entera: ahi se comprueba la memoria libre y, si no alcanza, se falla con
 * un mensaje claro.
 *
 * Estrategia: lado largo inicial de 4096 px como mucho y calidad decreciente; si no basta, se reduce el lado largo un 20 % por pasada
 * hasta un minimo de 400 px (o hasta agotar el presupuesto de tiempo). Si no baja del tope devuelve null. Un PNG con transparencia se
 * aplana sobre blanco.
 *
 * NUNCA corre dentro de una peticion web: lo usa el publicador (cola); la validacion en vivo solo mira el tope de pixeles
 * (`exceedsPixelCap()`). La generacion de cada copia va bajo un bloqueo por media para que no se hagan varias a la vez.
 */
class ImageDownscaler
{
    private const QUALITIES = [85, 75, 65, 55];

    /** Calidades de ffmpeg (`-q:v`, 2 = maxima .. 31 = minima), equivalentes a las de GD. */
    private const FFMPEG_QUALITIES = [4, 7, 11, 16];

    /** Lado largo maximo de partida: Bluesky no muestra mas y menos pixeles significa menos pasadas. */
    private const START_LONG_SIDE = 4096;

    private const MIN_LONG_SIDE = 400;

    /** Tope de pixeles que se descodifican (evita una bomba de descompresion: 100 MP ~ 400 MB de RAM). */
    public const MAX_PIXELS = 50_000_000;

    /** Memoria que necesita GD por pixel en el peor momento (origen + lienzo + giro a 4 B cada uno, con margen). */
    public const BYTES_PER_PIXEL = 16;

    private static ?bool $ffmpegOk = null;

    private static ?string $ffmpegChecked = null;

    public function __construct(private readonly MediaStorage $storage) {}

    /**
     * Ruta de la copia que cabe en `$maxBytes`, creandola si hace falta. Null si la imagen no se puede reducir hasta el tope.
     *
     * @return array{disk: string, path: string, size: int, mime: string, derived: bool}|null
     *
     * @throws TransientPublishException si se agota la espera del bloqueo (otro proceso la esta reduciendo)
     * @throws PermanentPublishException sin ffmpeg y sin memoria para GD
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

                $size = $this->shrinkToDisk($path, $target, $maxBytes, $id);

                return $size === null
                    ? null
                    : ['disk' => $this->storage->diskName(), 'path' => $target, 'size' => $size, 'mime' => 'image/jpeg', 'derived' => true];
            });
        } catch (LockTimeoutException) {
            // No es un fallo de la imagen: otro proceso la esta reduciendo. La cola lo reintenta y encuentra la copia hecha.
            throw new TransientPublishException('Otra publicacion esta reduciendo esta imagen: se reintentara en unos minutos.', 'media_ocupada');
        }
    }

    /** Ruta de la copia derivada: por media, tope y version de las reglas. */
    public function derivedPath(int $id, int $maxBytes): string
    {
        return 'derived/images/'.$id.'-'.$maxBytes.'-v'.preg_replace('/[^0-9A-Za-z]/', '', (string) config('media_specs.version')).'.jpg';
    }

    /**
     * ¿Supera el tope de pixeles que se descodifican (bomba de descompresion)? Solo mira dimensiones y NO depende de la memoria del
     * proceso (el de la peticion web no es el del worker). Con dimensiones desconocidas devuelve false.
     */
    public static function exceedsPixelCap(?int $width, ?int $height): bool
    {
        return (bool) ($width && $height && $width * $height > self::MAX_PIXELS);
    }

    /** ¿Cabe en la memoria libre de ESTE proceso cargarla entera con GD? Solo para el respaldo sin ffmpeg, y se mira en el worker. */
    public static function fitsInMemory(int $width, int $height, ?int $freeBytes = null): bool
    {
        return $width * $height * self::BYTES_PER_PIXEL <= ($freeBytes ?? self::freeMemory());
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

    /**
     * Orientacion EXIF (1-8) de un JPEG; 1 si no tiene o no se puede leer. Lector propio de la cabecera APP1/TIFF: no hace falta
     * `ext-exif`.
     */
    public static function exifOrientation(string $bytes): int
    {
        if (! str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }

        $pos = 2;
        $len = min(strlen($bytes), 262_144); // el EXIF vive al principio del fichero
        while ($pos + 4 <= $len && $bytes[$pos] === "\xFF") {
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $size = (int) unpack('n', substr($bytes, $pos + 2, 2))[1];
            if ($marker === 0xE1 && substr($bytes, $pos + 4, 6) === "Exif\x00\x00") {
                return self::tiffOrientation(substr($bytes, $pos + 10, max(0, $size - 8)));
            }
            $pos += 2 + $size;
        }

        return 1;
    }

    /** Un giro de 90 grados (orientaciones 5-8) intercambia ancho y alto. */
    public static function swapsAxes(int $orientation): bool
    {
        return $orientation >= 5 && $orientation <= 8;
    }

    private static function tiffOrientation(string $tiff): int
    {
        $order = substr($tiff, 0, 2);
        if (strlen($tiff) < 8 || ($order !== 'II' && $order !== 'MM')) {
            return 1;
        }
        $u16 = fn (int $o): int => $o + 2 <= strlen($tiff) ? (int) unpack($order === 'II' ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
        $u32 = fn (int $o): int => $o + 4 <= strlen($tiff) ? (int) unpack($order === 'II' ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;

        $ifd = $u32(4);
        $count = min($u16($ifd), 64);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
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

    /**
     * Genera la copia en `$target` y devuelve su tamano, o null si no se logra. El original se copia a un temporal POR FLUJO: PHP nunca
     * lo tiene entero en memoria (salvo en el respaldo GD).
     */
    private function shrinkToDisk(string $path, string $target, int $maxBytes, int $id): ?int
    {
        $store = $this->storage->disk();
        $dir = sys_get_temp_dir();
        $tmpIn = tempnam($dir, 'dsin');
        $tmpOut = tempnam($dir, 'dsout');
        if ($tmpIn === false || $tmpOut === false) {
            return null;
        }
        $created = [$tmpIn, $tmpOut];

        try {
            $in = $store->readStream($path);
            if (! is_resource($in)) {
                return null;
            }
            $out = fopen($tmpIn, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $info = @getimagesize($tmpIn);
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            if ($info === false || ! isset($types[$info[2]]) || self::exceedsPixelCap((int) $info[0], (int) $info[1])) {
                return null;
            }

            // El decodificador se fuerza por el tipo real (no por el nombre que traiga el usuario).
            $named = $tmpIn.'.'.$types[$info[2]];
            rename($tmpIn, $named);
            $created[0] = $named;

            $done = $this->ffmpegAvailable()
                ? $this->shrinkWithFfmpeg($named, (int) $info[2], max((int) $info[0], (int) $info[1]), $tmpOut, $maxBytes)
                : $this->shrinkWithGd($named, $info, $tmpOut, $maxBytes);

            if (! $done) {
                return null;
            }

            $size = (int) filesize($tmpOut);
            $stream = fopen($tmpOut, 'rb');
            try {
                $store->put($target, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return $size;
        } catch (PermanentPublishException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('media.downscale_failed', ['media_id' => $id, 'exception' => $e::class]);

            return null;
        } finally {
            foreach ($created as $file) {
                @unlink($file);
            }
        }
    }

    /** ¿Hay un ffmpeg ejecutable? Se comprueba una vez por proceso y binario configurado. */
    private function ffmpegAvailable(): bool
    {
        $binary = (string) config('media.ffmpeg', 'ffmpeg');
        if (self::$ffmpegChecked === $binary && self::$ffmpegOk !== null) {
            return self::$ffmpegOk;
        }
        self::$ffmpegChecked = $binary;

        $found = str_contains($binary, '/') || str_contains($binary, '\\') ? is_file($binary) : (new ExecutableFinder)->find($binary) !== null;
        if ($found) {
            try {
                $probe = new Process([$binary, '-version']);
                $probe->setTimeout(10);
                $probe->run();
                $found = $probe->isSuccessful();
            } catch (Throwable) {
                $found = false;
            }
        }

        return self::$ffmpegOk = $found;
    }

    /** Reduce con ffmpeg: calidades decrecientes y, si no basta, menos pixeles. La orientacion EXIF la aplica `autorotate`. */
    private function shrinkWithFfmpeg(string $in, int $type, int $longSide, string $out, int $maxBytes): bool
    {
        $decoder = [IMAGETYPE_JPEG => 'mjpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$type];
        $alpha = $type !== IMAGETYPE_JPEG;
        $limit = min($longSide, self::START_LONG_SIDE);
        $deadline = microtime(true) + (float) config('media.downscale_budget_seconds', 90);

        while (true) {
            // Lado largo = $limit (sea cual sea la orientacion final), sin ampliar y con medidas pares.
            $scale = "scale='if(gt(iw,ih),min($limit,iw),-2)':'if(gt(iw,ih),-2,min($limit,ih))':flags=lanczos";
            // JPEG no tiene transparencia: la imagen se pega sobre un fondo blanco del mismo tamano.
            $filter = $alpha
                ? "$scale,split[a][b];[a]drawbox=x=0:y=0:w=iw:h=ih:color=white:t=fill[bg];[bg][b]overlay=format=auto,format=yuvj420p"
                : "$scale,format=yuvj420p";

            foreach (self::FFMPEG_QUALITIES as $quality) {
                if (microtime(true) > $deadline) {
                    return false;
                }

                $process = new Process([
                    (string) config('media.ffmpeg', 'ffmpeg'), '-nostdin', '-v', 'error', '-y', '-threads', '1',
                    '-protocol_whitelist', 'file', '-f', 'image2', '-c:v', $decoder, '-i', $in,
                    '-frames:v', '1', '-vf', $filter, '-c:v', 'mjpeg', '-q:v', (string) $quality, '-f', 'image2', '-update', '1', $out,
                ]);
                $process->setTimeout((float) config('media.ffmpeg_timeout', 60));
                $process->run();

                if (! $process->isSuccessful()) {
                    Log::warning('media.downscale_ffmpeg_failed', ['exit' => $process->getExitCode()]);

                    return false;
                }

                clearstatcache(true, $out);
                if (filesize($out) > 0 && filesize($out) <= $maxBytes) {
                    return true;
                }
            }

            $limit = (int) floor($limit * 0.8);
            if ($limit < self::MIN_LONG_SIDE) {
                return false;
            }
        }
    }

    /**
     * Respaldo sin ffmpeg: carga la imagen entera con GD. Antes de decodificar se comprueba la memoria libre (un error fatal de GD no
     * se puede capturar y tumbaria el job).
     *
     * @param  array<int, mixed>  $info
     */
    private function shrinkWithGd(string $file, array $info, string $out, int $maxBytes): bool
    {
        if (! self::fitsInMemory((int) $info[0], (int) $info[1], max(0, self::freeMemory() - (int) filesize($file)))) {
            throw new PermanentPublishException('La imagen es demasiado grande para reducirla en este servidor (instala ffmpeg o usa una imagen mas pequena).', 'archivo_grande');
        }

        $original = (string) file_get_contents($file);
        $orientation = $info[2] === IMAGETYPE_JPEG ? self::exifOrientation($original) : 1;
        $source = @imagecreatefromstring($original);
        unset($original);

        if ($source === false) {
            return false;
        }

        $source = $this->orient($source, $orientation);

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            while (true) {
                $canvas = $this->flatten($source, $width, $height);
                try {
                    foreach (self::QUALITIES as $quality) {
                        imagejpeg($canvas, $out, $quality);
                        clearstatcache(true, $out);

                        if (filesize($out) <= $maxBytes) {
                            return true;
                        }
                    }
                } finally {
                    imagedestroy($canvas);
                }

                $width = (int) floor($width * 0.85);
                $height = (int) floor($height * 0.85);
                if (max($width, $height) < self::MIN_LONG_SIDE) {
                    return false;
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
