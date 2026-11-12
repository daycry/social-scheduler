<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Canva\CanvaJob;
use App\Models\MediaAsset;
use App\Models\ReportSchedule;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAiJob;
use App\Models\User;
use App\Notifications\AsyncJobDone;
use App\Notifications\AsyncJobFailed;
use App\Notifications\AsyncJobFailureSummary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Avisa al propietario (T-23) del resultado de un trabajo ASINCRONO: informe programado, exportacion MP4/GIF, IA, Canva o importacion remota.
 * Reutiliza el aviso comun (`Notifications/Index`, `n.url`); el correo solo con `notify_by_mail` y solo en los FALLOS.
 *
 * Fallos: un aviso por EJECUCION (clave por trabajo; el informe, por fecha de ejecucion) aunque fallen la cola y el servicio; como mucho
 * `BURST` avisos por tipo, usuario y hora. Lo que corta el tope se registra en el log y se agrupa en UN aviso resumen («y N fallos más de
 * importación en la última hora») que se actualiza en su sitio; un fallo cortado NO consume su clave, asi que avisa cuando haya hueco.
 * Exitos: solo en la app y solo para trabajos largos que el usuario no ve terminar (video, Canva, IA, importacion por URL); el informe
 * programado no avisa al terminar bien porque su correo ya es el aviso. Nada de esto rompe el job que lo llama.
 */
class JobFailureNotifier
{
    public const BURST = 5;

    private const NOUNS = ['report' => 'informes programados', 'studio_export' => 'exportación de video', 'studio_ai' => 'IA', 'canva' => 'Canva', 'import' => 'importación'];

    public function report(ReportSchedule $schedule): void
    {
        $brand = Brand::withoutUserScope()->find($schedule->brand_id);
        $run = ($schedule->last_run_at ?? now())->format('Y-m-d'); // fecha de la ejecucion: una programacion puede fallar cada semana
        $this->failed((int) $schedule->user_id, 'report', 'report:'.$schedule->id.':'.$run, 'No se pudo enviar un informe programado',
            (string) ($schedule->last_error ?: ReportService::GENERIC_ERROR), route('reports.index', [], false), (string) $brand?->name, $schedule->brand_id);
    }

    /** Solo las exportaciones de video (MP4/GIF): el resto las sigue el usuario en el propio editor. */
    public function export(DesignExport $export): void
    {
        if (! $this->isVideo($export)) {
            return;
        }

        $this->failed((int) $export->user_id, 'studio_export', 'export:'.$export->id, 'No se pudo exportar el diseño en '.strtoupper((string) $export->format),
            (string) ($export->error ?: 'La exportación falló. Puedes iniciar otra desde el diseño.'), route('studio.designs.edit', $export->design_id, false), (string) $export->design()->value('name'), $export->brand_id);
    }

    public function ai(StudioAiJob $job): void
    {
        $this->failed((int) $job->user_id, 'studio_ai', 'ai:'.$job->id, 'No se pudo completar un trabajo de IA',
            (string) ($job->error ?: 'El trabajo de IA falló. Inténtalo de nuevo.'), route('studio.index', [], false), mb_substr((string) $job->prompt, 0, 80), $job->brand_id);
    }

    public function canva(CanvaJob $job): void
    {
        $this->failed((int) $job->user_id, 'canva', 'canva:'.$job->id, 'Canva no ha podido completar un trabajo',
            (string) ($job->error ?: 'No se pudo completar el trabajo con Canva. Inténtalo de nuevo más tarde.'), route('canva.index', $job->brand_id, false), (string) ($job->title ?? ''), $job->brand_id);
    }

    public function import(MediaAsset $asset): void
    {
        $this->failed((int) $asset->user_id, 'import', 'import:'.$asset->id, 'No se pudo importar un archivo a la biblioteca',
            (string) ($asset->error ?: 'No se pudo descargar el fichero; inténtalo de nuevo más tarde.'), route('media.index', [], false), (string) $asset->original_name, null);
    }

    // ------------------------------------------------------------------ exitos (solo en la app)

    public function exportDone(DesignExport $export): void
    {
        if (! $this->isVideo($export)) {
            return;
        }

        $this->done((int) $export->user_id, 'studio_export', 'export:'.$export->id, 'Exportación lista en '.strtoupper((string) $export->format),
            'Ya puedes descargarla desde el diseño.', route('studio.designs.edit', $export->design_id, false), (string) $export->design()->value('name'), $export->brand_id);
    }

    public function aiDone(StudioAiJob $job): void
    {
        $this->done((int) $job->user_id, 'studio_ai', 'ai:'.$job->id, 'Tu imagen de IA está lista', 'Ya la tienes en la biblioteca.', route('studio.index', [], false), mb_substr((string) $job->prompt, 0, 80), $job->brand_id);
    }

    public function canvaDone(CanvaJob $job): void
    {
        [$title, $message] = match ($job->kind) {
            CanvaJob::KIND_UPLOAD => ['Recurso enviado a Canva', 'Ya puedes usarlo en tus diseños de Canva.'],
            CanvaJob::KIND_AUTOFILL => ['Diseño de Canva creado desde la plantilla', 'Ya puedes abrirlo en Canva.'],
            default => ['Exportación de Canva lista', 'Ya la tienes en la biblioteca.'],
        };
        $this->done((int) $job->user_id, 'canva', 'canva:'.$job->id, $title, $message, route('canva.index', $job->brand_id, false), (string) ($job->title ?? ''), $job->brand_id);
    }

    /** Solo importaciones por URL (las de Canva ya avisan con su propio trabajo). */
    public function importDone(MediaAsset $asset): void
    {
        if ($asset->source !== MediaAsset::SOURCE_URL) {
            return;
        }

        $this->done((int) $asset->user_id, 'import', 'import:'.$asset->id, 'Importación lista', 'El archivo ya está en la biblioteca.', route('media.index', [], false), (string) $asset->original_name, null);
    }

    private function isVideo(DesignExport $export): bool
    {
        return in_array((string) $export->format, ['mp4', 'gif'], true);
    }

    private function done(int $userId, string $kind, string $key, string $title, string $message, string $url, string $subject, ?int $brandId): void
    {
        try {
            $user = User::find($userId);
            if ($user === null || ! Cache::add("job-done:{$userId}:{$key}", 1, now()->addDay())) {
                return;
            }

            $user->notify(new AsyncJobDone($kind, $title, $message, $url, $subject, $brandId));
        } catch (Throwable $e) {
            Log::error('jobs.done_notification_failed', ['kind' => $kind, 'exception' => $e::class]);
        }
    }

    // ------------------------------------------------------------------ fallos

    private function failed(int $userId, string $kind, string $key, string $title, string $reason, string $url, string $subject, ?int $brandId): void
    {
        try {
            $user = User::find($userId);
            $jobKey = "job-failure:{$userId}:{$key}";
            if ($user === null || Cache::has($jobKey)) {
                return;
            }

            $bucket = "job-failure-burst:{$userId}:{$kind}";
            Cache::add($bucket, 0, now()->addHour());
            if (Cache::increment($bucket) > self::BURST) {
                $this->capped($user, $kind, $key, $url, $brandId);

                return; // la clave del trabajo NO se consume: avisara cuando haya hueco
            }

            Cache::put($jobKey, 1, now()->addDay());
            $user->notify(new AsyncJobFailed($kind, $title, $reason, $url, $subject, $brandId));
        } catch (Throwable $e) {
            Log::error('jobs.failure_notification_failed', ['kind' => $kind, 'exception' => $e::class]);
        }
    }

    /** Un fallo mas alla del tope: queda en el log y suma al aviso resumen de la hora (una sola vez por trabajo). */
    private function capped(User $user, string $kind, string $key, string $url, ?int $brandId): void
    {
        Log::warning('jobs.failure_notification_capped', ['user_id' => $user->id, 'kind' => $kind, 'key' => $key]);

        if (! Cache::add("job-failure-capped:{$user->id}:{$key}", 1, now()->addHour())) {
            return;
        }

        $noun = self::NOUNS[$kind] ?? $kind;
        $row = $user->notifications()->where('data->type', 'job_failed_summary')->where('data->kind', $kind)->where('created_at', '>=', now()->subHour())->latest()->first();

        if ($row === null) {
            Notification::sendNow($user, new AsyncJobFailureSummary($kind, 1, $noun, $url, $brandId));

            return;
        }

        $row->forceFill(['data' => AsyncJobFailureSummary::payload($kind, (int) ($row->data['count'] ?? 1) + 1, $noun, $url, $brandId), 'read_at' => null])->save();
    }
}
