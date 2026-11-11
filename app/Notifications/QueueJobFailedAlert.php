<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso por correo al administrador de que un job ha agotado sus reintentos (`Queue::failing`). Va en linea (no en cola):
 * una cola caida no debe impedir el aviso. Solo lleva nombre del job, cola y clase de la excepcion, nunca payload ni mensaje.
 */
class QueueJobFailedAlert extends Notification
{
    public function __construct(
        public readonly string $job,
        public readonly string $queue,
        public readonly string $exception,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('['.config('app.name').'] Job fallido en la cola '.$this->queue)
            ->line("El job {$this->job} ha fallado en la cola «{$this->queue}» ({$this->exception}).")
            ->line('Revisa `php artisan queue:failed` y `php artisan app:health`. Este aviso se limita a uno por intervalo.');
    }
}
