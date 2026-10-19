<?php

namespace App\Connectors\Support;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Exceptions\PermanentPublishException;
use Illuminate\Support\Facades\Storage;

/**
 * Lee un fichero del disco de media (R2 en produccion) POR TROZOS: nunca carga el video entero en memoria.
 * Mantiene una unica lectura abierta; en memoria solo vive el trozo que se esta enviando.
 */
class MediaChunkReader
{
    /** @var resource|null */
    private $stream = null;

    private int $position = 0;

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

    /** Lee `$length` bytes (o los que queden) a partir de `$offset`. */
    public function chunk(int $offset, int $length): string
    {
        $length = min($length, $this->size - $offset);

        if ($length <= 0) {
            return '';
        }

        $this->seek($offset);
        $data = '';

        while (strlen($data) < $length && ! feof($this->stream)) {
            $piece = fread($this->stream, $length - strlen($data));

            if ($piece === false || $piece === '') {
                break;
            }

            $data .= $piece;
        }

        $this->position = $offset + strlen($data);

        return $data;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
        $this->position = 0;
    }

    private function seek(int $offset): void
    {
        if (is_resource($this->stream) && $this->position === $offset) {
            return;
        }

        $seekable = is_resource($this->stream) && (stream_get_meta_data($this->stream)['seekable'] ?? false);

        if ($seekable && fseek($this->stream, $offset) === 0) {
            $this->position = $offset;

            return;
        }

        // Flujo no rebobinable (algunos adaptadores S3): se reabre y se descarta hasta el desplazamiento.
        $this->close();
        $stream = Storage::disk($this->disk)->readStream($this->path);

        if (! is_resource($stream)) {
            throw new PermanentPublishException('No se pudo leer el archivo de video del almacenamiento.', 'media_invalida');
        }

        $this->stream = $stream;
        $skipped = 0;

        while ($skipped < $offset && ! feof($this->stream)) {
            $piece = fread($this->stream, min(1024 * 1024, $offset - $skipped));

            if ($piece === false || $piece === '') {
                break;
            }

            $skipped += strlen($piece);
        }

        $this->position = $skipped;
    }
}
