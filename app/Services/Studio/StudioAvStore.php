<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignRevision;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvStep;
use App\Models\Studio\StudioAvWorkspace;
use App\Models\User;
use App\Services\JobFailureNotifier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Primitivas transaccionales compartidas del estado audiovisual privado: serializar al escritor SQLite, resolver el arrendamiento activo y su espacio de trabajo, guardar el estado y progreso del export y leer los checkpoints aceptados. */
class StudioAvStore
{
    public const WORKSPACE_COLUMNS = ['id', 'user_id', 'brand_id', 'export_id', 'uuid', 'fingerprint', 'media_id', 'media_reserved_bytes', 'media_receipt', 'media_retiring', 'active_until', 'created_at', 'updated_at'];

    public function reserveSqliteWriter(string $table, int|string $id): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Reserve the writer before reading fenced state; SQLite cannot wait
            // safely when upgrading a shared reader against another session writer.
            DB::table($table)->where('id', $id)->update(['id' => DB::raw('id')]);
        }
    }

    public function activeLease(object $lease, bool $requireOwner = true): array
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

    public function workspace(DesignExport $export): StudioAvWorkspace
    {
        $state = $export->render_state;
        $workspace = StudioAvWorkspace::ownedBy($export->user_id)->select(self::WORKSPACE_COLUMNS)->whereKey($state['allocation_id'])->where('export_id', $export->id)
            ->where('brand_id', $export->brand_id)->where('uuid', $state['workspace'])->lockForUpdate()->firstOrFail();
        if ($workspace->fingerprint !== ($state['fingerprint'] ?? null) || $this->fingerprint($export, $state['document_hash']) !== $workspace->fingerprint) {
            throw ValidationException::withMessages(['export' => ['La revisión u opciones cambiaron; inicia un trabajo nuevo.']]);
        }

        return $workspace;
    }

    public function step(array $state): ?StudioAvStep
    {
        return StudioAvStep::where('workspace_id', $state['allocation_id'])->where('generation', $state['generation'])
            ->where('position', $state['position'])->first();
    }

    public function saveState(DesignExport $export, array $state, array $changes = []): void
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
        if (($changes['status'] ?? null) === 'ready') {
            DB::afterCommit(fn () => app(JobFailureNotifier::class)->exportDone($export));
        }
        if (($changes['status'] ?? null) === 'failed') {
            DB::afterCommit(fn () => app(JobFailureNotifier::class)->export($export)); // tras confirmar la transaccion que cierra el export
        }
    }

    public function assertOwnership(DesignExport $export): void
    {
        if (! User::whereKey($export->user_id)->exists() || ! Brand::ownedBy($export->user_id)->whereKey($export->brand_id)->exists()
            || ! Design::ownedBy($export->user_id)->where('brand_id', $export->brand_id)->whereKey($export->design_id)->exists()) {
            throw (new ModelNotFoundException)->setModel(DesignExport::class, [$export->id]);
        }
    }

    public function fingerprint(DesignExport $export, string $documentHash): string
    {
        return hash('sha256', json_encode([$export->user_id, $export->brand_id, $export->design_id, $export->revision,
            $export->format, $export->pages, $export->options, $documentHash], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function ownedLocked(User $user, DesignExport $export): DesignExport
    {
        $current = DesignExport::ownedBy($user->id)->whereKey($export->id)->lockForUpdate()->firstOrFail();
        if (! Brand::ownedBy($user->id)->whereKey($current->brand_id)->exists()) {
            throw (new ModelNotFoundException)->setModel(DesignExport::class, [$current->id]);
        }
        if (! in_array($current->format, ['mp4', 'gif'], true) || ! $current->render_state) {
            throw ValidationException::withMessages(['export' => ['El trabajo no admite gestión audiovisual.']]);
        }

        return $current;
    }

    public function pinnedDocument(DesignExport $export): array
    {
        $this->assertOwnership($export);

        return DesignRevision::ownedBy($export->user_id)->where('design_id', $export->design_id)
            ->where('revision', $export->revision)->firstOrFail()->document;
    }

    public function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value);
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

    /** Internal descriptor for orchestration; never part of the public export serializer. */
    public function task(DesignExport $export): ?array
    {
        return $export->render_state ? $this->step($export->render_state)?->descriptor() : null;
    }
}
