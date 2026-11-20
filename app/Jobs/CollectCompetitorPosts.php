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
 * Publicaciones recientes de competidores (T-20, cola `metrics`). Sin `competitorId` es el despachador (uno por competidor);
 * con el, lee y guarda las ultimas publicaciones publicas de esa cuenta (upsert por competidor + id externo: inocuo repetirlo).
 */
class CollectCompetitorPosts implements ShouldBeUnique, ShouldQueue
{
    use GuardsInsightsErrors, InteractsWithQueue, Queueable;

    public int $timeout = 90;

    public int $tries = 2;

    public int $backoff = 300;

    public int $uniqueFor = 3600;

    public function __construct(public readonly ?int $competitorId = null)
    {
        $this->onQueue((string) config('social.analytics.queue', 'metrics'));
    }

    public function uniqueId(): string
    {
        return 'competitor-posts:'.($this->competitorId ?? 'all');
    }

    public function handle(CompetitorService $competitors, SocialAccountService $accounts): void
    {
        if ($this->competitorId === null) {
            // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
            // Primero los que nunca tuvieron publicaciones y los que mas tiempo llevan sin actualizarse (reparto justo de la cuota).
            $last = '(select max(updated_at) from competitor_posts where competitor_posts.competitor_id = competitors.id)';
            Competitor::withoutUserScope()->orderByRaw("$last is not null")->orderByRaw($last)->orderBy('id')->pluck('id')
                ->chunk(200)->each(function ($ids): void {
                    foreach ($ids as $id) {
                        self::dispatch($id);
                    }
                });

            return;
        }

        $competitor = Competitor::withoutUserScope()->find($this->competitorId);
        if (! $competitor) {
            return;
        }
        if (! $competitors->available($competitor->network)) {
            return; // red deshabilitada en esta instalacion: no se consulta
        }
        $account = $competitors->accountFor($competitor);
        if ($account === null && $competitors->source($competitor->network)->needsAccount()) {
            return; // sin cuenta propia activa en esa red no hay con que consultar
        }

        $this->guarded($account, $accounts, function () use ($competitors, $competitor): void {
            try {
                $competitors->refreshPosts($competitor);
            } catch (CompetitorNotFound $e) {
                $competitor->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 200)])->save();
            }
        });
    }
}
