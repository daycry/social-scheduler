<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Subida de media. En produccion (R2/S3) el navegador sube directamente con URLs prefirmadas (un PUT
 * o multipart para videos grandes): el binario nunca pasa por PHP-FPM. En dev/test (disco local) la
 * subida se hace contra una ruta de la app (`mode: local`).
 */
class MediaUploadService
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaProbe $probe,
    ) {}

    /**
     * Registra el asset y devuelve como subirlo.
     *
     * @return array{asset: MediaAsset, upload: array<string, mixed>}
     */
    public function initiate(User $user, string $name, string $mime, int $size): array
    {
        $kind = $this->kindFor($mime);

        if ($size < 1 || $size > (int) config("media.max_bytes.$kind")) {
            throw new InvalidArgumentException('El fichero supera el tamano maximo permitido para '.($kind === 'video' ? 'videos' : 'imagenes').'.');
        }

        $asset = new MediaAsset([
            'disk' => $this->storage->diskName(),
            'path' => sprintf('media/%d/%s.%s', $user->id, Str::lower((string) Str::ulid()), $this->extensionFor($mime)),
            'original_name' => Str::limit(basename($name), 200, ''),
            'mime' => $mime,
            'kind' => $kind,
            'declared_size' => $size,
            'status' => MediaAsset::STATUS_PENDING,
        ]);
        $asset->user_id = $user->id;

        if (! $this->storage->isS3()) {
            $asset->save();

            return ['asset' => $asset, 'upload' => ['mode' => 'local']];
        }

        $ttl = '+'.(int) config('media.upload_url_ttl', 120).' minutes';
        $client = $this->storage->client();
        $bucket = $this->storage->bucket();

        if ($size >= (int) config('media.multipart_threshold')) {
            $partSize = max(5 * 1024 * 1024, (int) config('media.part_size'));
            $count = (int) ceil($size / $partSize);

            if ($count > 10000) {
                throw new InvalidArgumentException('El fichero es demasiado grande para subirlo.');
            }

            $created = $client->createMultipartUpload(['Bucket' => $bucket, 'Key' => $asset->path, 'ContentType' => $mime]);
            $asset->upload_id = (string) $created['UploadId'];
            $asset->save();

            $parts = [];
            for ($n = 1; $n <= $count; $n++) {
                $cmd = $client->getCommand('UploadPart', ['Bucket' => $bucket, 'Key' => $asset->path, 'UploadId' => $asset->upload_id, 'PartNumber' => $n]);
                $parts[] = ['number' => $n, 'url' => (string) $client->createPresignedRequest($cmd, $ttl)->getUri()];
            }

            return ['asset' => $asset, 'upload' => ['mode' => 'multipart', 'part_size' => $partSize, 'parts' => $parts]];
        }

        $asset->save();
        $cmd = $client->getCommand('PutObject', ['Bucket' => $bucket, 'Key' => $asset->path, 'ContentType' => $mime]);

        return ['asset' => $asset, 'upload' => [
            'mode' => 'single',
            'url' => (string) $client->createPresignedRequest($cmd, $ttl)->getUri(),
            'headers' => ['Content-Type' => $mime],
        ]];
    }

    /**
     * Finaliza la subida (cierra el multipart si lo hay), comprueba el objeto y extrae los metadatos.
     *
     * @param  list<array{number: int, etag: string}>  $parts
     */
    public function complete(MediaAsset $asset, array $parts = []): MediaAsset
    {
        if ($asset->status !== MediaAsset::STATUS_PENDING) {
            return $asset;
        }

        if ($asset->upload_id) {
            if ($parts === []) {
                throw new InvalidArgumentException('Faltan las partes subidas del multipart.');
            }

            usort($parts, fn ($a, $b) => $a['number'] <=> $b['number']);
            $this->storage->client()->completeMultipartUpload([
                'Bucket' => $this->storage->bucket(),
                'Key' => $asset->path,
                'UploadId' => $asset->upload_id,
                'MultipartUpload' => ['Parts' => array_map(fn ($p) => ['PartNumber' => $p['number'], 'ETag' => $p['etag']], $parts)],
            ]);
        }

        return $this->finalize($asset);
    }

    /** Lee el objeto ya subido y rellena tamano y metadatos; deja `invalido` si no es lo declarado. */
    public function finalize(MediaAsset $asset): MediaAsset
    {
        $disk = $this->storage->disk();

        if (! $disk->exists($asset->path)) {
            return $this->invalidate($asset, 'El fichero no llego al almacenamiento.');
        }

        $size = (int) $disk->size($asset->path);

        if ($size > (int) config("media.max_bytes.{$asset->kind}")) {
            $disk->delete($asset->path);

            return $this->invalidate($asset, 'El fichero supera el tamano maximo permitido.');
        }

        try {
            $meta = $this->probe->inspect($asset);
        } catch (RuntimeException $e) {
            $disk->delete($asset->path);

            return $this->invalidate($asset, $e->getMessage());
        }

        $realMime = $meta['mime'];
        unset($meta['mime']);

        if ($realMime !== null && $realMime !== $asset->mime) {
            $disk->delete($asset->path);

            return $this->invalidate($asset, 'El contenido no coincide con el tipo de fichero declarado.');
        }

        $asset->forceFill($meta + ['size' => $size, 'status' => MediaAsset::STATUS_READY, 'upload_id' => null, 'error' => null])->save();

        return $asset;
    }

    /** Borra el fichero y el registro (cancela el multipart pendiente si lo hubiera). */
    public function delete(MediaAsset $asset): void
    {
        if ($asset->upload_id && $this->storage->isS3()) {
            try {
                $this->storage->client()->abortMultipartUpload(['Bucket' => $this->storage->bucket(), 'Key' => $asset->path, 'UploadId' => $asset->upload_id]);
            } catch (\Throwable) {
                // el multipart ya no existe: nada que cancelar
            }
        }

        $this->storage->disk()->delete($asset->path);
        $asset->delete();
    }

    private function invalidate(MediaAsset $asset, string $reason): MediaAsset
    {
        $asset->forceFill(['status' => MediaAsset::STATUS_INVALID, 'error' => $reason, 'upload_id' => null])->save();

        return $asset;
    }

    private function kindFor(string $mime): string
    {
        foreach ((array) config('media.allowed_mimes') as $kind => $mimes) {
            if (in_array($mime, $mimes, true)) {
                return $kind;
            }
        }

        throw new InvalidArgumentException('Tipo de fichero no admitido. Usa JPG, PNG, WebP, MP4 o MOV.');
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/quicktime' => 'mov',
            default => 'mp4',
        };
    }
}
