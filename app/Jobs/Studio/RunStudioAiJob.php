<?php

namespace App\Jobs\Studio;

use App\Models\Studio\StudioAiJob;
use App\Services\JobFailureNotifier;
use App\Services\Studio\StudioAi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunStudioAiJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Plazos encadenados: job (110 s) < --timeout del worker (120 s) < retry_after (180 s); ver docs/arquitectura.md. */
    public int $timeout = 110;

    public function __construct(public readonly int $aiJobId)
    {
        $this->onQueue('media');
    }

    public function handle(StudioAi $ai): void
    {
        $ai->process($this->aiJobId);
    }

    public function failed(?\Throwable $exception): void
    {
        $closed = StudioAiJob::withoutUserScope()->whereKey($this->aiJobId)->whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'failed', 'error_code' => 'processing_interrupted', 'error' => StudioAi::errorMessage('processing_interrupted')]);

        if ($closed === 1 && $job = StudioAiJob::withoutUserScope()->find($this->aiJobId)) {
            app(JobFailureNotifier::class)->ai($job);
        }
    }
}
