<?php

namespace App\Services\Studio;

use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvStep;
use App\Models\Studio\StudioAvWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Ciclo de vida del trabajo audiovisual: inicializar, cancelar, reintentar y marcar como fallido (por arrendamiento o por posicion). */
class StudioAvLifecycle
{
    public function __construct(private readonly StudioAvStore $store) {}

    public function initialize(User $user, DesignExport $export): DesignExport
    {
        return DB::transaction(function () use ($user, $export) {
            $this->store->reserveSqliteWriter('studio_design_exports', $export->id);
            $current = DesignExport::withoutUserScope()->where('user_id', $user->id)->whereKey($export->id)->lockForUpdate()->firstOrFail();
            $document = $this->store->pinnedDocument($current);
            if (! in_array($current->format, ['mp4', 'gif'], true)) {
                throw ValidationException::withMessages(['export' => ['Este trabajo no es una exportación audiovisual.']]);
            }
            $documentHash = hash('sha256', json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $fingerprint = $this->store->fingerprint($current, $documentHash);
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
            $this->store->saveState($current, ['version' => 1, 'generation' => $generation, 'workspace' => $workspace->uuid, 'allocation_id' => $workspace->id,
                'fingerprint' => $fingerprint, 'document_hash' => $documentHash, 'position' => 0, 'total' => 1,
                'lease' => null, 'reuse_generation' => null, 'cancelled_lease' => null]);

            return $current;
        });
    }

    public function cancel(User $user, DesignExport $export): DesignExport
    {
        return DB::transaction(function () use ($user, $export) {
            $this->store->reserveSqliteWriter('studio_design_exports', $export->id);
            $current = $this->store->ownedLocked($user, $export);
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
            $this->store->saveState($current, $state, ['status' => 'cancelled', 'error' => null]);

            return $current;
        });
    }

    public function retry(User $user, DesignExport $export): DesignExport
    {
        return DB::transaction(function () use ($user, $export) {
            $this->store->reserveSqliteWriter('studio_design_exports', $export->id);
            $current = $this->store->ownedLocked($user, $export);
            if (! in_array($current->status, ['failed', 'cancelled'], true)) {
                abort(409, 'El trabajo todavía está activo o ya tiene un resultado listo.');
            }
            $workspace = $this->store->workspace($current);
            $document = $this->store->pinnedDocument($current);
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
            $this->store->saveState($current, $state, ['status' => 'pending', 'error' => null]);

            return $current;
        });
    }

    public function fail(object $lease, string $code): bool
    {
        return DB::transaction(function () use ($lease, $code) {
            $this->store->reserveSqliteWriter('studio_av_leases', $lease->id);
            [$export, , $current] = $this->store->activeLease($lease, false);
            if (! $current) {
                return false;
            }
            $message = $this->failureMessage($code);
            $current->update(['status' => 'failed']);
            $state = $export->render_state;
            $state['lease'] = null;
            $this->store->saveState($export, $state, ['status' => 'failed', 'error' => $message]);

            return true;
        });
    }

    public function failPosition(int $id, string $generation, int $position, string $code): bool
    {
        return DB::transaction(function () use ($id, $generation, $position, $code) {
            $this->store->reserveSqliteWriter('studio_design_exports', $id);
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
            $this->store->saveState($export, $state, ['status' => 'failed', 'error' => $this->failureMessage($code)]);

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
}
