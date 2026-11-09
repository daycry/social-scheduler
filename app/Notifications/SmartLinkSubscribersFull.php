<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Avisa al propietario de que el formulario de email de su SmartLink llego al tope de suscriptores (T-20). Solo en base de datos. */
class SmartLinkSubscribersFull extends Notification
{
    public function __construct(public readonly int $smartLinkId, public readonly int $blockId, public readonly string $slug, public readonly int $limit) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => "El formulario de email de /l/{$this->slug} llegó a su tope de {$this->limit} suscriptores y no guarda más direcciones. Descarga o borra la lista para liberar sitio.",
            'smart_link_id' => $this->smartLinkId,
            'block_id' => $this->blockId,
        ];
    }
}
