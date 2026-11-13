<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\SocialAccount;
use App\Notifications\ConnectionExpiring;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pasada diaria (T-23, `connections:notify-expiry`): avisa a cada usuario de las conexiones que caducan en `social.expiry_notice_days`
 * (por defecto 7 y 1 dias antes). Una sola vez por umbral y por fecha de caducidad: la marca va en `meta.expiry_notice` y se invalida
 * sola al reconectar (la fecha cambia). Respeta `notify_by_mail`: sin la preferencia solo hay aviso en la app.
 */
class ConnectionExpiryNotifier
{
    /** @return int Avisos emitidos. */
    public function run(): int
    {
        $sent = 0;
        $window = now()->addDays(ConnectionExpiry::windowDays())->addSeconds(ConnectionExpiry::intervalSeconds());

        SocialAccount::withoutUserScope()->where('status', SocialAccount::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereBetween('data_access_expires_at', [now(), $window])->orWhereBetween('token_expires_at', [now(), $window])->orWhereBetween('refresh_expires_at', [now(), $window]))
            ->with('user')->orderBy('id')
            ->each(function (SocialAccount $account) use (&$sent): void {
                $sent += $this->notify($account) ? 1 : 0;
            });

        return $sent;
    }

    /** Zona de la marca (la de sus fechas en la app); UTC si no se conoce. */
    private function timezone(SocialAccount $account): string
    {
        return (string) (Brand::withoutUserScope()->whereKey($account->brand_id)->value('timezone') ?: 'UTC');
    }

    private function notify(SocialAccount $account): bool
    {
        $deadline = ConnectionExpiry::deadline($account);
        if ($deadline === null || $account->user === null) {
            return false;
        }

        // Una pasada al dia: el aviso de N dias se emite cuando quedan N dias + el intervalo de la pasada, asi llega con N dias de margen como minimo.
        $left = now()->diffInSeconds($deadline['at'], false);
        $applicable = null;
        foreach (array_reverse(ConnectionExpiry::thresholds()) as $threshold) { // de menor a mayor: el mas estrecho que ya se alcanzo
            if ($left <= $threshold * 86400 + ConnectionExpiry::intervalSeconds()) {
                $applicable = $threshold;
                break;
            }
        }
        if ($applicable === null) {
            return false;
        }

        $key = $deadline['at']->toIso8601String();
        $last = (array) ($account->meta['expiry_notice'] ?? []);
        if (($last['at'] ?? null) === $key && (int) ($last['threshold'] ?? PHP_INT_MAX) <= $applicable) {
            return false;
        }

        try {
            $account->user->notify(new ConnectionExpiring($account, ConnectionExpiry::message($account, $deadline['reason']), $deadline['at']->copy()->setTimezone($this->timezone($account))->locale('es')->isoFormat('D [de] MMMM [de] YYYY')));
        } catch (Throwable $e) {
            Log::error('social.expiry_notification_failed', ['account_id' => $account->id, 'exception' => $e::class]);

            return false;
        }

        $account->forceFill(['meta' => ['expiry_notice' => ['at' => $key, 'threshold' => $applicable]] + (array) $account->meta])->saveQuietly();

        return true;
    }
}
