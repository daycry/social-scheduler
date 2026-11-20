<?php

namespace App\Notifications;

use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso (T-23) de que una conexion caduca pronto y hay que reconectarla ANTES: acceso a datos de Meta (90 dias) o token de LinkedIn sin
 * renovacion. En la app siempre; correo solo con `notify_by_mail`. Datos escalares: nunca tokens.
 */
class ConnectionExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public readonly int $accountId;

    public readonly int $brandId;

    public readonly string $networkValue;

    public readonly string $networkLabel;

    public readonly string $accountName;

    public function __construct(SocialAccount $account, public readonly string $reason, public readonly string $expiresAt)
    {
        $this->accountId = $account->id;
        $this->brandId = $account->brand_id;
        $this->networkValue = $account->network->value;
        $this->networkLabel = $account->network->label();
        $this->accountName = $account->name;
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
            ->subject("Reconecta {$this->networkLabel} antes de que caduque")
            ->line("La conexion de {$this->networkLabel} «{$this->accountName}» caduca el {$this->expiresAt}.")
            ->line($this->reason)
            ->line('Mientras tanto sigue funcionando; reconectar a tiempo evita que se detengan tus publicaciones programadas.')
            ->action('Reconectar', route('brands.connections.index', $this->brandId));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'connection_expiring',
            'social_account_id' => $this->accountId,
            'brand_id' => $this->brandId,
            'network' => $this->networkValue,
            'network_label' => $this->networkLabel,
            'account_name' => $this->accountName,
            'message' => $this->reason,
            'expires_at' => $this->expiresAt,
            'url' => route('brands.connections.index', $this->brandId, false),
        ];
    }
}
