<?php

namespace App\Services\Studio;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Support\MediaChunkReader;
use App\Models\MediaAsset;
use App\Models\Studio\StudioAsset;
use App\Services\MediaProbe;
use Aws\Exception\AwsException;
use DomainException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** A ready audio file is a private snapshot that has never had a signed upload capability. */
class StudioAudioFiles
{
    public function __construct(private readonly MediaProbe $probe) {}

    public function finalize(MediaAsset $asset): MediaAsset
    {
        if ($asset->isReady() || $asset->status === MediaAsset::STATUS_INVALID) {
            return $asset;
        }
        $disk = Storage::disk($asset->disk);
        $source = $asset->path;
        $size = $this->readSize($asset, $source);
        if ($size === null) {
            return $this->invalid($asset, 'El archivo de audio no llegó al almacenamiento.');
        }
        if (! $this->sizeValid($asset, $size)) {
            $this->removeFile($asset, $source);

            return $this->invalid($asset, 'El tamaño real del audio no coincide con el declarado o supera el límite.');
        }
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        abort_unless(in_array($extension, ['wav', 'mp3', 'm4a', 'aac', 'ogg'], true), 422, 'Extensión de audio no compatible.');
        $snapshot = 'studio-audio/'.$asset->user_id.'/'.$asset->id.'/'.Str::uuid().'.'.$extension;
        // Persist the allocation before I/O so the existing abandoned-upload cleanup owns it after a crash.
        $this->discardSnapshot($asset);
        $asset->forceFill(['import_state' => ['studio_audio_snapshot' => $snapshot]])->save();
        $published = false;
        try {
            try {
                if ($disk instanceof AwsS3V3Adapter) {
                    $config = $disk->getConfig();
                    $prefix = trim((string) ($config['root'] ?? ''), '/');
                    $prefix = $prefix === '' ? '' : $prefix.'/';
                    $disk->getClient()->copyObject(['Bucket' => $config['bucket'], 'Key' => $prefix.$snapshot,
                        'CopySource' => $config['bucket'].'/'.str_replace('%2F', '/', rawurlencode($prefix.$source)),
                        '@retries' => 0, '@http' => ['timeout' => 20, 'connect_timeout' => 5]]);
                } else {
                    abort_unless($disk->copy($source, $snapshot), 503, 'No se pudo preparar el audio.');
                }
            } catch (Throwable) {
                throw new StudioAudioProbeUnavailable('No se pudo preparar la copia privada del audio; vuelve a intentarlo.');
            }
            $size = $this->readSize($asset, $snapshot);
            if ($size === null || ! $this->sizeValid($asset, $size)) {
                $this->removeFile($asset, $source);

                return $this->invalid($asset, 'La copia recibida no coincide con el tamaño declarado.');
            }
            $candidate = clone $asset;
            $candidate->path = $snapshot;
            try {
                $meta = $this->probe->inspect($candidate);
            } catch (StudioAudioProbeUnavailable $e) {
                throw $e;
            } catch (RuntimeException $e) {
                $this->removeFile($asset, $source);

                return $this->invalid($asset, $e->getMessage());
            }
            unset($meta['mime']);
            $result = DB::transaction(function () use ($asset, $snapshot, $source, $size, $meta): MediaAsset {
                $fresh = MediaAsset::withoutUserScope()->where('user_id', $asset->user_id)->lockForUpdate()->findOrFail($asset->id);
                if ($fresh->isReady()) {
                    return $fresh;
                }
                abort_unless($fresh->status === MediaAsset::STATUS_PENDING && $fresh->path === $source, 409, 'La subida cambió durante su validación.');
                $fresh->forceFill($meta + ['path' => $snapshot, 'source_ref' => $source, 'import_state' => null, 'size' => $size,
                    'status' => MediaAsset::STATUS_READY, 'upload_id' => null, 'error' => null])->save();

                return $fresh;
            });
            $published = $result->path === $snapshot;
            try {
                $this->removeFile($asset, $source);
            } catch (Throwable) {
                Log::warning('studio.audio_staging_cleanup_deferred', ['media_id' => $asset->id]);
            }

            return $result;
        } finally {
            if (! $published) {
                $this->discardSnapshot($asset);
            }
        }
    }

    private function sizeValid(MediaAsset $asset, int $size): bool
    {
        return $size > 0 && $size === (int) $asset->declared_size && $size <= min(100 * 1024 * 1024, (int) config('media.max_bytes.audio'));
    }

    public function hasCompleteObject(MediaAsset $asset): bool
    {
        $size = $this->readSize($asset, $asset->path);

        return $size !== null && $this->sizeValid($asset, $size);
    }

    private function readSize(MediaAsset $asset, string $path): ?int
    {
        try {
            return MediaChunkReader::for(new PublishMedia(id: $asset->id, kind: 'audio', mime: $asset->mime, url: '', disk: $asset->disk, path: $path), timeout: 5)->size;
        } catch (PermanentPublishException) {
            return null;
        } catch (Throwable) {
            throw new StudioAudioProbeUnavailable('No se pudo consultar el audio del almacenamiento; vuelve a intentarlo.');
        }
    }

    private function removeFile(MediaAsset $asset, string $path): void
    {
        $disk = Storage::disk($asset->disk);
        try {
            if ($disk instanceof AwsS3V3Adapter) {
                $config = $disk->getConfig();
                $root = trim((string) ($config['root'] ?? ''), '/');
                $disk->getClient()->deleteObject(['Bucket' => $config['bucket'], 'Key' => ($root === '' ? '' : $root.'/').$path,
                    '@retries' => 0, '@http' => ['timeout' => 5, 'connect_timeout' => 5]]);
            } else {
                if (! $disk->delete($path)) {
                    throw new RuntimeException('storage delete failed');
                }
            }
        } catch (Throwable) {
            throw new StudioAudioProbeUnavailable('La limpieza del audio no terminó; vuelve a intentarlo.');
        }
    }

    private function invalid(MediaAsset $asset, string $reason): MediaAsset
    {
        $asset->forceFill(['status' => MediaAsset::STATUS_INVALID, 'upload_id' => null, 'error' => $reason])->save();

        return $asset;
    }

    public function discardSnapshot(MediaAsset $asset): void
    {
        $path = $asset->import_state['studio_audio_snapshot'] ?? null;
        if (! is_string($path) || ! preg_match('~^studio-audio/'.(int) $asset->user_id.'/'.(int) $asset->id.'/[a-f0-9-]{36}\.(wav|mp3|m4a|aac|ogg)$~D', $path)) {
            return;
        }
        $this->removeFile($asset, $path);
        MediaAsset::withoutUserScope()->where('user_id', $asset->user_id)->whereKey($asset->id)
            ->where('import_state->studio_audio_snapshot', $path)->update(['import_state' => null]);
    }

    public function deleteStaging(MediaAsset $asset): void
    {
        $path = $asset->source_ref;
        if (is_string($path) && preg_match('~^media/'.(int) $asset->user_id.'/[a-z0-9]{26}\.(wav|mp3|m4a|aac|ogg)$~D', $path)) {
            $this->removeFile($asset, $path);
        }
    }

    /** Keep failed allocations in the ledger until their bytes are actually removed; no network inside a DB transaction. */
    public function deletePending(MediaAsset $asset): void
    {
        $lock = Cache::lock('studio.audio.transfer.'.$asset->id, 180);
        if (! $lock->get()) {
            throw new DomainException('El audio se está procesando; vuelve a intentar su eliminación.');
        }
        try {
            $fresh = MediaAsset::withoutUserScope()->where('user_id', $asset->user_id)->find($asset->id);
            if ($fresh === null) {
                return;
            }
            if ($fresh->isReady()) {
                throw new DomainException('El audio ya está listo en el estudio.');
            }
            $this->abortMultipart($fresh);
            $this->discardSnapshot($fresh);
            $this->deleteStaging($fresh);
            $this->removeFile($fresh, $fresh->path);
            DB::transaction(function () use ($asset): void {
                $locked = MediaAsset::withoutUserScope()->where('user_id', $asset->user_id)->lockForUpdate()->find($asset->id);
                if ($locked === null) {
                    return;
                }
                if ($locked->isReady() || $locked->posts()->exists()) {
                    throw new DomainException('El audio está en uso.');
                }
                StudioAsset::withoutUserScope()->where('user_id', $locked->user_id)->where('media_asset_id', $locked->id)->delete();
                $locked->delete();
            });
        } finally {
            $lock->release();
        }
    }

    private function abortMultipart(MediaAsset $asset): void
    {
        if (! $asset->upload_id) {
            return;
        }
        $disk = Storage::disk($asset->disk);
        if (! $disk instanceof AwsS3V3Adapter) {
            throw new StudioAudioProbeUnavailable('No se pudo cancelar la sesión de subida remota.');
        }
        $config = $disk->getConfig();
        $root = trim((string) ($config['root'] ?? ''), '/');
        try {
            $disk->getClient()->abortMultipartUpload(['Bucket' => $config['bucket'], 'Key' => ($root === '' ? '' : $root.'/').$asset->path,
                'UploadId' => $asset->upload_id, '@retries' => 0, '@http' => ['timeout' => 5, 'connect_timeout' => 5]]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchUpload') {
                throw new StudioAudioProbeUnavailable('No se pudo cancelar la subida remota; conserva su seguimiento para reintentar.');
            }
        } catch (Throwable) {
            throw new StudioAudioProbeUnavailable('No se pudo cancelar la subida remota; conserva su seguimiento para reintentar.');
        }
        $asset->forceFill(['upload_id' => null])->save();
    }

    /** Retain the old key until every upload URL is expired, so replay-created objects remain tracked. */
    public function pruneStaging(): void
    {
        MediaAsset::withoutUserScope()->where('kind', MediaAsset::KIND_AUDIO)->where('status', MediaAsset::STATUS_READY)->whereNotNull('source_ref')
            ->chunkById(100, function ($assets): void {
                foreach ($assets as $asset) {
                    try {
                        $this->deleteStaging($asset);
                        if ($asset->created_at->lt(now()->subMinutes((int) config('media.upload_url_ttl', 120) + 60))) {
                            MediaAsset::withoutUserScope()->whereKey($asset->id)->where('source_ref', $asset->source_ref)->update(['source_ref' => null]);
                        }
                    } catch (Throwable) {
                        Log::warning('studio.audio_staging_cleanup_deferred', ['media_id' => $asset->id]);
                    }
                }
            });
    }
}
