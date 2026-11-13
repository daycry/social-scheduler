<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Aviso (T-23) de que un trabajo largo que lanzó el usuario ha terminado BIEN: exportación MP4/GIF, exportación o envío de Canva, IA de imagen
 * e importación por URL. SOLO en la app (nunca por correo); el envío programado de informes no lo usa porque su propio correo ya es el aviso.
 * Mismo contrato que los demás avisos: `type`, `message` y un `url` relativo que `Notifications/Index` pinta como enlace.
 */
class AsyncJobDone extends Notification
{
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $url = null,
        public readonly string $subject = '',
        public readonly ?int $brandId = null,
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
            'type' => 'job_done_'.$this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'excerpt' => mb_substr($this->subject, 0, 80),
            'brand_id' => $this->brandId,
            'url' => $this->url,
        ];
    }
}
