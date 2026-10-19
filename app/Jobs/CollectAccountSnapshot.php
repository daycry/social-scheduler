<?php

namespace App\Jobs;

use App\Analytics\SnapshotCollector;
use App\Jobs\Concerns\GuardsInsightsErrors;
use App\Models\SocialAccount;
use App\Services\SocialAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Snapshot de UNA cuenta y UN dia (cola `metrics`). Unico por cuenta+dia: no se encola dos veces mientras haya uno
 * pendiente o en curso, y el resultado es un upsert por cuenta+fecha (re-ejecutarlo es inocuo).
 *
 * Las metricas de las publicaciones NO se leen aqui: se encadenan en `RefreshPostMetrics` (por bloques con presupuesto
 * de tiempo), para que un dia con muchas publicaciones no agote el `$timeout` de este job.
 *
 * Presupuesto: $timeout (90 s) < `--timeout` del worker (120 s) < `retry_after` de la cola (180 s). Fallos:
 *  - credenciales rechazadas -> la cuenta pasa a `requiere_reconexion` (y se avisa una vez);
 *  - transitorios (red, 5xx, limite) -> el job reintenta con espera; sin mas intentos queda en failed_jobs;
 *  - una metrica no admitida NO es un fallo: se guarda como `null` ("no disponible").
 */
class CollectAccountSnapshot implements ShouldBeUnique, ShouldQueue
{
    use GuardsInsightsErrors, InteractsWithQueue, Queueable;

    public int $timeout = 90;

    public int $tries = 3;

    public int $backoff = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $accountId,
        public readonly string $date,
        /** Tambien guarda las metricas de las publicaciones recientes (CA-09). */
        public readonly bool $withPosts = false,
        /** Solo si ese dia aun no esta asentado (reintentos de dias anteriores: sin flujos completos). */
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

        $this->guarded($account, $accounts, function () use ($collector, $account, $day): void {
            if (! $this->onlyIfMissing || $collector->needsCollection($account, $day)) {
                $collector->collectAccount($account, $day);
            }

            if ($this->withPosts) {
                RefreshPostMetrics::dispatch($account->id, (int) config('social.analytics.recent_post_days', 30) * 24);
            }
        });
    }
}
