<?php

namespace App\Jobs;

use App\Connectors\Meta\BusinessDiscovery;
use App\Connectors\Meta\CompetitorNotFound;
use App\Jobs\Concerns\GuardsInsightsErrors;
use App\Models\Competitor;
use App\Services\CompetitorService;
use App\Services\SocialAccountService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Foto diaria de UN competidor (cola `metrics`, PA-08). Unico por competidor+dia y upsert por competidor+fecha:
 * re-ejecutarlo es inocuo. Presupuesto: $timeout (90 s) < `--timeout` del worker (120 s) < `retry_after` (180 s).
 * Una cuenta que ya no existe o dejo de ser profesional se anota en `last_error` y NO se reintenta.
 */
class CollectCompetitorSnapshot implements ShouldBeUnique, ShouldQueue
{
    use GuardsInsightsErrors, InteractsWithQueue, Queueable;

    public int $timeout = 90;

    public int $tries = 3;

    public int $backoff = 120;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $competitorId, public readonly string $date)
    {
        $this->onQueue((string) config('social.analytics.queue', 'metrics'));
    }

    public function uniqueId(): string
    {
        return $this->competitorId.':'.$this->date;
    }

    public function handle(BusinessDiscovery $discovery, CompetitorService $competitors, SocialAccountService $accounts): void
    {
        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        $competitor = Competitor::withoutUserScope()->find($this->competitorId);
        if (! $competitor) {
            return;
        }

        $account = $competitors->queryAccount($competitor->user_id, $competitor->brand_id, $competitor->social_account_id);
        if (! $account) {
            $competitor->forceFill(['last_error' => 'Sin cuenta de Instagram activa para consultar.'])->save();

            return;
        }

        $this->guarded($account, $accounts, function () use ($discovery, $competitors, $competitor, $account): void {
            try {
                $profile = $discovery->lookup($account, $competitor->username);
            } catch (CompetitorNotFound $e) {
                $competitor->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 200)])->save();

                return;
            }

            $competitors->record($competitor, $profile);
        });
    }
}
