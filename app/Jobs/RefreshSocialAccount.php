<?php

namespace App\Jobs;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Models\SocialAccount;
use App\Notifications\ReconnectRequired;
use App\Services\SocialAccountService;
use Closure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renueva las credenciales de UNA cuenta antes de que caduquen. Clasifica los fallos:
 *
 *  - PERMANENTES (ReconnectRequiredException: invalid_grant, token revocado, 401 del refresh; acceso a
 *    datos de Meta o refresh token vencidos): la cuenta pasa a `requiere_reconexion` y se avisa UNA vez.
 *  - TRANSITORIOS (ConnectorException: 5xx, timeouts, red): NUNCA marcan la cuenta por si solos, aunque el
 *    access token ya haya caducado; se reintenta en la siguiente pasada (30 min) y solo tras
 *    `social.max_transient_failures` fallos SEGUIDOS (12 ~ 6 h) se escala a reconexion.
 *
 * Nunca borra cuentas, marcas ni publicaciones: las pendientes se conservan y se reanudan al reconectar.
 *
 * Presupuesto de tiempo: $timeout (100 s) < `--timeout` del worker (120 s, ver deploy/systemd) <
 * `retry_after` de la cola (180 s). La red se llama FUERA de transaccion; solo la relectura previa a
 * escribir (con `lockForUpdate`) es transaccional, para no bloquear la fila durante la llamada.
 */
class RefreshSocialAccount implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 100;

    public int $tries = 2;

    public int $backoff = 60;

    /** Una cuenta no se encola dos veces mientras haya un job suyo pendiente o en curso. */
    public int $uniqueFor = 900;

    public function __construct(public readonly int $accountId)
    {
        $this->onQueue((string) config('social.refresh_queue', 'poll'));
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    /** Criterio unico (despachador y job): ¿hay algo que hacer con esta cuenta ahora? */
    public static function needsWork(SocialAccount $account, ConnectorRegistry $connectors): bool
    {
        if (self::permanentReason($account) !== null) {
            return true;
        }

        if (! $connectors->has($account->network->provider())) {
            return false;
        }

        // Sin caducidad conocida (p. ej. token de Pagina de Meta) no hay nada que renovar.
        return $account->token_expires_at !== null
            && $account->token_expires_at->lte(now()->addHours($connectors->forNetwork($account->network)->refreshMarginHours()));
    }

    /** Motivo permanente conocido sin llamar a la red (acceso a datos / refresh token vencidos). */
    private static function permanentReason(SocialAccount $account): ?string
    {
        if ($account->data_access_expires_at?->isPast()) {
            return 'El acceso a datos de la red caduco: vuelve a iniciar sesion para renovarlo.';
        }

        if ($account->refresh_expires_at?->isPast()) {
            return 'El token de renovacion caduco: vuelve a iniciar sesion para renovarlo.';
        }

        return null;
    }

    public function handle(ConnectorRegistry $connectors, SocialAccountService $accounts): void
    {
        try {
            $this->process($connectors, $accounts);
        } catch (Throwable $e) {
            // Un fallo inesperado no debe reintentarse en caliente (podria repetir una rotacion de token):
            // se registra y la siguiente pasada vuelve a intentarlo.
            Log::error('social.refresh_unexpected_error', ['account_id' => $this->accountId, 'exception' => $e::class]);
        }
    }

    private function process(ConnectorRegistry $connectors, SocialAccountService $accounts): void
    {
        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        $account = SocialAccount::withoutUserScope()->find($this->accountId);

        if (! $account || ! $account->isActive()) {
            return; // desconectada, ya marcada o borrada mientras esperaba en la cola
        }

        if (($reason = self::permanentReason($account)) !== null) {
            $this->requireReconnection($accounts, $reason, $account->last_refreshed_at?->getTimestamp());

            return;
        }

        if (! self::needsWork($account, $connectors)) {
            return; // ya renovada por otro camino
        }

        $before = $account->last_refreshed_at?->getTimestamp();

        try {
            $result = $connectors->forNetwork($account->network)->refresh($account);
        } catch (ReconnectRequiredException $e) {
            $this->requireReconnection($accounts, $e->getMessage(), $before);

            return;
        } catch (ConnectorException $e) {
            $this->transientFailure($accounts, $e->getMessage(), $account, $before);

            return;
        }

        $this->locked(function (SocialAccount $fresh) use ($accounts, $result, $before): void {
            // Si mientras tanto se desconecto o ya se renovo, el resultado se descarta.
            if ($fresh->isActive() && $fresh->last_refreshed_at?->getTimestamp() === $before) {
                $accounts->applyRefresh($fresh, $result);
            }
        });
    }

    /**
     * El fallo pertenece a la cadena de tokens leida al empezar ($before): si mientras tanto la
     * cuenta se renovo o se reconecto (last_refreshed_at cambia), el error ya no aplica.
     */
    private static function sameChain(SocialAccount $fresh, ?int $before): bool
    {
        return $fresh->isActive() && $fresh->last_refreshed_at?->getTimestamp() === $before;
    }

    private function transientFailure(SocialAccountService $accounts, string $message, SocialAccount $account, ?int $before): void
    {
        Log::warning('social.refresh_transient_error', ['account_id' => $account->id, 'network' => $account->network->value]);

        $escalate = $this->locked(function (SocialAccount $fresh) use ($accounts, $message, $before): bool {
            if (! self::sameChain($fresh, $before)) {
                return false;
            }

            $failures = $accounts->recordTransientFailure($fresh, $message);

            return $failures >= (int) config('social.max_transient_failures', 12);
        });

        if ($escalate) {
            $this->requireReconnection($accounts, 'La renovacion falla desde hace horas: '.$message, $before);
        }
    }

    private function requireReconnection(SocialAccountService $accounts, string $reason, ?int $before): void
    {
        // El estado se cambia releyendo con lock: solo UNA transicion notifica (sin avisos duplicados).
        $account = null;
        $changed = $this->locked(function (SocialAccount $fresh) use ($accounts, $reason, $before, &$account): bool {
            if (! self::sameChain($fresh, $before)) {
                return false;
            }

            $account = $fresh;

            return $accounts->markRequiresReconnection($fresh, $reason);
        });

        if (! $changed || ! $account) {
            return;
        }

        try {
            $account->user->notify(new ReconnectRequired($account, $reason));
        } catch (Throwable $e) {
            Log::error('social.reconnect_notification_failed', ['account_id' => $account->id, 'exception' => $e::class]);
        }
    }

    /**
     * Relee la cuenta con `lockForUpdate` dentro de una transaccion y ejecuta $fn con la fila fresca.
     *
     * @template T
     *
     * @param  Closure(SocialAccount): T  $fn
     * @return T|null
     */
    private function locked(Closure $fn): mixed
    {
        return DB::transaction(function () use ($fn) {
            $fresh = SocialAccount::withoutUserScope()->lockForUpdate()->find($this->accountId);

            return $fresh ? $fn($fresh) : null;
        });
    }
}
