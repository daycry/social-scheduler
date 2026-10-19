<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Services\ReportService;
use Illuminate\Console\Command;

class PruneReports extends Command
{
    protected $signature = 'reports:prune';

    protected $description = 'Marca como fallidos los informes atascados y purga (fila + fichero) los que superan reports.retention_days';

    public function handle(): int
    {
        $stuckBefore = now()->subHours((int) config('reports.stuck_hours', 2));

        $stuck = Report::withoutUserScope()
            ->whereIn('status', [Report::STATUS_PENDING, Report::STATUS_PROCESSING])
            ->where('updated_at', '<', $stuckBefore)
            ->update(['status' => Report::STATUS_FAILED, 'error' => ReportService::STUCK_ERROR]);

        $purged = 0;

        Report::withoutUserScope()
            ->where('created_at', '<', now()->subDays((int) config('reports.retention_days', 30)))
            ->whereNotIn('status', [Report::STATUS_PENDING, Report::STATUS_PROCESSING])
            ->each(function (Report $report) use (&$purged) {
                $report->delete(); // el evento `deleting` borra el fichero
                $purged++;
            });

        $this->info("Informes atascados marcados: {$stuck}. Purgados por retencion: {$purged}.");

        return self::SUCCESS;
    }
}
