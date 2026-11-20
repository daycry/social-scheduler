<?php

namespace App\Services\Studio;

use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvLease;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Validation\ValidationException;
use Throwable;

class StudioAvRunner
{
    public function __construct(private readonly StudioAvAcceptance $acceptance, private readonly StudioAvLeases $leases, private readonly StudioAvLifecycle $lifecycle, private readonly StudioAvStore $store, private readonly StudioAvQueue $queue,
        private readonly StudioAvFiles $files, private readonly StudioAvProcess $process, private readonly StudioAvTransfer $transfer) {}

    public function run(string $id, ?float $deadline = null): bool
    {
        $lease = $this->leases->begin($id);
        if (! $lease) {
            return false;
        }
        $export = $this->leases->workingExport($lease);
        $deadline = min($deadline ?? microtime(true) + 85, microtime(true) + 85);
        $advanced = false;
        $source = null;
        try {
            if (microtime(true) >= $deadline) {
                throw new StudioAvProcessFailed('phase_timeout');
            }
            if ($lease->phase === 'register') {
                $result = $this->transfer->run($lease, $deadline);
                if ($result === null) {
                    if (! $this->leases->defer($lease)) {
                        return false;
                    }
                    $this->queue->enqueue($export->id);

                    return true;
                }
            } elseif (in_array($lease->phase, ['asset', 'font'], true)) {
                $result = $this->files->snapshot($lease, $deadline);
            } elseif ($lease->phase === 'verify') {
                $task = $this->store->task($export);
                $source = StudioAvLease::where('workspace_id', $lease->workspace_id)->findOrFail($task['source']);
                try {
                    $result = $this->files->verify($source, $deadline);
                } catch (StudioAvCheckpointCorrupt) {
                    $this->acceptance->rejectCheckpoint($lease);

                    return false;
                }
            } else {
                $result = $this->process->execute($lease, $deadline);
                if ($lease->phase !== 'plan') {
                    $this->files->inspect($lease, $result, $deadline);
                }
            }
            if (! $this->acceptance->accept($lease, $result, $lease->category === 'none' ? 0 : $result['size'])) {
                return false;
            }
            $advanced = true;
            $input = $source ?? $lease;
            if (in_array($input->phase, ['asset', 'font'], true)) {
                $this->files->publishSource($input, $deadline);
            }
            $this->queue->enqueue($export->id);

            return true;
        } catch (Throwable $error) {
            return $this->handlePhaseError($lease, $export, $advanced, $error);
        }
    }

    private function handlePhaseError(StudioAvLease $lease, DesignExport $export, bool $advanced, Throwable $error): bool
    {
        if ($error instanceof StudioAvWorkspaceBusy && ! $advanced) {
            $this->leases->defer($lease);

            return false;
        }

        $code = match (true) {
            $error instanceof StudioAvProcessFailed => $error->errorCode,
            $error instanceof ProcessTimedOutException => 'phase_timeout',
            $error instanceof ModelNotFoundException => 'missing_resource',
            $error instanceof ValidationException => 'output_budget',
            $error instanceof StudioRenderFailure => 'source_changed',
            default => 'phase_failed',
        };

        if ($advanced) {
            $this->lifecycle->failPosition($export->id, $lease->generation, $export->render_state['position'] + 1, $code);
        } else {
            $this->lifecycle->fail($lease, $code);
        }

        return false;
    }
}
