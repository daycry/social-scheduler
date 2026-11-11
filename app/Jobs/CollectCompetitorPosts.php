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
            Competitor::withoutUserScope()->chunkById(200, function ($rows): void {
                foreach ($rows as $competitor) {
                    self::dispatch($competitor->id);
                }
            });

            return;
        }

        $competitor = Competitor::withoutUserScope()->find($this->competitorId);
        $account = $competitor ? $competitors->queryAccount($competitor->user_id, $competitor->brand_id, $competitor->social_account_id) : null;
        if (! $competitor || ! $account) {
            return;
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
