<?php

namespace App\Services\Studio;

use App\Models\Studio\StudioAvLease;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Validation\ValidationException;
use Throwable;

class StudioAvRunner
{
    public function __construct(private readonly StudioAvState $state, private readonly StudioAvQueue $queue,
        private readonly StudioAvFiles $files, private readonly StudioAvProcess $process, private readonly StudioAvTransfer $transfer) {}

    public function run(string $id, ?float $deadline = null): bool
    {
        $lease = $this->state->begin($id);
        if (! $lease) {
            return false;
        }
        $export = $this->state->workingExport($lease);
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
                    if (! $this->state->defer($lease)) {
                        return false;
                    }
                    $this->queue->enqueue($export->id);

                    return true;
                }
            } elseif (in_array($lease->phase, ['asset', 'font'], true)) {
                $result = $this->files->snapshot($lease, $deadline);
            } elseif ($lease->phase === 'verify') {
                $task = $this->state->task($export);
                $source = StudioAvLease::where('workspace_id', $lease->workspace_id)->findOrFail($task['source']);
                try {
                    $result = $this->files->verify($source, $deadline);
                } catch (StudioAvCheckpointCorrupt) {
                    $this->state->rejectCheckpoint($lease);

                    return false;
                }
            } else {
                $result = $this->process->execute($lease, $deadline);
                if ($lease->phase !== 'plan') {
                    $this->files->inspect($lease, $result, $deadline);
                }
            }
            if (! $this->state->accept($lease, $result, $lease->category === 'none' ? 0 : $result['size'])) {
                return false;
            }
            $advanced = true;
            $input = $source ?? $lease;
            if (in_array($input->phase, ['asset', 'font'], true)) {
                $this->files->publishSource($input, $deadline);
            }
            $this->queue->enqueue($export->id);

            return true;
        } catch (StudioAvWorkspaceBusy $e) {
            if (! $advanced) {
                $this->state->defer($lease);

                return false;
            }
            $code = 'phase_failed';
        } catch (StudioAvProcessFailed $e) {
            $code = $e->errorCode;
        } catch (ProcessTimedOutException) {
            $code = 'phase_timeout';
        } catch (ModelNotFoundException) {
            $code = 'missing_resource';
        } catch (ValidationException) {
            $code = 'output_budget';
        } catch (StudioRenderFailure) {
            $code = 'source_changed';
        } catch (Throwable) {
            $code = 'phase_failed';
        }
        if ($advanced) {
            $this->state->failPosition($export->id, $lease->generation, $export->render_state['position'] + 1, $code);
        } else {
            $this->state->fail($lease, $code);
        }

        return false;
    }
}
