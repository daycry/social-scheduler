<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Aviso en la app del resumen semanal (T-86). Solo canal `database`: el correo lo envia `SendUserWeeklyDigest` con su propio Mailable (con
 * baja firmada), y el push lo cuelga `QueuePushNotification` de este canal, asi que el resumen llega por push a quien lo tiene activado.
 * Guarda escalares ya formateados (marcas y rango), nunca las cifras detalladas ni tokens.
 */
class WeeklyDigestReady extends Notification
{
    /** @param array{week: string, period: array{label: string}, brands: list<array<string, mixed>>} $digest */
    public function __construct(public readonly array $digest) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $names = array_column($this->digest['brands'], 'name');
        $withData = count(array_filter($this->digest['brands'], fn (array $brand) => $brand['has_data']));

        return [
            'type' => 'weekly_digest',
            'week' => $this->digest['week'],
            'title' => 'Tu resumen semanal: '.$this->digest['period']['label'],
            'message' => $withData === 0
                ? 'Esta semana no hay datos de '.mb_strimwidth(implode(', ', $names), 0, 120, '…').'. Revisa que tus cuentas estén conectadas.'
                : 'Ya tienes las cifras de la semana de '.mb_strimwidth(implode(', ', $names), 0, 120, '…').', comparadas con la anterior.',
            'url' => route('dashboard', absolute: false),
        ];
    }
}
