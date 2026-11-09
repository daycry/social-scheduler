<?php

namespace App\Services\Studio;

use App\Models\MediaAsset;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvWorkspace;
use Aws\Exception\AwsException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StudioAvTransfer
{
    private const PART_BYTES = 16 * 1024 ** 2;

    public function __construct(private readonly StudioAvState $state, private readonly StudioAvStorage $storage) {}

    public function run(StudioAvLease $lease, ?float $deadline = null): ?array
    {
        $deadline = min($deadline ?? microtime(true) + 85, microtime(true) + 85);
        $this->remaining($deadline);

        return $this->storage->locked($lease->workspace_id, function (StudioAvWorkspace $workspace) use ($lease, $deadline) {
            $export = $this->state->workingExport($lease);
            $source = $this->state->checkpoints($export, ['assemble'])->sole();
            $proof = $source->result;
            if (($proof['chunk_size'] ?? null) !== self::PART_BYTES || ! is_array($proof['chunk_sha256'] ?? null)
                || ! array_is_list($proof['chunk_sha256']) || count($proof['chunk_sha256']) !== (int) ceil($proof['size'] / self::PART_BYTES)
                || count($proof['chunk_sha256']) > 512) {
                throw new StudioAvCheckpointCorrupt('El render necesita un manifiesto de integridad por partes.');
            }
            foreach ($proof['chunk_sha256'] as $hash) {
                if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    throw new StudioAvCheckpointCorrupt('El manifiesto de integridad está incompleto.');
                }
            }
            $media = $this->state->allocateRegistration($lease);
            $disk = Storage::disk($media->disk);
            $location = $disk instanceof AwsS3V3Adapter
                ? ['driver' => 's3', 'bucket' => $disk->getConfig()['bucket'], 'key' => $disk->path($media->path), 'endpoint' => (string) $disk->getClient()->getEndpoint()]
                : ['driver' => 'local', 'root' => rtrim($disk->path(''), '/\\')];
            $this->state->bindRegistrationLocation($lease, $location);
            if ($disk instanceof AwsS3V3Adapter) {
                return $this->remote($lease, $workspace, $source, $media, $disk, $deadline);
            }

            return $this->local($lease, $workspace, $source, $media, $deadline);
        });
    }

    private function remote(StudioAvLease $lease, StudioAvWorkspace $workspace, StudioAvLease $source, MediaAsset $media, AwsS3V3Adapter $disk, float $deadline): ?array
    {
        $journal = $media->import_state;
        $client = $disk->getClient();
        $address = ['Bucket' => $disk->getConfig()['bucket'], 'Key' => $disk->path($media->path)];
        $head = null;
        if (! $media->upload_id || $journal['bytes'] === $journal['total']) {
            try {
                $head = $client->headObject($address + $this->http($deadline))->toArray();
            } catch (AwsException $error) {
                if ($error->getStatusCode() !== 404) {
                    throw $error;
                }
            }
        }
        if ($head !== null) {
            $this->assertHead($media, $head);
            if (($journal['stage'] ?? '') !== 'verifying' && ! $journal['complete']) {
                $this->save($lease, $media, ['bytes' => $journal['total'], 'stage' => 'verifying', 'object_etag' => $head['ETag']], deadline: $deadline);
                $journal = $media->import_state;
            }
        } elseif (in_array($journal['stage'] ?? '', ['verifying', 'done'], true) || $journal['complete']) {
            throw new StudioAvCheckpointCorrupt('El objeto final verificado ya no existe.');
        } elseif (! $media->upload_id) {
            // A creation response may have been lost. Reuse the upload with this exact generated key.
            $this->save($lease, $media, ['stage' => 'creating'], deadline: $deadline);
            $listed = $client->listMultipartUploads(['Bucket' => $address['Bucket'], 'Prefix' => $address['Key'], 'MaxUploads' => 10] + $this->http($deadline));
            $matches = array_values(array_filter($listed['Uploads'] ?? [], fn ($upload) => ($upload['Key'] ?? null) === $address['Key']));
            if (($listed['IsTruncated'] ?? false) || count($matches) > 1) {
                throw new RuntimeException('La recuperación de multipart necesita limpiar asignaciones adicionales.');
            }
            $id = $matches[0]['UploadId'] ?? null;
            if ($id === null) {
                $created = $client->createMultipartUpload($address + ['ContentType' => $media->mime,
                    'Metadata' => ['studio-sha256' => $journal['sha256'], 'studio-workspace' => $journal['studio_av']]] + $this->http($deadline));
                $id = $created['UploadId'];
            }
            $this->save($lease, $media, ['stage' => 'copying'], $id, deadline: $deadline);

            return null;
        }
        if (($journal['stage'] ?? '') !== 'verifying' && ! $journal['complete']) {
            if ($journal['bytes'] < $journal['total']) {
                $bytes = $this->sourcePart($workspace, $source, $journal['bytes'], $deadline);
                $number = intdiv($journal['bytes'], self::PART_BYTES) + 1;
                $uploaded = $client->uploadPart($address + ['UploadId' => $media->upload_id, 'PartNumber' => $number,
                    'Body' => $bytes, 'ContentLength' => strlen($bytes), 'ContentMD5' => base64_encode(md5($bytes, true))] + $this->http($deadline));
                $etag = $uploaded['ETag'];
                if (! is_string($etag) || $etag === '' || strlen($etag) > 256) {
                    throw new RuntimeException('El almacenamiento no confirmó la parte subida.');
                }
                $parts = $journal['parts'];
                $parts[] = ['PartNumber' => $number, 'ETag' => $etag];
                $this->save($lease, $media, ['bytes' => $journal['bytes'] + strlen($bytes), 'parts' => $parts], $media->upload_id, deadline: $deadline);

                return null;
            }
            if (count($journal['parts']) !== count($source->result['chunk_sha256'])) {
                throw new StudioAvCheckpointCorrupt('Faltan recibos de partes para completar la transferencia.');
            }
            $this->save($lease, $media, ['stage' => 'completing'], $media->upload_id, deadline: $deadline);
            $client->completeMultipartUpload($address + ['UploadId' => $media->upload_id, 'MultipartUpload' => ['Parts' => $journal['parts']]] + $this->http($deadline));
            $head = $client->headObject($address + $this->http($deadline))->toArray();
            $this->assertHead($media, $head);
            $this->save($lease, $media, ['stage' => 'verifying', 'object_etag' => $head['ETag']], deadline: $deadline);
            $journal = $media->import_state;
        }
        if ($head === null) {
            throw new StudioAvCheckpointCorrupt('Falta el objeto final de la transferencia.');
        }
        if ($head['ETag'] !== ($journal['object_etag'] ?? null)) {
            throw new StudioAvCheckpointCorrupt('El objeto cambió durante la verificación.');
        }
        if ($journal['verified_bytes'] < $journal['total']) {
            $start = $journal['verified_bytes'];
            $length = min(self::PART_BYTES, $journal['total'] - $start);
            $result = $client->getObject($address + ['Range' => 'bytes='.$start.'-'.($start + $length - 1),
                'IfMatch' => $head['ETag']] + $this->http($deadline, streamed: true));
            $body = $result['Body'];
            try {
                if ((int) $result['ContentLength'] !== $length || $result['ContentRange'] !== 'bytes '.$start.'-'.($start + $length - 1).'/'.$journal['total']) {
                    throw new StudioAvCheckpointCorrupt('El almacenamiento no respetó el rango solicitado.');
                }
                $bytes = '';
                while (strlen($bytes) < $length && ! $body->eof()) {
                    $this->remaining($deadline);
                    $piece = $body->read(min(1024 ** 2, $length - strlen($bytes)));
                    if ($piece === '') {
                        throw new RuntimeException('La lectura remota se interrumpió.');
                    }
                    $bytes .= $piece;
                }
                $this->remaining($deadline);
                if (strlen($bytes) !== $length) {
                    throw new StudioAvCheckpointCorrupt('La parte remota está incompleta.');
                }
                $this->assertPart($source, $start, $bytes);
            } finally {
                $body->close();
            }
            $this->save($lease, $media, ['verified_bytes' => $start + $length], deadline: $deadline);
            $journal = $media->import_state;
        }
        if ($journal['verified_bytes'] !== $journal['total']) {
            return null;
        }
        $this->save($lease, $media, ['stage' => 'done', 'complete' => true], deadline: $deadline);

        return ['media_id' => $media->id];
    }

    private function assertHead(MediaAsset $media, array $head): void
    {
        $journal = $media->import_state;
        if ((int) ($head['ContentLength'] ?? 0) !== $journal['total'] || ($head['Metadata']['studio-sha256'] ?? null) !== $journal['sha256']
            || ($head['Metadata']['studio-workspace'] ?? null) !== $journal['studio_av'] || ! is_string($head['ETag'] ?? null) || $head['ETag'] === '') {
            throw new StudioAvCheckpointCorrupt('El objeto final no coincide con la asignación privada.');
        }
    }

    private function http(float $deadline, bool $streamed = false): array
    {
        $seconds = min(20, $this->remaining($deadline));

        return ['@retries' => 0, '@http' => ['timeout' => $seconds, 'connect_timeout' => min(5, $seconds)]
            + ($streamed ? ['stream' => true, 'read_timeout' => min(5, $seconds)] : [])];
    }

    private function local(StudioAvLease $lease, StudioAvWorkspace $workspace, StudioAvLease $source, MediaAsset $media, float $deadline): ?array
    {
        $journal = $media->import_state;
        $part = $this->localPath($media, true);
        $final = $this->localPath($media, false);
        if ((in_array($journal['stage'] ?? '', ['verifying', 'done'], true) || $journal['complete'])
            && (! is_file($final) || filesize($final) !== $journal['total'])) {
            throw new StudioAvCheckpointCorrupt('La copia final verificada ya no está completa.');
        }
        if ($journal['bytes'] < $journal['total']) {
            if (file_exists($final)) {
                throw new StudioAvCheckpointCorrupt('El destino final apareció antes de completar su transferencia.');
            }
            $bytes = $this->sourcePart($workspace, $source, $journal['bytes'], $deadline);
            $stream = @fopen($part, 'c+b');
            if ($stream === false) {
                throw new RuntimeException('No se pudo abrir la copia parcial privada.');
            }
            try {
                // The durable journal wins over an unacknowledged tail after a worker crash.
                if (fstat($stream)['size'] < $journal['bytes'] || ! ftruncate($stream, $journal['bytes']) || fseek($stream, $journal['bytes']) !== 0) {
                    throw new StudioAvCheckpointCorrupt('La copia parcial perdió bytes ya registrados.');
                }
                for ($offset = 0; $offset < strlen($bytes);) {
                    $this->remaining($deadline);
                    $written = fwrite($stream, substr($bytes, $offset, min(1024 ** 2, strlen($bytes) - $offset)));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('La copia parcial no pudo escribirse.');
                    }
                    $offset += $written;
                }
                if (! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
                    throw new RuntimeException('No se pudo confirmar la copia parcial en disco.');
                }
            } finally {
                fclose($stream);
            }
            $this->save($lease, $media, ['bytes' => $journal['bytes'] + strlen($bytes), 'stage' => 'copying'], deadline: $deadline);

            return null;
        }
        if (($journal['stage'] ?? '') !== 'verifying' && ! $journal['complete']) {
            if (! file_exists($final) && ! @link($part, $final) && ! file_exists($final)) {
                throw new RuntimeException('No se pudo publicar la copia privada de biblioteca.');
            }
            $this->save($lease, $media, ['stage' => 'verifying'], deadline: $deadline);

            return null;
        }
        if ($journal['verified_bytes'] < $journal['total']) {
            $bytes = $this->readLocal($final, $journal['verified_bytes'], $journal['total'], $deadline);
            $this->assertPart($source, $journal['verified_bytes'], $bytes);
            $this->save($lease, $media, ['verified_bytes' => $journal['verified_bytes'] + strlen($bytes)], deadline: $deadline);
            $journal = $media->import_state;
        }
        if ($journal['verified_bytes'] !== $journal['total']) {
            return null;
        }
        if (file_exists($part) && ! @unlink($part)) {
            throw new RuntimeException('La retirada del parcial de biblioteca sigue pendiente.');
        }
        $this->save($lease, $media, ['stage' => 'done', 'complete' => true], deadline: $deadline);

        return ['media_id' => $media->id];
    }

    private function sourcePart(StudioAvWorkspace $workspace, StudioAvLease $source, int $offset, float $deadline): string
    {
        $path = $this->storage->checkedPath($workspace, $source->result['file']);
        $bytes = $this->readLocal($path, $offset, $source->result['size'], $deadline);
        $this->assertPart($source, $offset, $bytes);

        return $bytes;
    }

    private function readLocal(string $path, int $offset, int $total, float $deadline): string
    {
        $this->remaining($deadline);
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new StudioAvCheckpointCorrupt('Falta un archivo privado de la transferencia.');
        }
        try {
            $length = min(self::PART_BYTES, $total - $offset);
            if (fstat($stream)['size'] !== $total || $offset < 0 || $offset % self::PART_BYTES !== 0 || $length < 1 || fseek($stream, $offset) !== 0) {
                throw new StudioAvCheckpointCorrupt('El archivo no coincide con el tamaño o desplazamiento registrado.');
            }
            $bytes = stream_get_contents($stream, $length);
            $this->remaining($deadline);
            if ($bytes === false || strlen($bytes) !== $length) {
                throw new StudioAvCheckpointCorrupt('La lectura de la parte quedó incompleta.');
            }

            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    private function assertPart(StudioAvLease $source, int $offset, string $bytes): void
    {
        $expected = $source->result['chunk_sha256'][intdiv($offset, self::PART_BYTES)] ?? '';
        if (! hash_equals($expected, hash('sha256', $bytes))) {
            throw new StudioAvCheckpointCorrupt('La parte no coincide con el hash del render aceptado.');
        }
    }

    private function save(StudioAvLease $lease, MediaAsset $media, array $progress, ?string $uploadId = null, float $deadline = 0): void
    {
        $this->remaining($deadline);
        if (! $this->state->checkpointRegistration($lease, $progress, $uploadId, $deadline)) {
            throw (new ModelNotFoundException)->setModel(StudioAvLease::class, [$lease->id]);
        }
        $media->refresh();
    }

    private function remaining(float $deadline): float
    {
        $seconds = $deadline - microtime(true);
        if (! is_finite($seconds) || $seconds <= 0) {
            throw new StudioAvProcessFailed('phase_timeout');
        }

        return $seconds;
    }

    private function localPath(MediaAsset $media, bool $part): string
    {
        $root = rtrim(Storage::disk($media->disk)->path(''), '/\\');
        if (is_link($root) || ! is_dir($root) || ($path = realpath($root)) === false) {
            throw new RuntimeException('La raíz privada de biblioteca no está disponible.');
        }
        $pieces = explode('/', $media->path.($part ? '.part' : ''));
        foreach ($pieces as $index => $piece) {
            if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $piece) || in_array($piece, ['.', '..'], true)) {
                throw new RuntimeException('El destino de biblioteca no es válido.');
            }
            $path .= DIRECTORY_SEPARATOR.$piece;
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new RuntimeException('La copia no admite enlaces de archivos o directorios.');
            }
            if ($index < count($pieces) - 1 && ! is_dir($path) && ! @mkdir($path, 0700) && ! is_dir($path)) {
                throw new RuntimeException('No se pudo crear el directorio privado de biblioteca.');
            }
        }

        return $path;
    }
}
