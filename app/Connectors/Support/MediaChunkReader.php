<?php

namespace App\Connectors\Support;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Exceptions\PermanentPublishException;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;

/**
 * Lee un fichero del disco de media (R2 en produccion) POR TROZOS: nunca carga el video entero en memoria.
 *
 *  - Discos S3/R2: cada trozo es UN `GetObject` con cabecera `Range` (solo viajan los bytes pedidos; una continuacion en el
 *    byte 900 MB no re-descarga los 900 MB anteriores).
 *  - Discos locales: `fopen` + `fseek` sobre el trozo.
 *  - `stream()` entrega el trozo como flujo (cuerpo de la peticion HTTP sin cargarlo en memoria); `chunk()` como cadena.
 */
class MediaChunkReader
{
    private function __construct(private readonly string $disk, private readonly string $path, public readonly int $size) {}

    public static function for(PublishMedia $media): self
    {
        if ($media->disk === null || $media->path === null) {
            throw new PermanentPublishException('No se encuentra el archivo de video en el almacenamiento.', 'media_invalida');
        }

        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path)) {
            throw new PermanentPublishException('El archivo de video ya no existe en el almacenamiento.', 'media_invalida');
        }

        // Tamano real del fichero (no el declarado): el protocolo de subida lo exige exacto.
        return new self($media->disk, $media->path, (int) $disk->size($media->path));
    }

    /** Lee `$length` bytes (o los que queden) a partir de `$offset`; cadena vacia si no hay nada que leer. */
    public function chunk(int $offset, int $length): string
    {
        $stream = $this->stream($offset, $length);

        try {
            return $stream->getContents();
        } finally {
            $stream->close();
        }
    }

    /** El trozo `[offset, offset + length)` como flujo (Content-Length conocido). Quien lo usa lo cierra. */
    public function stream(int $offset, int $length): StreamInterface
    {
        $length = min($length, $this->size - $offset);

        if ($length <= 0) {
            return Utils::streamFor('');
        }

        $disk = Storage::disk($this->disk);

        if ($disk instanceof AwsS3V3Adapter) {
            return $this->ranged($disk, $offset, $length);
        }

        $resource = $disk->readStream($this->path);

        if (! is_resource($resource)) {
            throw new PermanentPublishException('No se pudo leer el archivo de video del almacenamiento.', 'media_invalida');
        }

        $meta = stream_get_meta_data($resource);

        if ($meta['seekable'] ?? false) {
            return new LimitStream(Utils::streamFor($resource), $length, $offset); // LimitStream se posiciona en `$offset`
        }

        // Flujo no rebobinable: se descarta hasta el desplazamiento y se entrega solo el trozo.
        $skipped = 0;
        while ($skipped < $offset && ! feof($resource)) {
            $piece = fread($resource, min(1024 * 1024, $offset - $skipped));

            if ($piece === false || $piece === '') {
                break;
            }

            $skipped += strlen($piece);
        }

        $data = $skipped === $offset ? (string) stream_get_contents($resource, $length) : '';
        fclose($resource);

        return Utils::streamFor($data);
    }

    /** No hay lecturas persistentes (cada trozo abre y cierra la suya); se mantiene por compatibilidad de uso. */
    public function close(): void {}

    private function ranged(AwsS3V3Adapter $disk, int $offset, int $length): StreamInterface
    {
        $config = $disk->getConfig();
        $root = trim((string) ($config['root'] ?? ''), '/');
        $key = $root === '' ? ltrim($this->path, '/') : $root.'/'.ltrim($this->path, '/');

        $result = $disk->getClient()->getObject([
            'Bucket' => $config['bucket'],
            'Key' => $key,
            'Range' => 'bytes='.$offset.'-'.($offset + $length - 1),
        ]);

        return $result['Body'];
    }
}
