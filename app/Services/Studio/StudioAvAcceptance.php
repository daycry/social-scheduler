<?php

namespace App\Services\Studio;

use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAsset;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvStep;
use App\Models\Studio\StudioAvWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Aceptacion de resultados de fase (plan, verificacion, registro y ficheros), rechazo de checkpoints y calculo de las tareas del plan. */
class StudioAvAcceptance
{
    public function __construct(private readonly StudioAvStore $store, private readonly StudioAvRegistrationState $registration) {}

    public function accept(object $lease, array $result, int $bytes = 0): bool
    {
        return DB::transaction(function () use ($lease, $result, $bytes) {
            $this->store->reserveSqliteWriter('studio_av_leases', $lease->id);
            [$export, $workspace, $current] = $this->store->activeLease($lease);
            if (! $current) {
                return false;
            }
            $state = $export->render_state;
            $step = $this->store->step($state);
            $resultLease = $current->id;
            if ($bytes < 0 || $bytes > $current->reserved_bytes || ($current->category !== 'none' && ($bytes < 1 || ($result['size'] ?? null) !== $bytes))) {
                throw ValidationException::withMessages(['export' => ['El resultado no coincide con su reserva de disco.']]);
            }
            if ($current->phase === 'plan') {
                $state['total'] = $this->acceptPlan($export, $workspace, $result);
            } elseif ($current->phase === 'verify') {
                $resultLease = $this->acceptVerification($workspace, $step, $result);
            } elseif ($current->phase === 'register') {
                $this->acceptRegistration($export, $workspace, $result);
            } else {
                $this->assertCheckpointFile($current, $export, $result);
            }
            $current->update(['status' => 'accepted', 'result' => $result, 'actual_bytes' => $bytes]);
            $step->update(['result_lease_id' => $resultLease]);
            $state['position']++;
            $state['lease'] = null;
            $this->store->saveState($export, $state, ['status' => $state['position'] === $state['total'] ? 'ready' : 'processing']);

            return true;
        });
    }

    public function rejectCheckpoint(object $lease): bool
    {
        return DB::transaction(function () use ($lease) {
            $this->store->reserveSqliteWriter('studio_av_leases', $lease->id);
            [$export, $workspace, $current] = $this->store->activeLease($lease);
            if (! $current || $current->phase !== 'verify') {
                return false;
            }
            $state = $export->render_state;
            $step = $this->store->step($state);
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
            $this->store->saveState($export, $state, ['status' => 'processing', 'error' => null]);

            return true;
        });
    }

    /** Fase `plan`: instala los pasos del plan y devuelve cuantos son. */
    private function acceptPlan(DesignExport $export, StudioAvWorkspace $workspace, array $result): int
    {
        $tasks = $this->tasks($export, $result, $workspace);
        $this->installSteps($workspace, $export->render_state['generation'], $tasks);
        $workspace->update(['plan' => $result]);

        return count($tasks);
    }

    /** Fase `verify`: el resultado debe coincidir con el checkpoint ya aceptado; devuelve el lease de origen. */
    private function acceptVerification(StudioAvWorkspace $workspace, StudioAvStep $step, array $result): string
    {
        $task = $step->descriptor();
        $source = StudioAvLease::where('workspace_id', $workspace->id)->whereKey($task['source'])->where('status', 'accepted')->first();
        if (! $source || $source->phase !== $task['target_phase'] || $source->item !== $task['target_index'] || $this->store->canonical($source->result) !== $this->store->canonical($result)) {
            throw ValidationException::withMessages(['export' => ['La verificación no coincide con el checkpoint aceptado.']]);
        }

        return $source->id;
    }

    /** Fase `register`: publica el recurso final (ya verificado) y deja el export apuntando a el. */
    private function acceptRegistration(DesignExport $export, StudioAvWorkspace $workspace, array $result): void
    {
        $mediaId = $result['media_id'] ?? null;
        $media = is_int($mediaId) && $workspace->media_id === $mediaId
            ? MediaAsset::ownedBy($export->user_id)->whereKey($mediaId)->lockForUpdate()->first() : null;
        if (! $media) {
            throw ValidationException::withMessages(['export' => ['El recurso final no pertenece al trabajo.']]);
        }
        $source = $this->registration->registrationSource($export);
        $output = $source->result;
        $this->registration->assertRegistrationPin($workspace, $media, $output);
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
    }

    /** Fases de fichero (`asset`, `font`, `normalize`, `render`, `mix`, `assemble`): el checkpoint debe ser el fichero esperado de su lease. */
    private function assertCheckpointFile(StudioAvLease $current, DesignExport $export, array $result): void
    {
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
}
