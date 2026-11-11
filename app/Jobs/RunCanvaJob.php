<?php

namespace App\Jobs;

use App\Models\Canva\CanvaJob;
use App\Services\Canva\CanvaJobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Un paso de un trabajo de Canva (T-16): crea o sondea el job remoto y, si sigue en curso, se reencola con espera. El sondeo esta
 * acotado por `services.canva.max_polls`; al agotarlo el trabajo queda `failed` con su motivo. Un fallo transitorio de Canva se reintenta
 * con espera y, agotados los intentos, `failed()` deja el trabajo en `failed` con un mensaje neutro.
 */
class RunCanvaJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 100;

    public int $tries = 4;

    public function __construct(public readonly int $jobId)
    {
        $this->onQueue((string) config('media.import_queue', 'media'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(CanvaJobs $jobs): void
    {
        if ($jobs->process($this->jobId)) {
            static::dispatch($this->jobId)->delay(now()->addSeconds((int) config('services.canva.poll_delay', 3)));
        }
    }

    public function failed(?Throwable $e): void
    {
        $job = CanvaJob::withoutUserScope()->find($this->jobId);
        if ($job === null || ! $job->isActive()) {
            return;
        }
        $job->forceFill(['status' => CanvaJob::FAILED, 'error_code' => 'job_failed', 'error' => 'No se pudo completar el trabajo con Canva. Inténtalo de nuevo más tarde.'])->save();
    }
}
