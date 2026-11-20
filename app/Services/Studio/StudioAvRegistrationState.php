<?php

namespace App\Services\Studio;

use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvWorkspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Registro del render final en la biblioteca: reserva del objeto, ubicacion fisica, checkpoint del progreso de la transferencia y las comprobaciones que fijan el recurso al render aceptado. */
class StudioAvRegistrationState
{
    public function __construct(private readonly StudioAvStore $store) {}

    /** Reserve one private library object before transfer; no network or filesystem I/O under DB locks. */
    public function allocateRegistration(StudioAvLease $lease): MediaAsset
    {
        return DB::transaction(function () use ($lease) {
            [$export, $workspace] = $this->lockRegistrationLease($lease);
            $source = $this->registrationSource($export);
            $result = $source->result;
            $kind = $export->format === 'mp4' ? MediaAsset::KIND_VIDEO : MediaAsset::KIND_IMAGE;
            if ($result['size'] > (int) config('media.max_bytes.'.$kind, 0)) {
                throw ValidationException::withMessages(['export' => ['El render supera el tamaño admitido en la biblioteca.']]);
            }
            if ($workspace->media_id !== null) {
                $media = MediaAsset::ownedBy($export->user_id)->findOrFail($workspace->media_id);
                $this->assertRegistrationPin($workspace, $media, $result);

                return $media;
            }
            if (! $this->store->canReserveBytes($workspace, 'output', $result['size'])) {
                throw ValidationException::withMessages(['export' => ['La copia final supera el presupuesto de almacenamiento del trabajo.']]);
            }
            $disk = (string) config('filesystems.media', 'local');
            $media = new MediaAsset;
            $media->forceFill(['user_id' => $export->user_id, 'disk' => $disk,
                'path' => 'media/'.$export->user_id.'/studio-'.$workspace->uuid.'.'.$export->format,
                'original_name' => 'studio-'.$export->id.'.'.$export->format,
                'mime' => $export->format === 'mp4' ? 'video/mp4' : 'image/gif', 'kind' => $kind,
                'declared_size' => $result['size'], 'status' => MediaAsset::STATUS_PENDING,
                'import_state' => ['studio_av' => $workspace->uuid, 'disk' => $disk, 'sha256' => $result['sha256'], 'total' => $result['size'],
                    'bytes' => 0, 'verified_bytes' => 0, 'complete' => false, 'parts' => []]])->save();
            $workspace->update(['media_id' => $media->id, 'media_reserved_bytes' => $result['size'], 'media_receipt' => [
                'version' => 1, 'media_id' => $media->id, 'disk' => $disk, 'path' => $media->path, 'total' => $result['size'],
                'sha256' => $result['sha256'], 'upload_id' => null, 'location' => null, 'state' => 'active']]);

            return $media;
        });
    }

    /** Fix the physical backend before the transfer can perform I/O. Credentials are never persisted. */
    public function bindRegistrationLocation(StudioAvLease $lease, array $location): void
    {
        DB::transaction(function () use ($lease, $location) {
            [, $workspace] = $this->lockRegistrationLease($lease);
            $receipt = $workspace->media_receipt;
            if (! $receipt || $receipt['media_id'] !== $workspace->media_id || $receipt['state'] !== 'active'
                || (! in_array($location['driver'] ?? null, ['local', 's3'], true))
                || (($receipt['location'] ?? null) !== null && $receipt['location'] !== $location)) {
                throw ValidationException::withMessages(['export' => ['El almacenamiento cambió respecto a la asignación original.']]);
            }
            $receipt['location'] = $location;
            $workspace->update(['media_receipt' => $receipt]);
        });
    }

    /** Persist transfer receipts only; no I/O and no visibility change. */
    public function checkpointRegistration(StudioAvLease $lease, array $progress, ?string $uploadId = null, ?float $deadline = null): bool
    {
        $checkpoint = function () use ($lease, $progress, $uploadId, $deadline) {
            $this->store->reserveSqliteWriter('studio_av_leases', $lease->id);
            if ($deadline !== null && (! is_finite($deadline) || microtime(true) >= $deadline)) {
                throw new StudioAvProcessFailed('phase_timeout');
            }
            [$export, $workspace, $current] = $this->store->activeLease($lease);
            if (! $current || $current->phase !== 'register') {
                return false;
            }
            $media = MediaAsset::ownedBy($export->user_id)->whereKey($workspace->media_id)->lockForUpdate()->firstOrFail();
            $this->assertRegistrationPin($workspace, $media, $this->registrationSource($export)->result);
            $journal = $this->checkpointJournal($media->import_state, $progress, $uploadId);
            $media->update(['import_state' => $journal, 'upload_id' => $uploadId]);
            $receipt = $workspace->media_receipt;
            $receipt['upload_id'] = $uploadId;
            $workspace->update(['media_receipt' => $receipt]);
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new StudioAvProcessFailed('phase_timeout');
            }

            return true;
        };

        return $this->runCheckpoint($checkpoint, $deadline);
    }

    /** @return array{DesignExport, StudioAvWorkspace} */
    private function lockRegistrationLease(StudioAvLease $lease): array
    {
        $this->store->reserveSqliteWriter('studio_av_leases', $lease->id);
        [$export, $workspace, $current] = $this->store->activeLease($lease);
        if (! $current || $current->phase !== 'register') {
            throw (new ModelNotFoundException)->setModel(StudioAvLease::class, [$lease->id]);
        }
        if ($workspace->media_retiring) {
            throw new StudioAvWorkspaceBusy('La copia anterior todavía se está retirando.');
        }

        return [$export, $workspace];
    }

    /** @internal */
    public function registrationSource(DesignExport $export): StudioAvLease
    {
        $sources = $this->store->checkpoints($export, ['assemble']);
        if ($sources->count() !== 1) {
            throw ValidationException::withMessages(['export' => ['Falta un único render final aceptado.']]);
        }
        $source = $sources->first();
        $result = $source->result;
        $plan = StudioAvWorkspace::withoutUserScope()->findOrFail($source->workspace_id)->plan;
        $mp4 = $export->format === 'mp4';
        $width = $plan['render']['paddedWidth'] ?? null;
        $height = $plan['render']['paddedHeight'] ?? null;
        if (! is_int($result['size'] ?? null) || $result['size'] < 1 || $result['size'] !== $source->actual_bytes
            || preg_match('/^[a-f0-9]{64}$/D', $result['sha256'] ?? '') !== 1
            || ($result['file'] ?? null) !== 'outputs/'.$source->id.'/project.'.$export->format || ($result['namespace'] ?? null) !== $source->id
            || ! is_int($width) || ! is_int($height) || $width < 1 || $height < 1
            || ($result['width'] ?? null) !== $width || ($result['height'] ?? null) !== $height
            || ($result['fps'] ?? null) !== ($plan['fps'] ?? null) || ($result['frameCount'] ?? null) !== ($plan['frameCount'] ?? null)
            || ! is_numeric($result['duration'] ?? null) || ! is_finite((float) $result['duration'])
            || abs($result['duration'] - $plan['duration']) > ($mp4 ? 1 / $plan['fps'] : 0.011)
            || ($result['videoCodec'] ?? null) !== ($mp4 ? 'h264' : 'gif')
            || ($result['audioCodec'] ?? null) !== ($mp4 && ($plan['audioAssetIds'] ?? []) !== [] ? 'aac' : null)) {
            throw ValidationException::withMessages(['export' => ['Los metadatos finales no coinciden con el plan aceptado.']]);
        }

        return $source;
    }

    /** @internal */
    public function assertRegistrationPin(StudioAvWorkspace $workspace, MediaAsset $media, array $result): void
    {
        if ($workspace->media_retiring) {
            throw new StudioAvWorkspaceBusy('La copia anterior todavía se está retirando.');
        }
        $journal = $media->import_state;
        $mp4 = $result['videoCodec'] === 'h264';
        if ($media->status !== MediaAsset::STATUS_PENDING || ($journal['studio_av'] ?? null) !== $workspace->uuid
            || ($journal['sha256'] ?? null) !== $result['sha256'] || ($journal['total'] ?? null) !== $result['size']
            || $media->declared_size !== $result['size'] || $media->disk !== ($journal['disk'] ?? null)
            || $media->path !== 'media/'.$workspace->user_id.'/studio-'.$workspace->uuid.($mp4 ? '.mp4' : '.gif')
            || $media->mime !== ($mp4 ? 'video/mp4' : 'image/gif') || $media->kind !== ($mp4 ? MediaAsset::KIND_VIDEO : MediaAsset::KIND_IMAGE)
            || $result['size'] > (int) config('media.max_bytes.'.$media->kind, 0)) {
            throw ValidationException::withMessages(['export' => ['La transferencia no pertenece al render final pendiente.']]);
        }
    }

    /**
     * Diario de la transferencia tras aplicar el progreso: solo avanza (nunca retrocede ni cambia la identidad) y respeta los topes.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $progress
     * @return array<string, mixed>
     */
    private function checkpointJournal(array $current, array $progress, ?string $uploadId): array
    {
        $allowed = ['stage', 'bytes', 'verified_bytes', 'complete', 'parts', 'object_etag'];
        if (array_diff(array_keys($progress), $allowed) !== []) {
            throw ValidationException::withMessages(['export' => ['El progreso no puede cambiar la identidad de la transferencia.']]);
        }
        $journal = array_replace($current, $progress);
        if (! is_int($journal['bytes']) || ! is_int($journal['verified_bytes']) || ! is_bool($journal['complete'])
            || $journal['bytes'] < $current['bytes'] || $journal['bytes'] > $journal['total']
            || $journal['verified_bytes'] < $current['verified_bytes'] || $journal['verified_bytes'] > $journal['bytes']
            || ! in_array($journal['stage'] ?? 'allocated', ['allocated', 'creating', 'copying', 'completing', 'verifying', 'done'], true)
            || ! is_array($journal['parts']) || ! array_is_list($journal['parts']) || count($journal['parts']) > 512
            || ($journal['complete'] && ($journal['bytes'] !== $journal['total'] || $journal['verified_bytes'] !== $journal['total']))
            || strlen(json_encode($journal, JSON_THROW_ON_ERROR)) > 256 * 1024 || ($uploadId !== null && (strlen($uploadId) > 1024 || $uploadId === ''))) {
            throw ValidationException::withMessages(['export' => ['El progreso de la transferencia no es válido.']]);
        }

        return $journal;
    }

    /**
     * Ejecuta el checkpoint en una transaccion. En SQLite con plazo, el `busy_timeout` se acota al tiempo que queda (tambien para el COMMIT)
     * y un bloqueo ocupado pasado el plazo se traduce en `phase_timeout`.
     */
    private function runCheckpoint(\Closure $checkpoint, ?float $deadline): bool
    {
        $connection = DB::connection();
        $busyTimeout = null;
        try {
            if ($connection->getDriverName() === 'sqlite' && $deadline !== null) {
                if (! is_finite($deadline) || microtime(true) >= $deadline) {
                    throw new StudioAvProcessFailed('phase_timeout');
                }
                $busyTimeout = (int) $connection->scalar('PRAGMA busy_timeout');
                $milliseconds = min($busyTimeout, max(1, (int) ceil(($deadline - microtime(true)) * 1000)));
                $connection->statement('PRAGMA busy_timeout = '.$milliseconds);
            }

            if ($connection->getDriverName() !== 'sqlite') {
                return $connection->transaction($checkpoint);
            }
            $level = $connection->transactionLevel();
            $connection->beginTransaction();
            try {
                $result = $checkpoint();
                if ($deadline !== null) {
                    if (microtime(true) >= $deadline) {
                        throw new StudioAvProcessFailed('phase_timeout');
                    }
                    // COMMIT can also wait for a reader. Budget it from the time left
                    // after acquiring the writer and validating the fenced checkpoint.
                    $milliseconds = min($busyTimeout, max(1, (int) ceil(($deadline - microtime(true)) * 1000)));
                    $connection->statement('PRAGMA busy_timeout = '.$milliseconds);
                }
                $connection->commit();

                return $result;
            } catch (\Throwable $error) {
                // The public Laravel API keeps its level until COMMIT succeeds,
                // allowing rollback of SQLITE_BUSY without leaving PDO open at level 0.
                $connection->rollBack($level);
                throw $error;
            }
        } catch (\PDOException $error) {
            if ($connection->getDriverName() === 'sqlite' && $deadline !== null && microtime(true) >= $deadline
                && in_array((int) ($error->errorInfo[1] ?? 0), [5, 6], true)) {
                throw new StudioAvProcessFailed('phase_timeout');
            }
            throw $error;
        } finally {
            if ($busyTimeout !== null) {
                $connection->statement('PRAGMA busy_timeout = '.$busyTimeout);
            }
        }
    }
}
