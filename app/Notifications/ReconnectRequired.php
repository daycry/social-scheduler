<?php

namespace App\Notifications;

use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa al usuario de que una cuenta conectada necesita volver a autorizarse. Nunca incluye tokens.
 *
 * Va por cola (Laravel encola un job POR CANAL): el aviso en BD (`database`, el que ve la app) no depende
 * de que el SMTP responda, y un fallo de `mail` no lo impide ni bloquea el job de refresco. Guarda datos
 * escalares y no el modelo: el worker no tiene usuario autenticado y el UserScope impediria restaurarlo.
 */
class ReconnectRequired extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly int $accountId;

    public readonly int $brandId;

    public readonly string $networkValue;

    public readonly string $networkLabel;

    public readonly string $accountName;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(SocialAccount $account, public readonly string $reason)
    {
        $this->accountId = $account->id;
        $this->brandId = $account->brand_id;
        $this->networkValue = $account->network->value;
        $this->networkLabel = $account->network->label();
        $this->accountName = $account->name;
        $this->onQueue((string) config('social.refresh_queue', 'poll'));
    }

    /** @return list<string> `database` primero: es el aviso que no puede perderse. */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->networkLabel;

        return (new MailMessage)
            ->subject("Reconecta tu cuenta de {$label}")
            ->line("La conexion de {$label} «{$this->accountName}» ha dejado de funcionar y hay que volver a autorizarla.")
            ->line('Tus publicaciones programadas se conservan, pero no se enviaran a esta cuenta hasta que la reconectes.')
            ->action('Reconectar', route('brands.connections.index', $this->brandId));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'reconnect_required',
            'social_account_id' => $this->accountId,
            'brand_id' => $this->brandId,
            'network' => $this->networkValue,
            'account_name' => $this->accountName,
            'message' => $this->reason,
        ];
    }
}
