<?php

namespace App\Jobs;

use App\Analytics\SnapshotCollector;
use App\Enums\PostStatus;
use App\Jobs\Concerns\GuardsInsightsErrors;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\SocialAccountService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Refresca las metricas de las publicaciones RECIENTES de UNA cuenta (T-36): cada `refresh_hours` (6 h) durante las
 * primeras `refresh_window_hours` (72 h) tras publicar, que es cuando mas cambian. Un snapshot por destino y dia
 * (upsert: la ultima lectura del dia gana). Unico por cuenta en cola `metrics`; $timeout 90 s < worker 120 s.
 * El despachador `dispatchDue()` solo encola cuentas activas con publicaciones en la ventana.
 *
 * Trabaja por BLOQUES con presupuesto de tiempo (`posts_budget_seconds` 60 s < $timeout 90 s): si no caben todas las
 * publicaciones, se reencola a si mismo con el desplazamiento por donde iba (`ShouldBeUniqueUntilProcessing` libera el
 * candado al empezar para poder encadenar). `hours` 0 = ventana de refresco; el snapshot diario pide `recent_post_days`.
 */
class RefreshPostMetrics implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use GuardsInsightsErrors, InteractsWithQueue, Queueable;

    public int $timeout = 90;

    public int $tries = 3;

    public int $backoff = 300;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $accountId, public readonly int $hours = 0, public readonly int $offset = 0)
    {
        $this->onQueue((string) config('social.analytics.queue', 'metrics'));
    }

    public function uniqueId(): string
    {
        return $this->accountId.($this->hours > 0 ? ':'.$this->hours : '');
    }

    /** Encola un job por cuenta activa con publicaciones dentro de la ventana de refresco. @return int cuentas encoladas */
    public static function dispatchDue(): int
    {
        $since = now()->subHours((int) config('social.analytics.refresh_window_hours', 72));
        $count = 0;

        // Despachador sin usuario autenticado: sale del UserScope de forma explicita.
        SocialAccount::withoutUserScope()
            ->where('status', SocialAccount::STATUS_ACTIVE)
            ->whereIn('id', PostTarget::withoutUserScope()
                ->where('status', PostStatus::Published->value)->whereNotNull('external_id')->where('published_at', '>=', $since)
                ->select('social_account_id'))
            ->chunkById(200, function ($accounts) use (&$count): void {
                foreach ($accounts as $account) {
                    self::dispatch($account->id);
                    $count++;
                }
            });

        return $count;
    }

    public function handle(SnapshotCollector $collector, SocialAccountService $accounts): void
    {
        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        $account = SocialAccount::withoutUserScope()->find($this->accountId);

        if (! $account || ! $account->isActive()) {
            return;
        }

        $hours = $this->hours > 0 ? $this->hours : (int) config('social.analytics.refresh_window_hours', 72);
        $next = null;

        $ok = $this->guarded($account, $accounts, function () use ($collector, $account, $hours, &$next): void {
            $next = $collector->collectPostsBatch($account, $hours, $this->offset, (float) config('social.analytics.posts_budget_seconds', 60))['next'];
        });

        if ($ok && $next !== null) {
            self::dispatch($this->accountId, $this->hours, $next);
        }
    }
}
