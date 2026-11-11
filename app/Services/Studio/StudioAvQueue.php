<?php

namespace App\Services\Studio;

use App\Jobs\Studio\ProcessStudioAvPhase;
use App\Models\Brand;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Throwable;

class StudioAvQueue
{
    public function __construct(private readonly StudioAvState $state) {}

    public function enqueue(int $exportId): bool
    {
        $export = DesignExport::withoutUserScope()->find($exportId);
        if (! $export || ! in_array($export->status, ['pending', 'processing'], true) || ! $export->render_state) {
            return false;
        }
        $old = isset($export->render_state['lease']) ? StudioAvLease::find($export->render_state['lease']) : null;
        if ($old && $this->pending($old)) {
            return false;
        }
        $task = $this->state->task($export);
        if (! $task) {
            return false;
        }
        $lease = $this->state->claim($exportId, $export->render_state['generation'], $this->bytes($export, $task), $export->render_state['position']);
        if (! $lease) {
            return false;
        }
        try {
            $id = Queue::connection('database')->push(new ProcessStudioAvPhase($lease->id), queue: config('studio.av.queue', 'studio'));
            if (is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0) {
                StudioAvLease::whereKey($lease->id)->update(['queue_job_id' => (int) $id]);
            }
        } catch (Throwable) {
            $this->state->fail($lease, 'queue_unavailable');

            return false;
        }

        return true;
    }

    public function pending(StudioAvLease $lease): bool
    {
        return $lease->status === 'running' && $lease->started_at === null && $lease->queue_job_id !== null
            && DB::connection(config('queue.connections.database.connection'))->table(config('queue.connections.database.table', 'jobs'))
                ->where('id', $lease->queue_job_id)->exists();
    }

    private function bytes(DesignExport $export, array $task): int
    {
        if (in_array($task['phase'], ['plan', 'verify', 'register'], true)) {
            return 0;
        }
        if (in_array($task['phase'], ['asset', 'font', 'normalize'], true)) {
            $user = User::findOrFail($export->user_id);
            $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($export->brand_id);
            $source = $task['phase'] === 'font' ? app(StudioFonts::class)->owned($user, $brand, $task['index'])
                : app(StudioAssets::class)->owned($user, $brand, $task['index']);
            $bytes = $task['phase'] === 'normalize' ? (int) round($source->duration * 48000) * 8 : $source->size;
            if (! is_int($bytes) || $bytes < 1 || $bytes > ($task['phase'] === 'normalize' ? 8 : 2) * 1024 ** 3) {
                throw ValidationException::withMessages(['export' => ['El origen supera el presupuesto de esta fase.']]);
            }

            return $bytes;
        }
        if ($task['phase'] === 'render') {
            return 512 * 1024 ** 2;
        }
        if ($task['phase'] === 'assemble') {
            return 8 * 1024 ** 3;
        }
        $plan = StudioAvWorkspace::withoutUserScope()->whereKey($export->render_state['allocation_id'])->value('plan');
        $plan = is_string($plan) ? json_decode($plan, true, flags: JSON_THROW_ON_ERROR) : $plan;
        $frames = min($plan['segmentFrames'], $plan['frameCount'] - $task['index'] * $plan['segmentFrames']);

        return 44 + (int) round($frames * 48000 / $plan['fps']) * 8;
    }
}
