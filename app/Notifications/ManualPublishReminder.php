<?php

namespace App\Notifications;

use App\Services\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Recordatorio (T-32) previo a una publicacion manual. Se encola para entregar por los canales habilitados por el usuario.
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
        return NotificationPreferences::allows($notifiable, 'manual_reminder', 'mail') ? ['database', 'mail'] : ['database'];
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
