<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Despachador (rapido) del snapshot diario (CA-09): encola un `CollectAccountSnapshot` por cada cuenta activa y
 * dia pendiente. El dia de referencia es AYER en UTC (ya cerrado); ademas se reintentan los `settle_days`
 * anteriores si su snapshot falta o salio vacio (caidas del job, latencia de procesado de YouTube).
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
        $yesterday = now()->utc()->startOfDay()->subDay();
        $settle = max(0, (int) config('social.analytics.settle_days', 2));

        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        SocialAccount::withoutUserScope()
            ->where('status', SocialAccount::STATUS_ACTIVE)
            ->chunkById(200, function ($accounts) use ($yesterday, $settle): void {
                foreach ($accounts as $account) {
                    CollectAccountSnapshot::dispatch($account->id, $yesterday->format('Y-m-d'), true);

                    for ($i = 1; $i <= $settle; $i++) {
                        CollectAccountSnapshot::dispatch($account->id, $yesterday->copy()->subDays($i)->format('Y-m-d'), false, true);
                    }
                }
            });
    }
}
