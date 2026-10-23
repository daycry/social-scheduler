<?php

namespace App\Connectors\Support;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\TransientPublishException;
use Aws\Exception\AwsException;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
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

        // Tamano real del fichero (no el declarado): el protocolo de subida lo exige exacto. UNA sola consulta (HeadObject en
        // S3/R2): `size()` ya falla si el fichero no existe, no hace falta un `exists()` previo (revision F4-5 i2 N4).
        try {
            $size = (int) $disk->size($media->path);
        } catch (FilesystemException $e) {
            $previous = $e->getPrevious();

            // Un fallo del almacenamiento que no sea «no existe» (5xx, red) es pasajero: se reintenta, no se da por perdido.
            if ($previous instanceof AwsException && $previous->getStatusCode() !== null && $previous->getStatusCode() !== 404) {
                throw new TransientPublishException('No se pudo consultar el video en el almacenamiento.', 'lectura_media');
            }

            throw new PermanentPublishException('El archivo de video ya no existe en el almacenamiento.', 'media_invalida');
        }

        return new self($media->disk, $media->path, $size);
    }

    /** Lee `$length` bytes (o los que queden) a partir de `$offset`; cadena vacia si no hay nada que leer. */
    public function chunk(int $offset, int $length, ?float $timeout = null): string
    {
        $stream = $this->stream($offset, $length, $timeout);

        try {
            return $stream->getContents();
        } finally {
            $stream->close();
        }
    }

    /** El trozo `[offset, offset + length)` como flujo (Content-Length conocido). Quien lo usa lo cierra. */
    public function stream(int $offset, int $length, ?float $timeout = null): StreamInterface
    {
        $length = min($length, $this->size - $offset);

        if ($length <= 0) {
            return Utils::streamFor('');
        }

        $disk = Storage::disk($this->disk);

        if ($disk instanceof AwsS3V3Adapter) {
            return $this->ranged($disk, $offset, $length, $timeout);
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

    private function ranged(AwsS3V3Adapter $disk, int $offset, int $length, ?float $timeout): StreamInterface
    {
        $config = $disk->getConfig();
        $root = trim((string) ($config['root'] ?? ''), '/');
        $key = $root === '' ? ltrim($this->path, '/') : $root.'/'.ltrim($this->path, '/');

        $args = [
            'Bucket' => $config['bucket'],
            'Key' => $key,
            'Range' => 'bytes='.$offset.'-'.($offset + $length - 1),
        ];

        // Opciones HTTP POR PETICION: el timeout del disco (r2 `timeout` 600 s) no sirve dentro de una pasada de ~100 s.
        if ($timeout !== null) {
            $args['@http'] = ['timeout' => $timeout, 'connect_timeout' => min($timeout, 5.0)];
        }

        try {
            $result = $disk->getClient()->getObject($args);
        } catch (AwsException $e) {
            // Vencido el tiempo o cortada la lectura: pasajero (el siguiente sondeo continua), nunca fallo definitivo.
            throw new TransientPublishException('No se pudo leer el video del almacenamiento.', 'lectura_media');
        }

        return $result['Body'];
    }
}
