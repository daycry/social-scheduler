<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Aviso in-app (sin correo) de colaboración en un diseño: invitación, cambio de rol o comentario nuevo. */
class StudioDesignActivity extends Notification
{
    public function __construct(public readonly string $kind, public readonly int $designId, public readonly string $designName, public readonly string $message) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'studio_design_'.$this->kind, 'design_id' => $this->designId, 'excerpt' => mb_substr($this->designName, 0, 80),
            'message' => $this->message, 'url' => route('studio.designs.edit', $this->designId, false)];
    }
}
