<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Aviso in-app (solo base de datos) de que una serie recurrente no ha podido generar sus ocurrencias, con el motivo legible
 * (p. ej. cuenta desconectada). Guarda escalares, nunca tokens ni URLs firmadas. Se envia solo cuando el motivo cambia.
 */
class SeriesGenerationFailed extends Notification
{
    public function __construct(
        public readonly int $seriesId,
        public readonly ?int $brandId,
        public readonly string $brandName,
        public readonly string $reason,
        public readonly string $excerpt = '',
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'series_failed',
            'series_id' => $this->seriesId,
            'brand_id' => $this->brandId,
            'brand_name' => $this->brandName,
            'message' => $this->reason,
            'excerpt' => mb_substr($this->excerpt, 0, 80),
            'url' => route('series.index', [], false),
        ];
    }
}
