<?php

namespace App\Jobs;

use App\Analytics\Days;
use App\Models\SocialAccount;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Despachador (rapido) del snapshot diario (CA-09): encola un `CollectAccountSnapshot` por cada cuenta activa y
 * dia pendiente. El dia de referencia es el ultimo dia CERRADO de la red en SU zona (Meta y YouTube cuentan el dia en
 * el Pacifico, no en UTC: ver Analytics\Days); ademas se reintentan los `settle_days` anteriores mientras su snapshot
 * no este completo (faltan flujos diarios: latencia de YouTube, caidas, limites de uso), no solo si falta o esta vacio.
 * Trabajar por cuenta aisla una red lenta o caida del resto.
 */
class CollectSnapshots implements ShouldBeUnique, ShouldQueue
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
        $settle = max(0, (int) config('social.analytics.settle_days', 3));

        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        SocialAccount::withoutUserScope()
            ->where('status', SocialAccount::STATUS_ACTIVE)
            ->chunkById(200, function ($accounts) use ($settle): void {
                foreach ($accounts as $account) {
                    $yesterday = Days::closed($account->network);

                    CollectAccountSnapshot::dispatch($account->id, $yesterday->format('Y-m-d'), true);

                    for ($i = 1; $i <= $settle; $i++) {
                        CollectAccountSnapshot::dispatch($account->id, $yesterday->copy()->subDays($i)->format('Y-m-d'), false, true);
                    }
                }
            });
    }
}
