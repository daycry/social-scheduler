<?php

namespace App\Jobs;

use App\Mail\ScheduledReportMail;
use App\Models\Brand;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Services\JobFailureNotifier;
use App\Services\Reports\ReportBlocks;
use App\Services\ReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * Genera el PDF de una programacion (periodo cerrado anterior) y lo envia a cada destinatario por separado (nadie ve las
 * direcciones de los demas). Limite diario de correos por usuario (`reports.mail_daily_limit`): si no cabe, no se envia nada y
 * queda anotado en la programacion. Cola `reports`, sin reintentos (el siguiente envio es el de la proxima fecha).
 */
class SendScheduledReport implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 100;

    public int $tries = 1;

    public function __construct(public readonly int $scheduleId)
    {
        $this->onQueue((string) config('reports.queue', 'reports'));
    }

    public function handle(ReportService $reports): void
    {
        $schedule = ReportSchedule::withoutUserScope()->find($this->scheduleId);

        if (! $schedule || ! $schedule->active) {
            return;
        }

        // Job sin sesion: la plantilla y la marca se leen del propietario de la programacion, no del usuario autenticado.
        $template = ReportTemplate::withoutUserScope()->where('user_id', $schedule->user_id)->find($schedule->report_template_id);

        $user = User::find($schedule->user_id);
        $brand = Brand::withoutUserScope()->where('user_id', $schedule->user_id)->find($schedule->brand_id);

        if (! $user || ! $brand || ! $template || (int) $template->brand_id !== (int) $brand->id) {
            $schedule->update(['active' => false, 'last_error' => 'La marca o la plantilla ya no existen.']);

            return;
        }

        $recipients = array_values((array) $schedule->recipients);
        $key = 'report-mail:'.$user->id;
        $limit = (int) config('reports.mail_daily_limit', 20);

        // Cupo reservado de forma atomica ANTES de generar el PDF (dos envios simultaneos no lo sobrepasan); se libera si falla.
        $reserved = count($recipients);

        if (RateLimiter::increment($key, 86400, $reserved) > $limit) {
            RateLimiter::decrement($key, 86400, $reserved);
            $schedule->update(['last_run_at' => now(), 'last_error' => 'Se alcanzo el limite diario de correos de informes. No se envio este informe.']);

            return;
        }

        $period = ReportSchedule::periodFor($schedule->frequency, $brand->timezone);
        try {
            $report = $reports->create($user, $brand, 'pdf', $period, (string) ($template->compare ?: 'none'), ReportBlocks::normalize($template->blocks), $schedule->id);
            $reports->generate($report);
        } catch (\Throwable $e) {
            RateLimiter::decrement($key, 86400, $reserved);

            throw $e;
        }

        if ($report->status !== Report::STATUS_READY) {
            RateLimiter::decrement($key, 86400, $reserved);
            $schedule->update(['last_run_at' => now(), 'last_error' => ReportService::GENERIC_ERROR]);
            app(JobFailureNotifier::class)->report($schedule);

            return;
        }

        $hours = max(1, (int) config('reports.mail_link_hours', 72));
        $url = $report->signedDownloadUrl($hours * 60, $schedule->id);

        foreach ($recipients as $to) {
            $unsubscribe = URL::signedRoute('report-schedules.unsubscribe', ['schedule' => $schedule->id, 'email' => $to]);
            Mail::to($to)->send(new ScheduledReportMail($report, $brand->name, $url, $hours, (string) $user->name, (string) $user->email, $unsubscribe));
        }

        $schedule->update(['last_run_at' => now(), 'last_error' => null]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('report.schedule_failed', ['schedule_id' => $this->scheduleId, 'error' => $e->getMessage()]);
        ReportSchedule::withoutUserScope()->whereKey($this->scheduleId)->update(['last_run_at' => now(), 'last_error' => ReportService::GENERIC_ERROR]);

        if ($schedule = ReportSchedule::withoutUserScope()->find($this->scheduleId)) {
            app(JobFailureNotifier::class)->report($schedule);
        }
    }
}
