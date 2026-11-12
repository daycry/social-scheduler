<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Resumen (T-23) de los fallos de trabajos que superaron el tope de avisos individuales por hora («y N fallos más de importación en la
 * última hora»). Solo en la app; `JobFailureNotifier` actualiza el contador de la misma fila mientras dure la hora.
 */
class AsyncJobFailureSummary extends Notification
{
    public function __construct(public readonly string $kind, public readonly int $count, public readonly string $noun, public readonly ?string $url = null, public readonly ?int $brandId = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return self::payload($this->kind, $this->count, $this->noun, $this->url, $this->brandId);
    }

    /** @return array<string, mixed> */
    public static function payload(string $kind, int $count, string $noun, ?string $url, ?int $brandId): array
    {
        return [
            'type' => 'job_failed_summary',
            'kind' => $kind,
            'count' => $count,
            'noun' => $noun,
            'title' => 'Y '.$count.($count === 1 ? ' fallo más' : ' fallos más').' de '.$noun.' en la última hora',
            'message' => 'Para no llenar tus avisos solo se muestran los primeros; abre la sección para ver el detalle de los demás.',
            'brand_id' => $brandId,
            'url' => $url,
        ];
    }
}
