<?php

namespace App\Jobs;

use App\Services\Canva\CanvaJobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Un paso de un trabajo de Canva (T-16): crea o sondea el job remoto y, si sigue en curso, se reencola con espera. El sondeo esta
 * acotado por `services.canva.max_polls`; al agotarlo el trabajo queda `failed` con su motivo.
 */
class RunCanvaJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 100;

    public int $tries = 2;

    public function __construct(public readonly int $jobId)
    {
        $this->onQueue((string) config('media.import_queue', 'media'));
    }

    public function handle(CanvaJobs $jobs): void
    {
        if ($jobs->process($this->jobId)) {
            static::dispatch($this->jobId)->delay(now()->addSeconds((int) config('services.canva.poll_delay', 3)));
        }
    }
}
