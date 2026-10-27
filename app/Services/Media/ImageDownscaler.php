<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Services\MediaStorage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recompresion de imagenes por encima del tope de una red (Bluesky: `social.bluesky.image_max_bytes`, 2 MB). Crea una COPIA
 * derivada JPEG en el mismo disco, cacheada por media, tope y version de las reglas de media; la original nunca se toca.
 *
 * Estrategia: calidad 85 -> 75 -> 65 -> 55 y, si no basta, se reduce el lado largo un 15 % por pasada hasta un minimo de 400 px.
 * Si no baja del tope devuelve null y el guardado falla con un mensaje claro. Un PNG con transparencia se aplana sobre blanco.
 */
class ImageDownscaler
{
    private const QUALITIES = [85, 75, 65, 55];

    private const MIN_LONG_SIDE = 400;

    /** Tope de pixeles que se descodifican (evita una bomba de descompresion: 100 MP ~ 400 MB de RAM). */
    private const MAX_PIXELS = 50_000_000;

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
        $store = $this->storage->disk();

        // La copia solo se reutiliza si sigue cabiendo en el tope actual (el nombre ya lleva el tope y la version).
        if ($store->exists($target)) {
            $existing = (int) $store->size($target);
            if ($existing > 0 && $existing <= $maxBytes) {
                return ['disk' => $this->storage->diskName(), 'path' => $target, 'size' => $existing, 'mime' => 'image/jpeg', 'derived' => true];
            }
        }

        try {
            $bytes = $this->shrink((string) $store->get($path), $maxBytes);
        } catch (Throwable $e) {
            Log::warning('media.downscale_failed', ['media_id' => $id, 'exception' => $e::class]);

            return null;
        }

        if ($bytes === null) {
            return null;
        }

        $store->put($target, $bytes);

        return ['disk' => $this->storage->diskName(), 'path' => $target, 'size' => strlen($bytes), 'mime' => 'image/jpeg', 'derived' => true];
    }

    /** Ruta de la copia derivada: por media, tope y version de las reglas. */
    public function derivedPath(int $id, int $maxBytes): string
    {
        return 'derived/images/'.$id.'-'.$maxBytes.'-v'.preg_replace('/[^0-9A-Za-z]/', '', (string) config('media_specs.version')).'.jpg';
    }

    /** JPEG que cabe en `$maxBytes`, o null si no se logra. */
    private function shrink(string $original, int $maxBytes): ?string
    {
        $info = $original === '' ? false : @getimagesizefromstring($original);
        if ($info === false || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        $source = @imagecreatefromstring($original);
        if ($source === false) {
            return null;
        }

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

    /** Copia a `$width` x `$height` sobre fondo blanco (JPEG no tiene transparencia). */
    private function flatten(\GdImage $source, int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $canvas;
    }
}
