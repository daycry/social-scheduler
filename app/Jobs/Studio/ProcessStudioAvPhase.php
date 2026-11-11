<?php

namespace App\Jobs\Studio;

use App\Services\Studio\StudioAvRunner;
use App\Services\Studio\StudioAvState;
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
        app(StudioAvState::class)->fail((object) ['id' => $this->leaseId], 'phase_failed');
    }
}
