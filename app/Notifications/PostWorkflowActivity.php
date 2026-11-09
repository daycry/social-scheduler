<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Aviso in-app (solo base de datos) del flujo de publicacion (T-19): aprobacion pedida / aprobada / rechazada, invitacion de
 * colaborador y publicacion manual que toca. Guarda escalares y nunca tokens ni URLs firmadas.
 */
class PostWorkflowActivity extends Notification
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly string $kind,
        public readonly ?int $postId,
        public readonly ?int $brandId,
        public readonly string $brandName,
        public readonly string $message,
        public readonly string $excerpt = '',
        public readonly array $extra = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $url = match (true) {
            str_starts_with($this->kind, 'manual') && $this->postId !== null => route('posts.manual.show', $this->postId, false),
            $this->kind === 'approval_requested' => route('approvals.index', [], false),
            $this->postId !== null => route('posts.edit', $this->postId, false),
            default => route('approvals.index', [], false),
        };

        return [
            'type' => $this->kind,
            'post_id' => $this->postId,
            'brand_id' => $this->brandId,
            'brand_name' => $this->brandName,
            'message' => $this->message,
            'excerpt' => mb_substr($this->excerpt, 0, 80),
            'url' => $url,
        ] + $this->extra;
    }
}
