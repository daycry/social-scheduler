<?php

namespace App\Jobs;

use App\Models\Competitor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Despachador (rapido) del snapshot diario de competidores: un `CollectCompetitorSnapshot` por competidor y dia (cola `metrics`). */
class CollectCompetitorSnapshots implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue((string) config('social.analytics.queue', 'metrics'));
    }

    public function handle(): void
    {
        $date = now('UTC')->toDateString();

        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        Competitor::withoutUserScope()->chunkById(200, function ($competitors) use ($date): void {
            foreach ($competitors as $competitor) {
                CollectCompetitorSnapshot::dispatch($competitor->id, $date);
            }
        });
    }
}
