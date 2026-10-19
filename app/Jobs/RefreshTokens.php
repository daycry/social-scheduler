<?php

namespace App\Jobs;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Models\SocialAccount;
use App\Notifications\ReconnectRequired;
use App\Services\SocialAccountService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renueva las credenciales de las cuentas activas antes de que caduquen (margen configurable por
 * proveedor en config/social.php). Si la red las rechaza, la cuenta pasa a `requiere_reconexion`
 * y se avisa al usuario UNA vez. Nunca borra cuentas, marcas ni publicaciones: las pendientes se
 * conservan y se reanudan al reconectar.
 */
class RefreshTokens implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue((string) config('social.refresh_queue', 'poll'));
    }

    public function handle(ConnectorRegistry $connectors, SocialAccountService $accounts): void
    {
        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        SocialAccount::withoutUserScope()
            ->where('status', SocialAccount::STATUS_ACTIVE)
            ->orderBy('id')
            ->each(function (SocialAccount $account) use ($connectors, $accounts): void {
                try {
                    $this->process($account, $connectors, $accounts);
                } catch (Throwable $e) {
                    // Una cuenta con problemas no debe bloquear al resto.
                    Log::error('social.refresh_unexpected_error', ['account_id' => $account->id, 'exception' => $e::class]);
                }
            });
    }

    private function process(SocialAccount $account, ConnectorRegistry $connectors, SocialAccountService $accounts): void
    {
        // Meta: pasados los 90 dias de acceso a datos solo sirve un nuevo login.
        if ($account->data_access_expires_at?->isPast()) {
            $this->requireReconnection($account, $accounts, 'El acceso a datos de la red caduco: vuelve a iniciar sesion para renovarlo.');

            return;
        }

        if (! $connectors->has($account->network->provider())) {
            return;
        }

        $connector = $connectors->forNetwork($account->network);

        if (! $this->isDue($account, $connector->refreshMarginHours())) {
            return;
        }

        try {
            $accounts->applyRefresh($account, $connector->refresh($account));
        } catch (ReconnectRequiredException $e) {
            $this->requireReconnection($account, $accounts, $e->getMessage());
        } catch (ConnectorException $e) {
            // Fallo transitorio: se reintenta en la siguiente pasada, salvo que el token ya haya caducado.
            Log::warning('social.refresh_transient_error', ['account_id' => $account->id, 'network' => $account->network->value]);

            if ($account->token_expires_at?->isPast()) {
                $this->requireReconnection($account, $accounts, 'No se pudo renovar la conexion y el token ya caduco: '.$e->getMessage());
            }
        }
    }

    private function isDue(SocialAccount $account, int $marginHours): bool
    {
        // Sin caducidad conocida (p. ej. token de Pagina de Meta) no hay nada que renovar.
        return $account->token_expires_at !== null
            && $account->token_expires_at->lte(now()->addHours($marginHours));
    }

    private function requireReconnection(SocialAccount $account, SocialAccountService $accounts, string $reason): void
    {
        $changed = $accounts->markRequiresReconnection($account, $reason);

        if (! $changed) {
            return;
        }

        try {
            $account->user->notify(new ReconnectRequired($account, $reason));
        } catch (Throwable $e) {
            Log::error('social.reconnect_notification_failed', ['account_id' => $account->id, 'exception' => $e::class]);
        }
    }
}
