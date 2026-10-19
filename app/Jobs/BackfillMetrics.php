<?php

namespace App\Jobs;

use App\Analytics\SnapshotCollector;
use App\Jobs\Concerns\GuardsInsightsErrors;
use App\Models\SocialAccount;
use App\Services\SocialAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Historico inicial al conectar una cuenta (T-36): recorre del dia de ayer hacia atras hasta el maximo que ofrece
 * la red (`social.analytics.backfill_days`: Meta ~30 dias de cuenta, YouTube 90, TikTok ninguno) y guarda un
 * snapshot por dia (upsert: repetirlo es inocuo; los dias que ya tienen datos se saltan).
 *
 * Presupuesto: cada pasada trabaja como mucho `backfill_budget_seconds` (80 s < $timeout 100 s < `--timeout` del
 * worker 120 s); si quedan dias se reencola con el desplazamiento por donde iba. `ShouldBeUniqueUntilProcessing`
 * evita duplicados pendientes por cuenta pero libera el candado al empezar, para poder encadenar la continuacion.
 * Dias que la API ya no ofrece quedan como snapshot vacio ("no disponible"), nunca como 0.
 */
class BackfillMetrics implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use GuardsInsightsErrors, InteractsWithQueue, Queueable;

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

        $total = max(0, (int) config('social.analytics.backfill_days.'.$account->network->value, 0));
        $budget = max(1, (int) config('social.analytics.backfill_budget_seconds', 80));
        $started = microtime(true);
        $yesterday = CarbonImmutable::now('UTC')->startOfDay()->subDay();
        $next = $this->offset;

        $ok = $this->guarded($account, $accounts, function () use ($collector, $account, $total, $budget, $started, $yesterday, &$next): void {
            for (; $next < $total; $next++) {
                if (microtime(true) - $started >= $budget) {
                    return; // sin presupuesto: continua otra pasada
                }

                $day = $yesterday->subDays($next);

                if (! $collector->hasData($account, $day)) {
                    $collector->collectAccount($account, $day);
                }
            }
        });

        if ($ok && $next < $total) {
            self::dispatch($this->accountId, $next);
        }
    }
}
