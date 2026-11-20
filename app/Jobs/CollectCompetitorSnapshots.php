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

        // Job sin usuario autenticado: opta explicitamente por salir del UserScope. Primero los que nunca tuvieron datos y los que llevan mas
        // tiempo sin sincronizar: con una red de cuota limitada (YouTube) nadie se queda siempre el ultimo por tener un id alto.
        Competitor::withoutUserScope()->orderByRaw('last_synced_at is not null')->orderBy('last_synced_at')->orderBy('id')->pluck('id')
            ->chunk(200)->each(function ($ids) use ($date): void {
                foreach ($ids as $id) {
                    CollectCompetitorSnapshot::dispatch($id, $date);
                }
            });
    }
}
