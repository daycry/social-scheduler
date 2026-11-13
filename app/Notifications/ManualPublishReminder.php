<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Recordatorio (T-32) de que una publicacion manual toca pronto: aviso en la app siempre y correo solo con `notify_by_mail`. Va a la cola
 * (como `ConnectionExpiring`): el correo no se envia de forma sincrona dentro del despachador del minuto. Guarda escalares, nunca tokens.
 */
class ManualPublishReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public readonly int $postId,
        public readonly ?int $brandId,
        public readonly string $brandName,
        public readonly string $message,
        public readonly string $excerpt = '',
    ) {
        $this->onQueue((string) config('social.refresh_queue', 'poll'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->notify_by_mail ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Recuerda publicar a mano: '.$this->brandName)
            ->line($this->message)
            ->action('Abrir el contenido', route('posts.manual.show', $this->postId));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'manual_reminder',
            'post_id' => $this->postId,
            'brand_id' => $this->brandId,
            'brand_name' => $this->brandName,
            'message' => $this->message,
            'excerpt' => mb_substr($this->excerpt, 0, 80),
            'url' => route('posts.manual.show', $this->postId, false),
        ];
    }
}
