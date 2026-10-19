<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\ReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Genera un informe en la cola `reports` (T-39/T-40). `$timeout` 100 s < `--timeout` del worker (120 s); sin
 * reintentos automaticos (un fallo queda en el informe como `failed` y el usuario lo regenera). Concurrencia 1 la
 * da el worker unico de la cola `reports`, que acota el uso de memoria del render.
 */
class GenerateReport implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 100;

    public int $tries = 1;

    public function __construct(public readonly int $reportId)
    {
        $this->onQueue((string) config('reports.queue', 'reports'));
    }

    public function handle(ReportService $reports): void
    {
        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        $report = Report::withoutUserScope()->find($this->reportId);

        if (! $report || $report->status !== Report::STATUS_PENDING) {
            return;
        }

        $reports->generate($report);
    }

    public function failed(\Throwable $e): void
    {
        // Detalle al log; el usuario solo ve un mensaje generico.
        Log::error('report.job_failed', ['report_id' => $this->reportId, 'error' => $e->getMessage()]);
        Report::withoutUserScope()->whereKey($this->reportId)->update(['status' => Report::STATUS_FAILED, 'error' => ReportService::GENERIC_ERROR]);
    }
}
