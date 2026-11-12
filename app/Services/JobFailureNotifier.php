<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Canva\CanvaJob;
use App\Models\MediaAsset;
use App\Models\ReportSchedule;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAiJob;
use App\Models\User;
use App\Notifications\AsyncJobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisa al propietario (T-23) cuando un trabajo ASINCRONO falla: informe programado, exportacion MP4/GIF, IA, Canva o importacion remota.
 * Hasta ahora solo cambiaba su estado en su propio panel. Reutiliza el aviso comun (`Notifications/Index`, `n.url`); el correo solo con
 * `notify_by_mail`. Nunca rompe el job que lo llama y limita la frecuencia: un mismo trabajo avisa una sola vez (aunque fallen la cola
 * y el servicio) y un usuario recibe como mucho `BURST` avisos por tipo y hora (una tanda de importaciones rotas no inunda el buzon).
 */
class JobFailureNotifier
{
    public const BURST = 5;

    public function report(ReportSchedule $schedule): void
    {
        $brand = Brand::withoutUserScope()->find($schedule->brand_id);
        $this->send((int) $schedule->user_id, 'report', 'report:'.$schedule->id, 'No se pudo enviar un informe programado',
            (string) ($schedule->last_error ?: ReportService::GENERIC_ERROR), route('reports.index', [], false), (string) $brand?->name, $schedule->brand_id);
    }

    /** Solo las exportaciones de video (MP4/GIF): el resto las sigue el usuario en el propio editor. */
    public function export(DesignExport $export): void
    {
        if (! in_array((string) $export->format, ['mp4', 'gif'], true)) {
            return;
        }

        $name = (string) $export->design()->value('name');
        $this->send((int) $export->user_id, 'studio_export', 'export:'.$export->id, 'No se pudo exportar el diseño en '.strtoupper((string) $export->format),
            (string) ($export->error ?: 'La exportación falló. Puedes iniciar otra desde el diseño.'), route('studio.designs.edit', $export->design_id, false), $name, $export->brand_id);
    }

    public function ai(StudioAiJob $job): void
    {
        $this->send((int) $job->user_id, 'studio_ai', 'ai:'.$job->id, 'No se pudo completar un trabajo de IA',
            (string) ($job->error ?: 'El trabajo de IA falló. Inténtalo de nuevo.'), route('studio.index', [], false), mb_substr((string) $job->prompt, 0, 80), $job->brand_id);
    }

    public function canva(CanvaJob $job): void
    {
        $this->send((int) $job->user_id, 'canva', 'canva:'.$job->id, 'Canva no ha podido completar un trabajo',
            (string) ($job->error ?: 'No se pudo completar el trabajo con Canva. Inténtalo de nuevo más tarde.'), route('canva.index', $job->brand_id, false), (string) ($job->title ?? ''), $job->brand_id);
    }

    public function import(MediaAsset $asset): void
    {
        $this->send((int) $asset->user_id, 'import', 'import:'.$asset->id, 'No se pudo importar un archivo a la biblioteca',
            (string) ($asset->error ?: 'No se pudo descargar el fichero; inténtalo de nuevo más tarde.'), route('media.index', [], false), (string) $asset->original_name, null);
    }

    private function send(int $userId, string $kind, string $key, string $title, string $reason, string $url, string $subject, ?int $brandId): void
    {
        try {
            $user = User::find($userId);
            if ($user === null || ! Cache::add("job-failure:{$userId}:{$key}", 1, now()->addDay())) {
                return;
            }

            $bucket = "job-failure-burst:{$userId}:{$kind}";
            Cache::add($bucket, 0, now()->addHour());
            if (Cache::increment($bucket) > self::BURST) {
                return;
            }

            $user->notify(new AsyncJobFailed($kind, $title, $reason, $url, $subject, $brandId));
        } catch (Throwable $e) {
            Log::error('jobs.failure_notification_failed', ['kind' => $kind, 'exception' => $e::class]);
        }
    }
}
