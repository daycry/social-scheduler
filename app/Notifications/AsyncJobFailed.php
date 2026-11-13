<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso (T-23) de que un trabajo asincrono ha fallado: informe programado, exportacion MP4/GIF, IA, Canva o importacion. Siempre en la app
 * (`database`); el correo solo si el usuario lo activo (`notify_by_mail`). Guarda escalares con el motivo ya legible, nunca tokens ni URLs
 * firmadas, y un `url` relativo que la pagina de avisos usa como enlace generico (igual que los avisos de publicacion y de series).
 */
class AsyncJobFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $reason,
        public readonly ?string $url = null,
        public readonly string $subject = '',
        public readonly ?int $brandId = null,
    ) {
        $this->onQueue((string) config('social.refresh_queue', 'poll'));
    }

    /** @return list<string> `database` primero: es el aviso que no puede perderse. */
    public function via(object $notifiable): array
    {
        return $notifiable->notify_by_mail ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->title.'.')->line($this->reason);

        if ($this->subject !== '') {
            $mail->line('«'.$this->subject.'»');
        }

        return $this->url ? $mail->action('Abrir', url($this->url)) : $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'job_failed_'.$this->kind,
            'title' => $this->title,
            'message' => $this->reason,
            'excerpt' => mb_substr($this->subject, 0, 80),
            'brand_id' => $this->brandId,
            'url' => $this->url,
        ];
    }
}
