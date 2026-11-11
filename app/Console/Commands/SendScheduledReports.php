<?php

namespace App\Console\Commands;

use App\Jobs\SendScheduledReport;
use App\Models\Brand;
use App\Models\ReportSchedule;
use Illuminate\Console\Command;

class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = 'Encola el envio por email de los informes programados que ya tocan (semanal o mensual)';

    public function handle(): int
    {
        $sent = 0;

        ReportSchedule::withoutUserScope()->where('active', true)->where('next_run_at', '<=', now())
            ->orderBy('id')->chunkById(100, function ($schedules) use (&$sent) {
                foreach ($schedules as $schedule) {
                    $timezone = Brand::withoutUserScope()->whereKey($schedule->brand_id)->value('timezone');
                    $next = ReportSchedule::nextRun($schedule->frequency, $timezone);

                    // Reclamo atomico: si otra pasada ya adelanto la fecha, esta no lo envia dos veces.
                    $claimed = ReportSchedule::withoutUserScope()->whereKey($schedule->id)->where('next_run_at', $schedule->next_run_at)->update(['next_run_at' => $next]);

                    if ($claimed === 1) {
                        SendScheduledReport::dispatch($schedule->id);
                        $sent++;
                    }
                }
            });

        $this->info("Informes programados encolados: {$sent}.");

        return self::SUCCESS;
    }
}
