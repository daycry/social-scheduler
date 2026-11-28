<?php

namespace App\Jobs\Studio;

use App\Services\Studio\StudioAvLifecycle;
use App\Services\Studio\StudioAvRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessStudioAvPhase implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 100;

    public function __construct(public readonly string $leaseId)
    {
        $this->onConnection('database')->onQueue(config('studio.av.queue', 'studio'));
    }

    public function handle(StudioAvRunner $runner): void
    {
        $runner->run($this->leaseId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(StudioAvLifecycle::class)->fail((object) ['id' => $this->leaseId], 'phase_failed');
    }
}
