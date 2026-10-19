<?php

namespace App\Notifications;

use App\Models\SocialAccount;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Avisa al usuario de que una cuenta conectada necesita volver a autorizarse. Nunca incluye tokens. */
class ReconnectRequired extends Notification
{
    public function __construct(
        public readonly SocialAccount $account,
        public readonly string $reason,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->account->network->label();

        return (new MailMessage)
            ->subject("Reconecta tu cuenta de {$label}")
            ->line("La conexion de {$label} «{$this->account->name}» ha dejado de funcionar y hay que volver a autorizarla.")
            ->line('Tus publicaciones programadas se conservan, pero no se enviaran a esta cuenta hasta que la reconectes.')
            ->action('Reconectar', route('brands.connections.index', $this->account->brand_id));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'reconnect_required',
            'social_account_id' => $this->account->id,
            'brand_id' => $this->account->brand_id,
            'network' => $this->account->network->value,
            'account_name' => $this->account->name,
            'message' => $this->reason,
        ];
    }
}
