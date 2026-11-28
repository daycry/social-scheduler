<?php

namespace App\Services\Studio;

use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvWorkspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Arrendamientos de fase: reclamar el siguiente paso con su reserva de disco, arrancarlo, aplazarlo y exponer el export de un arrendamiento vigente. */
class StudioAvLeases
{
    public function __construct(private readonly StudioAvStore $store) {}

    public function begin(string $id): ?StudioAvLease
    {
        return DB::transaction(function () use ($id) {
            $this->store->reserveSqliteWriter('studio_av_leases', $id);
            $candidate = StudioAvLease::find($id);
            $allocation = $candidate ? StudioAvWorkspace::withoutUserScope()->select(StudioAvStore::WORKSPACE_COLUMNS)->find($candidate->workspace_id) : null;
            $export = $allocation ? DesignExport::withoutUserScope()->whereKey($allocation->export_id)->lockForUpdate()->first() : null;
            if (! $export || $export->status !== 'processing' || ($export->render_state['lease'] ?? null) !== $id
                || ($export->render_state['generation'] ?? null) !== $candidate->generation) {
                return null;
            }
            $workspace = $this->store->workspace($export);
            $this->store->assertOwnership($export);
            $current = StudioAvLease::whereKey($id)->lockForUpdate()->first();
            $step = $this->store->step($export->render_state);
            if (! $current || $current->status !== 'running' || $current->started_at !== null || ! $step
                || $step->phase !== $current->phase || $step->item !== $current->item || $step->lease_id !== $id) {
                return null;
            }
            $current->update(['started_at' => now(), 'expires_at' => now()->addSeconds(180)]);
            $workspace->update(['active_until' => $current->expires_at]);

            return $current;
        });
    }

    public function claim(int $exportId, string $generation, int $bytes = 0, ?int $position = null): ?StudioAvLease
    {
        return DB::transaction(function () use ($exportId, $generation, $bytes, $position) {
            $this->store->reserveSqliteWriter('studio_design_exports', $exportId);
            $export = DesignExport::withoutUserScope()->whereKey($exportId)->lockForUpdate()->first();
            if (! $export || ! in_array($export->status, ['pending', 'processing'], true) || ($export->render_state['generation'] ?? null) !== $generation
                || ($position !== null && ($export->render_state['position'] ?? null) !== $position)) {
                return null;
            }
            $workspace = $this->store->workspace($export);
            $this->store->assertOwnership($export);
            $state = $export->render_state;
            $old = isset($state['lease']) ? StudioAvLease::whereKey($state['lease'])->lockForUpdate()->first() : null;
            if ($old && $old->status === 'running' && $old->expires_at->isFuture()) {
                return null;
            }
            if ($old && $old->status === 'running') {
                $old->update(['status' => 'abandoned']);
            }
            $step = $this->store->step($state);
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
            if ($category !== 'none' && ! $this->store->canReserveBytes($workspace, $category, $bytes)) {
                throw ValidationException::withMessages(['export' => ['El trabajo supera su presupuesto de almacenamiento; limpia o reduce los recursos antes de reintentar.']]);
            }
            $lease = StudioAvLease::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'generation' => $generation,
                'phase' => $task['phase'], 'item' => $task['index'], 'category' => $category, 'reserved_bytes' => $bytes, 'expires_at' => now()->addSeconds(180)]);
            $state['lease'] = $lease->id;
            $step->update(['lease_id' => $lease->id]);
            $this->store->saveState($export, $state, ['status' => 'processing']);
            $workspace->update(['active_until' => $lease->expires_at]);

            return $lease;
        });
    }

    public function defer(object $lease): bool
    {
        return DB::transaction(function () use ($lease) {
            $this->store->reserveSqliteWriter('studio_av_leases', $lease->id);
            [$export, $workspace, $current] = $this->store->activeLease($lease);
            if (! $current) {
                return false;
            }
            $current->update(['status' => 'abandoned', 'expires_at' => now()]);
            $workspace->update(['active_until' => now()]);
            $state = $export->render_state;
            $state['lease'] = null;
            $this->store->saveState($export, $state, ['status' => 'processing']);

            return true;
        });
    }

    public function workingExport(StudioAvLease $lease): DesignExport
    {
        return DB::transaction(function () use ($lease) {
            [$export, , $current] = $this->store->activeLease($lease);
            if (! $current) {
                throw (new ModelNotFoundException)->setModel(StudioAvLease::class, [$lease->id]);
            }

            return $export;
        });
    }
}
