<?php

namespace App\Jobs\Studio;

use App\Models\Studio\StudioImageJob;
use App\Services\Studio\StudioImages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessStudioImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 110;

    public function __construct(public readonly int $imageJobId)
    {
        $this->onQueue('media');
    }

    public function handle(StudioImages $images): void
    {
        $images->process($this->imageJobId);
    }

    public function failed(?\Throwable $exception): void
    {
        StudioImageJob::withoutUserScope()->whereKey($this->imageJobId)->whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'failed', 'error_code' => 'processing_interrupted', 'error' => 'El procesamiento se interrumpió o superó el tiempo permitido. Puedes volver a intentarlo.']);
    }
}
