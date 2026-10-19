<?php

namespace App\Jobs;

use App\Analytics\SnapshotCollector;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Services\SocialAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Snapshot de UNA cuenta y UN dia (cola `metrics`). Unico por cuenta+dia: no se encola dos veces mientras haya uno
 * pendiente o en curso, y el resultado es un upsert por cuenta+fecha (re-ejecutarlo es inocuo).
 *
 * Presupuesto: $timeout (90 s) < `--timeout` del worker (120 s) < `retry_after` de la cola (180 s). Fallos:
 *  - credenciales rechazadas -> la cuenta pasa a `requiere_reconexion` (y se avisa una vez);
 *  - transitorios (red, 5xx, limite) -> el job reintenta con espera; sin mas intentos queda en failed_jobs;
 *  - una metrica no admitida NO es un fallo: se guarda como `null` ("no disponible").
 */
class CollectAccountSnapshot implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 90;

    public int $tries = 3;

    public int $backoff = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $accountId,
        public readonly string $date,
        /** Tambien guarda las metricas de las publicaciones recientes (CA-09). */
        public readonly bool $withPosts = false,
        /** Solo si ese dia aun no tiene datos (reintentos de dias anteriores). */
        public readonly bool $onlyIfMissing = false,
    ) {
        $this->onQueue((string) config('social.analytics.queue', 'metrics'));
    }

    public function uniqueId(): string
    {
        return $this->accountId.':'.$this->date;
    }

    public function handle(SnapshotCollector $collector, SocialAccountService $accounts): void
    {
        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        $account = SocialAccount::withoutUserScope()->find($this->accountId);

        if (! $account || ! $account->isActive()) {
            return;
        }

        $day = CarbonImmutable::createFromFormat('Y-m-d', $this->date, 'UTC')->startOfDay();

        try {
            if (! ($this->onlyIfMissing && $collector->hasData($account, $day))) {
                $collector->collectAccount($account, $day);
            }

            if ($this->withPosts) {
                $collector->collectPosts($account, (int) config('social.analytics.recent_post_days', 30) * 24);
            }
        } catch (ReconnectRequiredException $e) {
            $accounts->flagReconnection($account, $e->getMessage());
        } catch (TransientPublishException $e) {
            Log::warning('analytics.snapshot_transient', ['account_id' => $account->id, 'network' => $account->network->value, 'code' => $e->errorCode]);

            $this->release($this->backoff);
        } catch (ConnectorException $e) {
            Log::warning('analytics.snapshot_error', ['account_id' => $account->id, 'exception' => $e::class]);
        } catch (Throwable $e) {
            // Inesperado: se registra sin datos sensibles y la siguiente pasada diaria vuelve a intentarlo.
            Log::error('analytics.snapshot_unexpected', ['account_id' => $account->id, 'exception' => $e::class]);
        }
    }
}
