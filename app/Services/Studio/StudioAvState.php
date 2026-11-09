<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignRevision;
use App\Models\Studio\StudioAsset;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvStep;
use App\Models\Studio\StudioAvWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Private audiovisual state. Queue/filesystem integration follows the generation and lease contract. */
class StudioAvState
{
    public function failPosition(int $id, string $generation, int $position, string $code): bool
    {
        return DB::transaction(function () use ($id, $generation, $position, $code) {
            $this->reserveSqliteWriter('studio_design_exports', $id);
            $export = DesignExport::withoutUserScope()->whereKey($id)->lockForUpdate()->first();
            if (! $export || ! in_array($export->status, ['pending', 'processing'], true) || ($export->render_state['generation'] ?? null) !== $generation
                || ($export->render_state['position'] ?? null) !== $position) {
                return false;
            }
            $state = $export->render_state;
            if ($state['lease']) {
                StudioAvLease::whereKey($state['lease'])->where('status', 'running')->update(['status' => 'failed']);
            }
            $state['lease'] = null;
            $this->saveState($export, $state, ['status' => 'failed', 'error' => $this->failureMessage($code)]);

            return true;
        });
    }

    public function defer(object $lease): bool
    {
        return DB::transaction(function () use ($lease) {
            $this->reserveSqliteWriter('studio_av_leases', $lease->id);
            [$export, $workspace, $current] = $this->activeLease($lease);
            if (! $current) {
                return false;
            }
            $current->update(['status' => 'abandoned', 'expires_at' => now()]);
            $workspace->update(['active_until' => now()]);
            $state = $export->render_state;
            $state['lease'] = null;
            $this->saveState($export, $state, ['status' => 'processing']);

            return true;
        });
    }

    public function workingExport(StudioAvLease $lease): DesignExport
    {
        return DB::transaction(function () use ($lease) {
            [$export, , $current] = $this->activeLease($lease);
            if (! $current) {
                throw (new ModelNotFoundException)->setModel(StudioAvLease::class, [$lease->id]);
            }

            return $export;
        });
    }

    /** Reserve one private library object before transfer; no network or filesystem I/O under DB locks. */
    public function allocateRegistration(StudioAvLease $lease): MediaAsset
    {
        return DB::transaction(function () use ($lease) {
            $this->reserveSqliteWriter('studio_av_leases', $lease->id);
            [$export, $workspace, $current] = $this->activeLease($lease);
            if (! $current || $current->phase !== 'register') {
                throw (new ModelNotFoundException)->setModel(StudioAvLease::class, [$lease->id]);
            }
            if ($workspace->media_retiring) {
                throw new StudioAvWorkspaceBusy('La copia anterior todavía se está retirando.');
            }
            $source = $this->registrationSource($export);
            $result = $source->result;
            $kind = $export->format === 'mp4' ? MediaAsset::KIND_VIDEO : MediaAsset::KIND_IMAGE;
            if ($result['size'] > (int) config('media.max_bytes.'.$kind, 0)) {
                throw ValidationException::withMessages(['export' => ['El render supera el tamaño admitido en la biblioteca.']]);
            }
            if ($workspace->media_id !== null) {
                $media = MediaAsset::withoutUserScope()->where('user_id', $export->user_id)->findOrFail($workspace->media_id);
                $this->assertRegistrationPin($workspace, $media, $result);

                return $media;
            }
            $used = (int) StudioAvLease::where('workspace_id', $workspace->id)->where('category', 'output')->where('status', '!=', 'released')
                ->sum(DB::raw('COALESCE(actual_bytes, reserved_bytes)'));
            $limit = config('studio.av.disk.output', 8 * 1024 ** 3);
            if (! is_int($limit) || $limit < 1 || $limit > 8 * 1024 ** 3 || $result['size'] > $limit - $used - $workspace->media_reserved_bytes) {
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
            $this->reserveSqliteWriter('studio_av_leases', $lease->id);
            [$export, $workspace, $current] = $this->activeLease($lease);
            if (! $current || $current->phase !== 'register') {
                throw (new ModelNotFoundException)->setModel(StudioAvLease::class, [$lease->id]);
            }
            if ($workspace->media_retiring) {
                throw new StudioAvWorkspaceBusy('La copia anterior todavía se está retirando.');
            }
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
            $this->reserveSqliteWriter('studio_av_leases', $lease->id);
            if ($deadline !== null && (! is_finite($deadline) || microtime(true) >= $deadline)) {
                throw new StudioAvProcessFailed('phase_timeout');
            }
            [$export, $workspace, $current] = $this->activeLease($lease);
            if (! $current || $current->phase !== 'register') {
                return false;
            }
            $media = MediaAsset::withoutUserScope()->where('user_id', $export->user_id)->whereKey($workspace->media_id)->lockForUpdate()->firstOrFail();
            $this->assertRegistrationPin($workspace, $media, $this->registrationSource($export)->result);
            $allowed = ['stage', 'bytes', 'verified_bytes', 'complete', 'parts', 'object_etag'];
            if (array_diff(array_keys($progress), $allowed) !== []) {
                throw ValidationException::withMessages(['export' => ['El progreso no puede cambiar la identidad de la transferencia.']]);
            }
            $journal = array_replace($media->import_state, $progress);
            if (! is_int($journal['bytes']) || ! is_int($journal['verified_bytes']) || ! is_bool($journal['complete'])
                || $journal['bytes'] < $media->import_state['bytes'] || $journal['bytes'] > $journal['total']
                || $journal['verified_bytes'] < $media->import_state['verified_bytes'] || $journal['verified_bytes'] > $journal['bytes']
                || ! in_array($journal['stage'] ?? 'allocated', ['allocated', 'creating', 'copying', 'completing', 'verifying', 'done'], true)
                || ! is_array($journal['parts']) || ! array_is_list($journal['parts']) || count($journal['parts']) > 512
                || ($journal['complete'] && ($journal['bytes'] !== $journal['total'] || $journal['verified_bytes'] !== $journal['total']))
                || strlen(json_encode($journal, JSON_THROW_ON_ERROR)) > 256 * 1024 || ($uploadId !== null && (strlen($uploadId) > 1024 || $uploadId === ''))) {
                throw ValidationException::withMessages(['export' => ['El progreso de la transferencia no es válido.']]);
            }
            $media->update(['import_state' => $journal, 'upload_id' => $uploadId]);
            $receipt = $workspace->media_receipt;
            $receipt['upload_id'] = $uploadId;
            $workspace->update(['media_receipt' => $receipt]);
            if ($deadline !== null && microtime(true) >= $deadline) {
                throw new StudioAvProcessFailed('phase_timeout');
            }

            return true;
        };
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

    private function registrationSource(DesignExport $export): StudioAvLease
    {
        $sources = $this->checkpoints($export, ['assemble']);
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

    private function assertRegistrationPin(StudioAvWorkspace $workspace, MediaAsset $media, array $result): void
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

    public function begin(string $id): ?StudioAvLease
    {
        return DB::transaction(function () use ($id) {
            $this->reserveSqliteWriter('studio_av_leases', $id);
            $candidate = StudioAvLease::find($id);
            $allocation = $candidate ? StudioAvWorkspace::withoutUserScope()->select(self::WORKSPACE_COLUMNS)->find($candidate->workspace_id) : null;
            $export = $allocation ? DesignExport::withoutUserScope()->whereKey($allocation->export_id)->lockForUpdate()->first() : null;
            if (! $export || $export->status !== 'processing' || ($export->render_state['lease'] ?? null) !== $id
                || ($export->render_state['generation'] ?? null) !== $candidate->generation) {
                return null;
            }
            $workspace = $this->workspace($export);
            $this->assertOwnership($export);
            $current = StudioAvLease::whereKey($id)->lockForUpdate()->first();
            $step = $this->step($export->render_state);
            if (! $current || $current->status !== 'running' || $current->started_at !== null || ! $step
                || $step->phase !== $current->phase || $step->item !== $current->item || $step->lease_id !== $id) {
                return null;
            }
            $current->update(['started_at' => now(), 'expires_at' => now()->addSeconds(180)]);
            $workspace->update(['active_until' => $current->expires_at]);

            return $current;
        });
    }

    public function rejectCheckpoint(object $lease): bool
    {
        return DB::transaction(function () use ($lease) {
            $this->reserveSqliteWriter('studio_av_leases', $lease->id);
            [$export, $workspace, $current] = $this->activeLease($lease);
            if (! $current || $current->phase !== 'verify') {
                return false;
            }
            $state = $export->render_state;
            $step = $this->step($state);
            $source = StudioAvLease::where('workspace_id', $workspace->id)->whereKey($step->source_id)->where('status', 'accepted')->lockForUpdate()->first();
            if (! $source || $source->phase !== $step->target_phase || $source->item !== $step->target_item) {
                return false;
            }
            $source->update(['status' => 'invalid']);
            $workspace->update(['active_until' => $source->expires_at]);
            $current->update(['status' => 'failed', 'actual_bytes' => 0]);
            $step->update(['phase' => $source->phase, 'item' => $source->item, 'source_id' => null,
                'target_phase' => null, 'target_item' => null, 'lease_id' => null, 'result_lease_id' => null]);
            $state['lease'] = null;
            $this->saveState($export, $state, ['status' => 'processing', 'error' => null]);

            return true;
        });
    }

    private const WORKSPACE_COLUMNS = ['id', 'user_id', 'brand_id', 'export_id', 'uuid', 'fingerprint', 'media_id', 'media_reserved_bytes', 'media_receipt', 'media_retiring', 'active_until', 'created_at', 'updated_at'];

    public function initialize(User $user, DesignExport $export): DesignExport
    {
        return DB::transaction(function () use ($user, $export) {
            $this->reserveSqliteWriter('studio_design_exports', $export->id);
            $current = DesignExport::withoutUserScope()->where('user_id', $user->id)->whereKey($export->id)->lockForUpdate()->firstOrFail();
            $document = $this->pinnedDocument($current);
            if (! in_array($current->format, ['mp4', 'gif'], true)) {
                throw ValidationException::withMessages(['export' => ['Este trabajo no es una exportación audiovisual.']]);
            }
            $documentHash = hash('sha256', json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $fingerprint = $this->fingerprint($current, $documentHash);
            if ($current->render_state !== null) {
                if (($current->render_state['fingerprint'] ?? null) !== $fingerprint) {
                    throw ValidationException::withMessages(['export' => ['La revisión u opciones del trabajo cambiaron. Inicia una exportación nueva.']]);
                }

                return $current;
            }
            if ($current->status !== 'pending') {
                throw ValidationException::withMessages(['export' => ['El trabajo no admite inicialización en su estado actual.']]);
            }
            $workspace = StudioAvWorkspace::withoutUserScope()->firstOrCreate(['export_id' => $current->id], [
                'user_id' => $user->id, 'brand_id' => $current->brand_id, 'uuid' => (string) Str::uuid(),
                'fingerprint' => $fingerprint, 'active_until' => now()->addSeconds(180),
            ]);
            if ($workspace->user_id !== (int) $current->user_id || $workspace->brand_id !== (int) $current->brand_id || $workspace->fingerprint !== $fingerprint) {
                throw (new ModelNotFoundException)->setModel(DesignExport::class, [$current->id]);
            }
            $generation = (string) Str::uuid();
            StudioAvStep::create(['workspace_id' => $workspace->id, 'generation' => $generation, 'position' => 0, 'phase' => 'plan', 'item' => 0]);
            $this->saveState($current, ['version' => 1, 'generation' => $generation, 'workspace' => $workspace->uuid, 'allocation_id' => $workspace->id,
                'fingerprint' => $fingerprint, 'document_hash' => $documentHash, 'position' => 0, 'total' => 1,
                'lease' => null, 'reuse_generation' => null, 'cancelled_lease' => null]);

            return $current;
        });
    }

    public function claim(int $exportId, string $generation, int $bytes = 0, ?int $position = null): ?StudioAvLease
    {
        return DB::transaction(function () use ($exportId, $generation, $bytes, $position) {
            $this->reserveSqliteWriter('studio_design_exports', $exportId);
            $export = DesignExport::withoutUserScope()->whereKey($exportId)->lockForUpdate()->first();
            if (! $export || ! in_array($export->status, ['pending', 'processing'], true) || ($export->render_state['generation'] ?? null) !== $generation
                || ($position !== null && ($export->render_state['position'] ?? null) !== $position)) {
                return null;
            }
            $workspace = $this->workspace($export);
            $this->assertOwnership($export);
            $state = $export->render_state;
            $old = isset($state['lease']) ? StudioAvLease::whereKey($state['lease'])->lockForUpdate()->first() : null;
            if ($old && $old->status === 'running' && $old->expires_at->isFuture()) {
                return null;
            }
            if ($old && $old->status === 'running') {
                $old->update(['status' => 'abandoned']);
            }
            $step = $this->step($state);
            if (! $step) {
                return null;
            }
            $task = $step->descriptor();
            $category = match ($task['phase']) {
                'asset', 'font' => 'snapshot', 'normalize' => 'pcm', 'render', 'mix' => 'segment', 'assemble' => 'output', default => 'none',
            };
            // Keep the fixed source path unavailable until physical retirement, even after another retry.
            if ($category !== 'none' && StudioAvLease::where('workspace_id', $workspace->id)->where('category', $category)
                ->where('status', 'invalid')->where('phase', $task['phase'])->where('item', $task['index'])->exists()) {
                return null;
            }
            if ($bytes < 0 || ($category === 'none' ? $bytes !== 0 : $bytes < 1)) {
                throw ValidationException::withMessages(['export' => ['La fase necesita una reserva de disco válida.']]);
            }
            if ($category !== 'none') {
                $limit = config('studio.av.disk.'.$category, 8 * 1024 ** 3);
                $used = (int) StudioAvLease::where('workspace_id', $workspace->id)->where('category', $category)->where('status', '!=', 'released')
                    ->sum(DB::raw('COALESCE(actual_bytes, reserved_bytes)'));
                $used += $category === 'output' ? $workspace->media_reserved_bytes : 0;
                if (! is_int($limit) || $limit < 1 || $limit > 8 * 1024 ** 3 || $bytes > $limit - $used) {
                    throw ValidationException::withMessages(['export' => ['El trabajo supera su presupuesto de almacenamiento; limpia o reduce los recursos antes de reintentar.']]);
                }
            }
            $lease = StudioAvLease::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'generation' => $generation,
                'phase' => $task['phase'], 'item' => $task['index'], 'category' => $category, 'reserved_bytes' => $bytes, 'expires_at' => now()->addSeconds(180)]);
            $state['lease'] = $lease->id;
            $step->update(['lease_id' => $lease->id]);
            $this->saveState($export, $state, ['status' => 'processing']);
            $workspace->update(['active_until' => $lease->expires_at]);

            return $lease;
        });
    }

    public function accept(object $lease, array $result, int $bytes = 0): bool
    {
        return DB::transaction(function () use ($lease, $result, $bytes) {
            $this->reserveSqliteWriter('studio_av_leases', $lease->id);
            [$export, $workspace, $current] = $this->activeLease($lease);
            if (! $current) {
                return false;
            }
            $state = $export->render_state;
            $step = $this->step($state);
            $resultLease = $current->id;
            if ($bytes < 0 || $bytes > $current->reserved_bytes || ($current->category !== 'none' && ($bytes < 1 || ($result['size'] ?? null) !== $bytes))) {
                throw ValidationException::withMessages(['export' => ['El resultado no coincide con su reserva de disco.']]);
            }
            if ($current->phase === 'plan') {
                $tasks = $this->tasks($export, $result, $workspace);
                $this->installSteps($workspace, $state['generation'], $tasks);
                $state['total'] = count($tasks);
                $workspace->update(['plan' => $result]);
            } elseif ($current->phase === 'verify') {
                $task = $step->descriptor();
                $source = StudioAvLease::where('workspace_id', $workspace->id)->whereKey($task['source'])->where('status', 'accepted')->first();
                if (! $source || $source->phase !== $task['target_phase'] || $source->item !== $task['target_index'] || $this->canonical($source->result) !== $this->canonical($result)) {
                    throw ValidationException::withMessages(['export' => ['La verificación no coincide con el checkpoint aceptado.']]);
                }
                $resultLease = $source->id;
            } elseif ($current->phase === 'register') {
                $mediaId = $result['media_id'] ?? null;
                $media = is_int($mediaId) && $workspace->media_id === $mediaId
                    ? MediaAsset::withoutUserScope()->where('user_id', $export->user_id)->whereKey($mediaId)->lockForUpdate()->first() : null;
                if (! $media) {
                    throw ValidationException::withMessages(['export' => ['El recurso final no pertenece al trabajo.']]);
                }
                $source = $this->registrationSource($export);
                $output = $source->result;
                $this->assertRegistrationPin($workspace, $media, $output);
                $journal = $media->import_state;
                if (($journal['complete'] ?? null) !== true || ($journal['bytes'] ?? null) !== $output['size']
                    || ($journal['verified_bytes'] ?? null) !== $output['size']) {
                    throw ValidationException::withMessages(['export' => ['La transferencia final aún no está verificada.']]);
                }
                $media->forceFill(['status' => MediaAsset::STATUS_READY, 'size' => $output['size'], 'width' => $output['width'],
                    'height' => $output['height'], 'duration' => $output['duration'], 'fps' => $output['fps'],
                    'codec' => $output['videoCodec'], 'audio_codec' => $output['audioCodec'], 'upload_id' => null, 'error' => null])->save();
                StudioAsset::withoutUserScope()->firstOrCreate(['brand_id' => $export->brand_id, 'media_asset_id' => $mediaId], ['user_id' => $export->user_id]);
                $export->media_ids = [$mediaId];
                $receipt = $workspace->media_receipt;
                $receipt['state'] = 'published';
                $receipt['upload_id'] = null;
                $workspace->update(['media_reserved_bytes' => 0, 'media_receipt' => $receipt]);
                $export->disk = 'local';
                $export->path = 'studio-exports/'.$export->user_id.'/'.$export->id.'/'.$workspace->uuid.'/'.$output['file'];
            } else {
                $expected = match ($current->phase) {
                    'asset' => "imports/{$current->id}/asset-{$current->item}.bin",
                    'font' => "imports/{$current->id}/font-{$current->item}.woff2",
                    'normalize' => "audio-sources/{$current->id}/{$current->item}.pcm",
                    'render' => sprintf('segments/%s/video-%06d.%s', $current->id, $current->item, $export->format),
                    'mix' => sprintf('segments/%s/audio-%06d.wav', $current->id, $current->item),
                    'assemble' => "outputs/{$current->id}/project.{$export->format}",
                    default => null,
                };
                if ($expected === null || ($result['file'] ?? null) !== $expected || ($result['namespace'] ?? null) !== $current->id || preg_match('/^[a-f0-9]{64}$/D', $result['sha256'] ?? '') !== 1) {
                    throw ValidationException::withMessages(['export' => ['El checkpoint no coincide con su fase y lease.']]);
                }
            }
            $current->update(['status' => 'accepted', 'result' => $result, 'actual_bytes' => $bytes]);
            $step->update(['result_lease_id' => $resultLease]);
            $state['position']++;
            $state['lease'] = null;
            $this->saveState($export, $state, ['status' => $state['position'] === $state['total'] ? 'ready' : 'processing']);

            return true;
        });
    }

    public function cancel(User $user, DesignExport $export): DesignExport
    {
        return DB::transaction(function () use ($user, $export) {
            $this->reserveSqliteWriter('studio_design_exports', $export->id);
            $current = $this->ownedLocked($user, $export);
            if ($current->status === 'ready' || $current->status === 'failed') {
                abort(409, 'El estado actual no admite cancelación.');
            }
            if ($current->status === 'cancelled') {
                return $current;
            }
            $state = $current->render_state;
            $state['cancelled_lease'] = $state['lease'];
            if ($state['lease']) {
                StudioAvLease::whereKey($state['lease'])->where('status', 'running')->update(['status' => 'abandoned']);
            }
            $state['lease'] = null;
            $this->saveState($current, $state, ['status' => 'cancelled', 'error' => null]);

            return $current;
        });
    }

    public function retry(User $user, DesignExport $export): DesignExport
    {
        return DB::transaction(function () use ($user, $export) {
            $this->reserveSqliteWriter('studio_design_exports', $export->id);
            $current = $this->ownedLocked($user, $export);
            if (! in_array($current->status, ['failed', 'cancelled'], true)) {
                abort(409, 'El trabajo todavía está activo o ya tiene un resultado listo.');
            }
            $workspace = $this->workspace($current);
            $document = $this->pinnedDocument($current);
            if (hash('sha256', json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)) !== $current->render_state['document_hash']) {
                throw ValidationException::withMessages(['export' => ['El documento de la revisión cambió; inicia una exportación nueva.']]);
            }
            $state = $current->render_state;
            if (StudioAvStep::where('workspace_id', $workspace->id)->where('generation', $state['generation'])->where('position', '>', 0)->exists()) {
                $state['reuse_generation'] = $state['generation'];
            }
            $state['generation'] = (string) Str::uuid();
            $state['position'] = 0;
            $state['total'] = 1;
            $state['lease'] = null;
            StudioAvStep::create(['workspace_id' => $workspace->id, 'generation' => $state['generation'], 'position' => 0, 'phase' => 'plan', 'item' => 0]);
            $workspace->update(['plan' => null]);
            $this->saveState($current, $state, ['status' => 'pending', 'error' => null]);

            return $current;
        });
    }

    public function fail(object $lease, string $code): bool
    {
        return DB::transaction(function () use ($lease, $code) {
            $this->reserveSqliteWriter('studio_av_leases', $lease->id);
            [$export, , $current] = $this->activeLease($lease, false);
            if (! $current) {
                return false;
            }
            $message = $this->failureMessage($code);
            $current->update(['status' => 'failed']);
            $state = $export->render_state;
            $state['lease'] = null;
            $this->saveState($export, $state, ['status' => 'failed', 'error' => $message]);

            return true;
        });
    }

    private function failureMessage(string $code): string
    {
        return match ($code) {
            'phase_timeout' => 'La fase superó su tiempo permitido. Reduce el tamaño o reintenta el trabajo.',
            'runtime_unavailable' => 'El entorno audiovisual no está disponible. Revisa Python, Node, Chromium, FFmpeg y los límites del worker Studio.',
            'missing_resource' => 'Un recurso o el diseño ya no está disponible en esta marca.',
            'source_changed' => 'El recurso original cambió. Inicia una nueva exportación para no mezclar contenido anterior y nuevo.',
            'output_budget', 'plan_budget' => 'El trabajo supera su presupuesto. Reduce la resolución o las páginas seleccionadas.',
            default => 'No se pudo completar la fase audiovisual. Reintenta o revisa los recursos del diseño.',
        };
    }

    private function fingerprint(DesignExport $export, string $documentHash): string
    {
        return hash('sha256', json_encode([$export->user_id, $export->brand_id, $export->design_id, $export->revision,
            $export->format, $export->pages, $export->options, $documentHash], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Internal descriptor for orchestration; never part of the public export serializer. */
    public function task(DesignExport $export): ?array
    {
        return $export->render_state ? $this->step($export->render_state)?->descriptor() : null;
    }

    /** Accepted native result references of this generation; optionally bounded to needed phases. */
    public function checkpoints(DesignExport $export, array $phases = [], bool $unpublished = false): Collection
    {
        $state = $export->render_state;
        if (! $state) {
            return collect();
        }
        $query = StudioAvLease::query()->where('studio_av_leases.workspace_id', $state['allocation_id'])->where('studio_av_leases.status', 'accepted');
        if ($unpublished) {
            $query->where('studio_av_leases.category', 'snapshot')->where('studio_av_leases.input_published', false)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('studio_av_steps as checkpoint_steps')
                    ->where('checkpoint_steps.workspace_id', $state['allocation_id'])->where('checkpoint_steps.generation', $state['generation'])
                    ->whereColumn('checkpoint_steps.result_lease_id', 'studio_av_leases.id'))->limit(16);
        } elseif ($phases !== []) {
            // Phase-filtered lookups (especially assemble per transfer part) must not scan every step.
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('studio_av_steps as checkpoint_steps')
                ->where('checkpoint_steps.workspace_id', $state['allocation_id'])->where('checkpoint_steps.generation', $state['generation'])
                ->whereColumn('checkpoint_steps.result_lease_id', 'studio_av_leases.id'));
        } else {
            $query->join('studio_av_steps as checkpoint_steps', 'checkpoint_steps.result_lease_id', '=', 'studio_av_leases.id')
                ->where('checkpoint_steps.workspace_id', $state['allocation_id'])->where('checkpoint_steps.generation', $state['generation']);
        }
        if ($phases !== []) {
            $query->whereIn('studio_av_leases.phase', $phases);
        }

        return $query->select('studio_av_leases.*')->get()->keyBy(fn (StudioAvLease $lease) => $lease->phase.':'.$lease->item);
    }

    private function step(array $state): ?StudioAvStep
    {
        return StudioAvStep::where('workspace_id', $state['allocation_id'])->where('generation', $state['generation'])
            ->where('position', $state['position'])->first();
    }

    private function installSteps(StudioAvWorkspace $workspace, string $generation, array $tasks): void
    {
        $rows = [];
        foreach ($tasks as $position => $task) {
            if ($position === 0) {
                continue; // The initial plan step already owns the live lease being accepted.
            }
            $rows[] = ['workspace_id' => $workspace->id, 'generation' => $generation, 'position' => $position,
                'phase' => $task['phase'], 'item' => $task['index'], 'source_id' => $task['source'] ?? null,
                'target_phase' => $task['target_phase'] ?? null, 'target_item' => $task['target_index'] ?? null];
            if (count($rows) === 500) {
                StudioAvStep::insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            StudioAvStep::insert($rows);
        }
    }

    private function saveState(DesignExport $export, array $state, array $changes = []): void
    {
        $total = max(1, $state['total']);
        $completed = min($total, $state['position']);
        $phase = match ($changes['status'] ?? $export->status) {
            'ready' => 'done', 'failed' => 'failed', 'cancelled' => 'cancelled',
            default => $this->step($state)?->phase ?? 'done',
        };
        $progress = ['phase' => in_array($phase, ['asset', 'font'], true) ? 'snapshot' : $phase,
            'completed' => $completed, 'total' => $total, 'percent' => round(100 * $completed / $total, 2)];
        $export->fill($changes + ['render_state' => $state, 'render_progress' => $progress])->save();
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value);
    }

    private function workspace(DesignExport $export): StudioAvWorkspace
    {
        $state = $export->render_state;
        $workspace = StudioAvWorkspace::withoutUserScope()->select(self::WORKSPACE_COLUMNS)->whereKey($state['allocation_id'])->where('export_id', $export->id)->where('user_id', $export->user_id)
            ->where('brand_id', $export->brand_id)->where('uuid', $state['workspace'])->lockForUpdate()->firstOrFail();
        if ($workspace->fingerprint !== ($state['fingerprint'] ?? null) || $this->fingerprint($export, $state['document_hash']) !== $workspace->fingerprint) {
            throw ValidationException::withMessages(['export' => ['La revisión u opciones cambiaron; inicia un trabajo nuevo.']]);
        }

        return $workspace;
    }

    private function ownedLocked(User $user, DesignExport $export): DesignExport
    {
        $current = DesignExport::withoutUserScope()->where('user_id', $user->id)->whereKey($export->id)->lockForUpdate()->firstOrFail();
        if (! Brand::withoutUserScope()->where('user_id', $user->id)->whereKey($current->brand_id)->exists()) {
            throw (new ModelNotFoundException)->setModel(DesignExport::class, [$current->id]);
        }
        if (! in_array($current->format, ['mp4', 'gif'], true) || ! $current->render_state) {
            throw ValidationException::withMessages(['export' => ['El trabajo no admite gestión audiovisual.']]);
        }

        return $current;
    }

    private function reserveSqliteWriter(string $table, int|string $id): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Reserve the writer before reading fenced state; SQLite cannot wait
            // safely when upgrading a shared reader against another session writer.
            DB::table($table)->where('id', $id)->update(['id' => DB::raw('id')]);
        }
    }

    private function activeLease(object $lease, bool $requireOwner = true): array
    {
        $existing = StudioAvLease::find($lease->id);
        $allocation = $existing ? StudioAvWorkspace::withoutUserScope()->select(self::WORKSPACE_COLUMNS)->find($existing->workspace_id) : null;
        $export = $allocation ? DesignExport::withoutUserScope()->whereKey($allocation->export_id)->lockForUpdate()->first() : null;
        if (! $export || $export->status !== 'processing' || ($export->render_state['lease'] ?? null) !== $existing->id || ($export->render_state['generation'] ?? null) !== $existing->generation) {
            return [null, null, null];
        }
        $workspace = $this->workspace($export);
        $current = StudioAvLease::whereKey($existing->id)->lockForUpdate()->first();
        if ($requireOwner) {
            $this->assertOwnership($export);
        }
        if ($current->status !== 'running' || $current->expires_at->isPast()) {
            return [null, null, null];
        }
        $step = $this->step($export->render_state);
        if (! $step || $step->phase !== $current->phase || $step->item !== $current->item || $step->lease_id !== $current->id) {
            return [null, null, null];
        }

        return [$export, $workspace, $current];
    }

    private function tasks(DesignExport $export, array $plan, StudioAvWorkspace $workspace): array
    {
        $fps = $plan['fps'] ?? null;
        $frames = $plan['frameCount'] ?? null;
        $count = $plan['segmentCount'] ?? null;
        $size = $plan['segmentFrames'] ?? null;
        if (! in_array($fps, [12, 15, 24, 25, 30, 50, 60], true) || ! is_int($frames) || $frames < 1 || ! is_int($size) || $size < 1 || $size > $fps * 2
            || ! is_int($count) || $count < 1 || $count > 4096 || (int) ceil($frames / $size) !== $count || ($plan['sampleRate'] ?? null) !== 48000
            || ($plan['selection'] ?? null) !== $export->pages || ! is_numeric($plan['duration'] ?? null) || abs($plan['duration'] - $frames / $fps) > 1e-9) {
            throw ValidationException::withMessages(['export' => ['El plan no tiene un reloj y una selección válidos.']]);
        }
        $tasks = [['phase' => 'plan', 'index' => 0]];
        foreach (['assetIds' => 'asset', 'fontIds' => 'font', 'audioAssetIds' => 'normalize'] as $key => $phase) {
            $ids = $plan[$key] ?? null;
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) > ($key === 'fontIds' ? 64 : 50000) || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
                throw ValidationException::withMessages(['export' => ['Los recursos del plan no son válidos.']]);
            }
            foreach ($ids as $id) {
                if (! is_int($id) || $id < 1 || $id > 9007199254740991 || ($phase === 'normalize' && ! in_array($id, $plan['assetIds'], true))) {
                    throw ValidationException::withMessages(['export' => ['El plan contiene una identidad de recurso inválida.']]);
                }
                $tasks[] = ['phase' => $phase, 'index' => $id];
            }
        }
        if ($export->format === 'gif' && $plan['audioAssetIds'] !== []) {
            throw ValidationException::withMessages(['export' => ['GIF no utiliza pistas de audio.']]);
        }
        for ($index = 0; $index < $count; $index++) {
            $tasks[] = ['phase' => 'render', 'index' => $index];
            if ($plan['audioAssetIds'] !== []) {
                $tasks[] = ['phase' => 'mix', 'index' => $index];
            }
        }
        $tasks[] = ['phase' => 'assemble', 'index' => 0];
        $tasks[] = ['phase' => 'register', 'index' => 0];

        $reuse = $export->render_state['reuse_generation'] ?? null;
        if ($reuse !== null) {
            $sources = StudioAvLease::query()->join('studio_av_steps as reusable_steps', function ($join) {
                $join->on('studio_av_leases.id', '=', DB::raw('COALESCE(reusable_steps.result_lease_id, reusable_steps.source_id)'));
            })
                ->where('reusable_steps.workspace_id', $workspace->id)->where('reusable_steps.generation', $reuse)
                ->where('studio_av_leases.workspace_id', $workspace->id)->where('studio_av_leases.status', 'accepted')
                ->whereNotIn('studio_av_leases.phase', ['plan', 'register'])->select('studio_av_leases.*')->get()
                ->keyBy(fn (StudioAvLease $lease) => $lease->phase.':'.$lease->item);
            $verification = 0;
            foreach ($tasks as &$task) {
                $source = $sources->get($task['phase'].':'.$task['index']);
                if ($source && $source->phase === $task['phase'] && $source->item === $task['index'] && $source->actual_bytes > 0) {
                    $task = ['phase' => 'verify', 'index' => $verification++, 'source' => $source->id,
                        'target_phase' => $source->phase, 'target_index' => $source->item];
                }
            }
            unset($task);
        }

        return $tasks;
    }

    private function assertOwnership(DesignExport $export): void
    {
        if (! User::whereKey($export->user_id)->exists() || ! Brand::withoutUserScope()->where('user_id', $export->user_id)->whereKey($export->brand_id)->exists()
            || ! Design::withoutUserScope()->where('user_id', $export->user_id)->where('brand_id', $export->brand_id)->whereKey($export->design_id)->exists()) {
            throw (new ModelNotFoundException)->setModel(DesignExport::class, [$export->id]);
        }
    }

    private function pinnedDocument(DesignExport $export): array
    {
        $this->assertOwnership($export);

        return DesignRevision::withoutUserScope()->where('user_id', $export->user_id)->where('design_id', $export->design_id)
            ->where('revision', $export->revision)->firstOrFail()->document;
    }
}
