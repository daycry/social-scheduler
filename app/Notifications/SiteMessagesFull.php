<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Avisa (una sola vez) de que el formulario de contacto de un sitio llego al tope y empieza a descartar los mensajes mas antiguos (T-15). */
class SiteMessagesFull extends Notification
{
    public function __construct(public readonly int $siteId, public readonly string $title, public readonly int $limit) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => "El formulario de contacto del sitio «{$this->title}» guarda {$this->limit} mensajes: desde ahora se descartan los más antiguos al llegar otros nuevos. Léelos o bórralos desde el editor del sitio.",
            'site_id' => $this->siteId,
        ];
    }
}
