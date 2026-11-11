<?php

namespace App\Services\Studio;

use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvWorkspace;
use Illuminate\Support\Facades\DB;

class StudioAvCleanup
{
    public function __construct(private readonly StudioAvStorage $storage) {}

    public function lease(string $id): bool
    {
        $lease = StudioAvLease::find($id);
        if (! $lease) {
            return false;
        }
        try {
            return $this->storage->locked($lease->workspace_id, function (StudioAvWorkspace $workspace) use ($id) {
                $candidate = DB::transaction(function () use ($workspace, $id) {
                    StudioAvWorkspace::withoutUserScope()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
                    $current = StudioAvLease::where('workspace_id', $workspace->id)->whereKey($id)->lockForUpdate()->first();
                    if (! $current || $current->expires_at->isFuture()) {
                        return null;
                    }
                    if (app(StudioAvQueue::class)->pending($current)) {
                        return null;
                    }
                    if ($current->status === 'running') {
                        $current->update(['status' => 'abandoned']);
                    }

                    return in_array($current->status, ['abandoned', 'failed', 'invalid'], true) ? $current : null;
                });
                if (! $candidate) {
                    return false;
                }
                $this->storage->removeLease($workspace, $candidate);

                return DB::transaction(function () use ($workspace, $candidate) {
                    StudioAvWorkspace::withoutUserScope()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
                    $current = StudioAvLease::where('workspace_id', $workspace->id)->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    if ($current->status !== $candidate->status) {
                        return false;
                    }
                    $current->update(['status' => 'released', 'actual_bytes' => 0]);

                    return true;
                });
            });
        } catch (StudioAvWorkspaceBusy) {
            return false;
        }
    }

    public function orphan(int $id): bool
    {
        $candidate = StudioAvWorkspace::withoutUserScope()->find($id);
        if (! $candidate || DesignExport::withoutUserScope()->whereKey($candidate->export_id)->exists()) {
            return false;
        }
        if (! app(StudioAvRegistrationCleanup::class)->run($id)) {
            return false;
        }
        try {
            return $this->storage->locked($id, function (StudioAvWorkspace $workspace) {
                $eligible = DB::transaction(function () use ($workspace) {
                    $current = StudioAvWorkspace::withoutUserScope()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

                    return ! DesignExport::withoutUserScope()->whereKey($current->export_id)->exists()
                        && ! $current->media_retiring && $current->media_reserved_bytes === 0
                        && ! $current->active_until->isFuture()
                        && ! StudioAvLease::where('workspace_id', $current->id)->where('expires_at', '>', now())->exists();
                });
                if (! $eligible) {
                    return false;
                }
                $this->storage->removeWorkspace($workspace);

                return DB::transaction(function () use ($workspace) {
                    $current = StudioAvWorkspace::withoutUserScope()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
                    if (DesignExport::withoutUserScope()->whereKey($current->export_id)->exists()) {
                        return false;
                    }
                    $current->delete();

                    return true;
                });
            });
        } catch (StudioAvWorkspaceBusy) {
            return false;
        }
    }
}
