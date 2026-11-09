<?php

namespace App\Services\Studio;

use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvWorkspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

class StudioAvRecovery
{
    public function __construct(private readonly StudioAvQueue $queue, private readonly StudioAvCleanup $cleanup,
        private readonly StudioAvState $state, private readonly StudioAvRegistrationCleanup $registrationCleanup) {}

    public function run(int $limit = 25): array
    {
        $result = ['enqueued' => 0, 'leases_released' => 0, 'orphans_removed' => 0, 'deferred' => 0];
        $limit = max(1, min(100, $limit));
        $deadline = microtime(true) + 15;
        // A retry can hold a new native lease while the old library copy is retiring. Use a separate due time.
        $retiring = StudioAvWorkspace::withoutUserScope()->where('media_retiring', true)->where('media_cleanup_after', '<=', now())
            ->orderBy('media_cleanup_after')->limit($limit)->get(['id']);
        foreach ($retiring as $workspace) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                if (! $this->registrationCleanup->run($workspace->id)) {
                    $result['deferred']++;
                }
            } catch (Throwable) {
                $result['deferred']++;
            }
            $this->deferRetirement($workspace->id);
        }
        // All already-retiring copies belong exclusively to the due-time pass above.
        $orphans = StudioAvWorkspace::withoutUserScope()->where('media_retiring', false)->where('active_until', '<=', now())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('studio_design_exports')->whereColumn('studio_design_exports.id', 'studio_av_workspaces.export_id'))
            ->orderBy('active_until')->limit($limit)->get(['id']);
        foreach ($orphans as $workspace) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                $this->cleanup->orphan($workspace->id) ? $result['orphans_removed']++ : $result['deferred']++;
            } catch (Throwable) {
                $result['deferred']++;
            }
            $this->deferRetirement($workspace->id);
            StudioAvWorkspace::withoutUserScope()->whereKey($workspace->id)->where('active_until', '<=', now())
                ->update(['active_until' => now()->addSeconds(180)]);
        }
        $terminal = StudioAvWorkspace::withoutUserScope()->where('media_retiring', false)->where('active_until', '<=', now())
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('studio_design_exports')->whereColumn('studio_design_exports.id', 'studio_av_workspaces.export_id')
                ->whereIn('studio_design_exports.status', ['failed', 'cancelled']))
            ->where(fn ($q) => $q->where('media_reserved_bytes', '>', 0)->orWhereExists(fn ($leases) => $leases->selectRaw('1')->from('studio_av_leases')->whereColumn('studio_av_leases.workspace_id', 'studio_av_workspaces.id')
                ->whereIn('status', ['running', 'failed', 'abandoned', 'invalid'])->where('expires_at', '<=', now())))
            ->orderBy('active_until')->limit($limit)->get(['id']);
        foreach ($terminal as $workspace) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                if (! $this->registrationCleanup->run($workspace->id)) {
                    $result['deferred']++;
                }
            } catch (Throwable) {
                $result['deferred']++;
            }
            $this->deferRetirement($workspace->id);
            $leases = StudioAvLease::where('workspace_id', $workspace->id)->whereIn('status', ['running', 'failed', 'abandoned', 'invalid'])
                ->where('expires_at', '<=', now())->orderBy('expires_at')->limit(32)->get(['id']);
            foreach ($leases as $lease) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                try {
                    $this->cleanup->lease($lease->id) ? $result['leases_released']++ : $result['deferred']++;
                } catch (Throwable) {
                    $result['deferred']++;
                }
            }
            StudioAvWorkspace::withoutUserScope()->whereKey($workspace->id)->where('active_until', '<=', now())->update(['active_until' => now()->addSeconds(180)]);
        }
        $workspaces = StudioAvWorkspace::withoutUserScope()->where('active_until', '<=', now())
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('studio_design_exports')->whereColumn('studio_design_exports.id', 'studio_av_workspaces.export_id')
                ->whereIn('studio_design_exports.status', ['pending', 'processing'])->whereNotNull('render_state'))
            ->orderBy('active_until')->limit($limit)->get(['id', 'export_id']);
        foreach ($workspaces as $workspace) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $export = DesignExport::withoutUserScope()->find($workspace->export_id);
            if (! $export || ! $export->render_state) {
                continue;
            }
            try {
                $ready = true;
                $old = StudioAvLease::where('workspace_id', $workspace->id)->whereIn('status', ['running', 'failed', 'abandoned', 'invalid'])
                    ->where('expires_at', '<=', now())->orderBy('expires_at')->limit(32)->get();
                foreach ($old as $lease) {
                    if (microtime(true) >= $deadline || ! $this->cleanup->lease($lease->id)) {
                        $ready = false;
                        break;
                    }
                    $result['leases_released']++;
                }
                if ($ready) {
                    $ready = ! StudioAvLease::where('workspace_id', $workspace->id)->whereIn('status', ['running', 'failed', 'abandoned', 'invalid'])
                        ->where('expires_at', '<=', now())->exists();
                }
                if ($ready) {
                    foreach ($this->state->checkpoints($export, ['asset', 'font'], true) as $source) {
                        if (microtime(true) >= $deadline) {
                            $ready = false;
                            break;
                        }
                        app(StudioAvFiles::class)->publishSource($source);
                    }
                    $ready = $ready && $this->state->checkpoints($export, ['asset', 'font'], true)->isEmpty();
                }
                if ($ready && $this->queue->enqueue($export->id)) {
                    $result['enqueued']++;
                } else {
                    $result['deferred']++;
                }
            } catch (StudioAvCheckpointCorrupt) {
                $this->state->failPosition($export->id, $export->render_state['generation'], $export->render_state['position'], 'phase_failed');
                $result['deferred']++;
            } catch (ModelNotFoundException) {
                $this->state->failPosition($export->id, $export->render_state['generation'], $export->render_state['position'], 'missing_resource');
                $result['deferred']++;
            } catch (ValidationException) {
                $this->state->failPosition($export->id, $export->render_state['generation'], $export->render_state['position'], 'output_budget');
                $result['deferred']++;
            } catch (Throwable) {
                $result['deferred']++;
            }
            // A worker-down export must not monopolize every bounded pass ahead of other brands.
            StudioAvWorkspace::withoutUserScope()->whereKey($workspace->id)->where('active_until', '<=', now())
                ->update(['active_until' => now()->addSeconds(180)]);
        }

        return $result;
    }

    private function deferRetirement(int $id): void
    {
        StudioAvWorkspace::withoutUserScope()->whereKey($id)->where('media_retiring', true)
            ->update(['media_cleanup_after' => now()->addSeconds(180)]);
    }
}
