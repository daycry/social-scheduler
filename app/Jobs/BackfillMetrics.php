<?php

namespace App\Jobs;

use App\Analytics\Days;
use App\Analytics\Period;
use App\Analytics\SnapshotCollector;
use App\Jobs\Concerns\GuardsInsightsErrors;
use App\Models\SocialAccount;
use App\Services\SocialAccountService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Historico inicial al conectar una cuenta (T-36): recorre del ultimo dia cerrado de la red hacia atras hasta el maximo
 * que se pide (`social.analytics.backfill_days`: IG 30, FB 90, YouTube 365, TikTok ninguno; tope Period::MAX_DAYS) y
 * guarda un snapshot por dia (upsert: repetirlo es inocuo; los dias ya asentados se saltan).
 *
 * Por rango cuando la red lo permite (`RangeInsightsReader`: YouTube `dimensions=day`, Facebook `since/until` de <= 30
 * dias): una consulta por tramo en vez de una por dia. Los ultimos dias (que llevan stocks) y Instagram (metricas
 * `total_value` que exigen un dia por llamada) van dia a dia.
 *
 * Presupuesto: cada pasada trabaja como mucho `backfill_budget_seconds` (80 s < $timeout 100 s < `--timeout` del worker
 * 120 s). Se comprueba ANTES de cada unidad de trabajo (un dia o un tramo) contando lo que costo la mas lenta de la
 * pasada, para no empezar una que no quepa; si quedan dias se reencola con el desplazamiento por donde iba.
 * `ShouldBeUniqueUntilProcessing` evita duplicados pendientes por cuenta pero libera el candado al empezar, para poder
 * encadenar la continuacion. Dias que la API ya no ofrece quedan como snapshot vacio ("no disponible"), nunca como 0.
 */
class BackfillMetrics implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use GuardsInsightsErrors, InteractsWithQueue, Queueable;

    /** Dias recientes que se leen siempre uno a uno (llevan los stocks: la API solo da el valor actual). */
    private const RECENT_DAYS = 3;

    public int $timeout = 100;

    public int $tries = 5;

    public int $backoff = 300;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $accountId, public readonly int $offset = 0)
    {
        $this->onQueue((string) config('social.analytics.queue', 'metrics'));
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(SnapshotCollector $collector, SocialAccountService $accounts): void
    {
        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        $account = SocialAccount::withoutUserScope()->find($this->accountId);

        if (! $account || ! $account->isActive()) {
            return;
        }

        $total = min(Period::MAX_DAYS, max(0, (int) config('social.analytics.backfill_days.'.$account->network->value, 0)));
        $budget = max(1, (int) config('social.analytics.backfill_budget_seconds', 80));
        $started = microtime(true);
        $slowest = 0.0;
        $newest = Days::closed($account->network);
        $range = $collector->rangeReader($account);
        $next = $this->offset;

        $ok = $this->guarded($account, $accounts, function () use ($collector, $account, $total, $budget, $started, &$slowest, $newest, $range, &$next): void {
            while ($next < $total) {
                // Sin presupuesto para otra unidad (contando la mas lenta ya vista): continua otra pasada.
                if ($next > $this->offset && (microtime(true) - $started) + $slowest > $budget) {
                    return;
                }

                $t0 = microtime(true);
                $day = $newest->subDays($next);
                $span = $range !== null && $next >= self::RECENT_DAYS ? min($range->maxRangeDays($account), $total - $next) : 1;

                if ($span > 1) {
                    $collector->collectRange($account, $day->subDays($span - 1), $day);
                } elseif ($collector->needsCollection($account, $day)) {
                    $collector->collectAccount($account, $day);
                }

                $next += max(1, $span);
                $slowest = max($slowest, microtime(true) - $t0);
            }
        });

        if ($ok && $next < $total) {
            self::dispatch($this->accountId, $next);
        }
    }
}
