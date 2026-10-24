<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
     * Registra un asset `pendiente` que `ImportRemoteMedia` rellenara descargando de una URL o de Drive. El parcial vive en
     * `media/{user}/{ulid}.part` (el tipo real aun no se conoce) y el importador lo renombra al terminar. Necesita disco local.
     *
     * @param  'url'|'drive'  $source
     */
    public function beginImport(User $user, string $source, string $reference, string $name): MediaAsset
    {
        if ($this->storage->isS3()) {
            throw new InvalidArgumentException('La importacion remota necesita el almacenamiento local (MEDIA_DISK=local).');
        }

        $asset = new MediaAsset([
            'disk' => $this->storage->diskName(),
            'path' => sprintf('media/%d/%s.part', $user->id, Str::lower((string) Str::ulid())),
            'original_name' => Str::limit(basename($name) ?: 'importado', 200, ''),
            'mime' => 'application/octet-stream',
            'kind' => MediaAsset::KIND_IMAGE,
            'declared_size' => 0,
            'status' => MediaAsset::STATUS_PENDING,
            'source' => $source,
            'source_ref' => Str::limit($reference, 500, ''),
            'import_state' => ['bytes' => 0, 'total' => null, 'passes' => 0],
        ]);
        $asset->user_id = $user->id;
        $asset->save();

        return $asset;
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

        // La URL prefirmada no puede limitar el tamano del PUT: se comprueba aqui el objeto REAL contra lo declarado
        // (y ya validado contra el maximo al iniciar la subida), para que declarar poco no permita subir mucho (CWE-770).
        if ($size > $asset->declared_size) {
            $disk->delete($asset->path);

            return $this->invalidate($asset, 'El fichero pesa mas de lo declarado al iniciar la subida.');
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

    /**
     * Borra el registro y despues el fichero (cancela el multipart pendiente si lo hubiera). La comprobacion de
     * «sin publicaciones» y el borrado de la fila van en UNA transaccion con la fila bloqueada: una publicacion que
     * adjunte la media a la vez choca con el lock (y con la FK `restrict`) en vez de quedarse sin fichero. Si la
     * fila no se puede borrar, el fichero queda intacto.
     *
     * @throws \DomainException si la media la usa alguna publicacion.
     */
    public function delete(MediaAsset $asset): void
    {
        try {
            DB::transaction(function () use ($asset) {
                $locked = MediaAsset::withoutUserScope()->lockForUpdate()->find($asset->id);

                if ($locked === null) {
                    return; // ya borrada por otra peticion
                }

                if ($locked->posts()->exists()) {
                    throw new \DomainException('El archivo se usa en una publicacion; quitalo de ella antes de borrarlo.');
                }

                $locked->delete();
            });
        } catch (QueryException) {
            throw new \DomainException('El archivo se usa en una publicacion; quitalo de ella antes de borrarlo.');
        }

        $this->removeObject($asset);
    }

    /**
     * Limpieza de subidas abandonadas: assets `pendiente` (o `invalido`) de mas de `$hours` horas que ninguna
     * publicacion usa. Aborta su multipart abierto (R2 factura las partes huerfanas), borra el objeto y la fila.
     *
     * @return int Registros eliminados.
     */
    public function pruneAbandoned(int $hours): int
    {
        $n = 0;

        MediaAsset::withoutUserScope()
            ->whereIn('status', [MediaAsset::STATUS_PENDING, MediaAsset::STATUS_INVALID])
            ->where('created_at', '<', now()->subHours($hours))
            ->whereDoesntHave('posts')
            ->chunkById(100, function ($assets) use (&$n) {
                foreach ($assets as $asset) {
                    try {
                        $this->delete($asset);
                        $n++;
                    } catch (\DomainException) {
                        // se adjunto a una publicacion en el ultimo instante: se conserva
                    }
                }
            });

        return $n;
    }

    /** Aborta el multipart abierto (si lo hay) y borra el objeto; ambos pasos tolerantes a «ya no existe». */
    private function removeObject(MediaAsset $asset): void
    {
        if ($asset->upload_id && $this->storage->isS3()) {
            try {
                $this->storage->client()->abortMultipartUpload(['Bucket' => $this->storage->bucket(), 'Key' => $asset->path, 'UploadId' => $asset->upload_id]);
            } catch (\Throwable) {
                // el multipart ya no existe: nada que cancelar
            }
        }

        $this->storage->disk()->delete($asset->path);
    }

    public function invalidate(MediaAsset $asset, string $reason): MediaAsset
    {
        $asset->forceFill(['status' => MediaAsset::STATUS_INVALID, 'error' => $reason, 'upload_id' => null])->save();

        return $asset;
    }

    public function kindFor(string $mime): string
    {
        foreach ((array) config('media.allowed_mimes') as $kind => $mimes) {
            if (in_array($mime, $mimes, true)) {
                return $kind;
            }
        }

        throw new InvalidArgumentException('Tipo de fichero no admitido. Usa JPG, PNG, WebP, MP4 o MOV.');
    }

    public function extensionFor(string $mime): string
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
