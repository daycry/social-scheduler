<?php

namespace App\Services;

use App\Connectors\Network;
use App\Models\SocialAccount;
use Carbon\CarbonInterface;

/**
 * Caducidad CONOCIDA de una conexion que el sistema NO puede renovar solo (T-23): hay que volver a iniciar sesion antes de la fecha.
 * Dos casos: el acceso a datos (Meta lo limita a 90 dias desde el ultimo inicio de sesion; cualquier red que lo informe) y un token de
 * acceso sin refresh_token (LinkedIn). Los tokens que se refrescan solos (Threads, Pinterest, TikTok, Google...) no cuentan: su renovacion
 * ya la hace `RefreshSocialAccount`, que avisa si falla.
 */
final class ConnectionExpiry
{
    public const DATA_ACCESS = 'data_access';

    public const TOKEN = 'token';

    /** @return array{at: CarbonInterface, reason: string}|null  Fecha mas proxima (futura) y motivo; null si no hay caducidad que avisar. */
    public static function deadline(SocialAccount $account): ?array
    {
        if ($account->status !== SocialAccount::STATUS_ACTIVE) {
            return null;
        }

        $candidates = [];

        if ($account->data_access_expires_at !== null) {
            $candidates[] = ['at' => $account->data_access_expires_at, 'reason' => self::DATA_ACCESS];
        }

        if ($account->network === Network::LinkedIn && $account->token_expires_at !== null && (string) $account->refresh_token === '') {
            $candidates[] = ['at' => $account->token_expires_at, 'reason' => self::TOKEN];
        }

        $candidates = array_values(array_filter($candidates, fn (array $c) => $c['at']->isFuture()));
        usort($candidates, fn (array $a, array $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        return $candidates[0] ?? null;
    }

    /** Umbrales de aviso en dias (de mayor a menor), p. ej. [7, 1]. @return list<int> */
    public static function thresholds(): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', (array) config('social.expiry_notice_days', [7, 1])), fn (int $d) => $d > 0)));
        rsort($days);

        return $days === [] ? [7, 1] : $days;
    }

    /** Ventana (dias) en la que la pantalla de Conexiones marca «reconecta antes de»: el umbral mayor. */
    public static function windowDays(): int
    {
        return self::thresholds()[0];
    }

    /** Si la cuenta esta dentro de la ventana de aviso: fecha ISO y motivo. @return array{at: string, reason: string}|null */
    public static function dueSoon(SocialAccount $account): ?array
    {
        $d = self::deadline($account);

        return $d !== null && $d['at']->lte(now()->addDays(self::windowDays())) ? ['at' => $d['at']->toIso8601String(), 'reason' => $d['reason']] : null;
    }

    /** Texto para el usuario del motivo. */
    public static function message(SocialAccount $account, string $reason): string
    {
        $label = $account->network->label();

        return $reason === self::DATA_ACCESS
            ? "El acceso a datos de {$label} caduca (Meta lo limita a 90 días) y no se renueva solo: vuelve a iniciar sesión para ampliarlo."
            : "{$label} no permite renovar el acceso automáticamente: vuelve a iniciar sesión antes de que caduque.";
    }
}
