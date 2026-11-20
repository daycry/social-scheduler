<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Aviso in-app (solo base de datos) de que a un destino programado le faltaba un archivo de su media al migrar las referencias
 * a media a tablas con FK (T-06): el destino quedo `fallida` hasta que el usuario edite su media. Lo inserta la migracion como fila.
 */
class PostMediaMissing extends Notification
{
    /** @param  list<int>  $mediaIds */
    public function __construct(public readonly int $postId, public readonly array $mediaIds) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'media_missing',
            'post_id' => $this->postId,
            'message' => 'Falta el archivo '.implode(', ', $this->mediaIds)." en la publicacion {$this->postId}: editala y vuelve a elegir la media.",
            'url' => route('posts.edit', $this->postId, false),
        ];
    }
}
