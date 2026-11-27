<?php

namespace App\Notifications;

use App\Services\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso (T-23) de que un trabajo asincrono ha fallado. La politica del usuario gobierna app, correo y push. Guarda el motivo legible y
 * una URL relativa para abrir el trabajo desde Avisos.
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
        return NotificationPreferences::allows($notifiable, 'job_failed', 'mail') ? ['database', 'mail'] : ['database'];
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
