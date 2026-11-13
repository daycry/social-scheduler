<?php

namespace App\Jobs;

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
 * Foto diaria de UN competidor (cola `metrics`, PA-08, T-25: Instagram, Bluesky y YouTube). Unico por competidor+dia y upsert por competidor+fecha:
 * re-ejecutarlo es inocuo. Presupuesto: $timeout (90 s) < `--timeout` del worker (120 s) < `retry_after` (180 s).
 * Una cuenta que ya no existe o dejo de ser profesional se anota en `last_error` y NO se reintenta. La cuota agotada (YouTube 403
 * `quotaExceeded`, 429) y los fallos transitorios se reintentan con la espera creciente de `GuardsInsightsErrors`.
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

    public function handle(CompetitorService $competitors, SocialAccountService $accounts): void
    {
        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        $competitor = Competitor::withoutUserScope()->find($this->competitorId);
        if (! $competitor) {
            return;
        }

        // Una red deshabilitada en esta instalacion no se consulta.
        if (! $competitors->available($competitor->network)) {
            return;
        }

        // Cada red lee con su fuente publica (Instagram y YouTube con el token de una cuenta propia; Bluesky sin cuenta).
        $account = $competitors->accountFor($competitor);
        if ($account === null && $competitors->source($competitor->network)->needsAccount()) {
            $competitor->forceFill(['last_error' => "Sin cuenta de {$competitor->network->label()} activa para consultar."])->save();

            return;
        }

        $this->guarded($account, $accounts, function () use ($competitors, $competitor, $account): void {
            try {
                $profile = $competitors->fetchProfile($competitor, $account);
            } catch (CompetitorNotFound $e) {
                $competitor->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 200)])->save();

                return;
            }

            $competitors->record($competitor, $profile);
        });
    }
}
