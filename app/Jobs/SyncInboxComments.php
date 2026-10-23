<?php

namespace App\Jobs;

use App\Connectors\Network;
use App\Models\SocialAccount;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Despachador (rapido) de la sincronizacion periodica del buzon (PA-10): un `SyncAccountComments` por cuenta activa de
 * Instagram/Facebook (cola `poll`). `$userId` acota a las cuentas de un usuario (boton «Actualizar»).
 */
class SyncInboxComments implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public readonly ?int $userId = null)
    {
        $this->onQueue((string) config('social.inbox.queue', 'poll'));
    }

    public function uniqueId(): string
    {
        return 'inbox:'.($this->userId ?? 'all');
    }

    public function handle(): void
    {
        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        SocialAccount::withoutUserScope()->where('status', SocialAccount::STATUS_ACTIVE)
            ->whereIn('network', [Network::Instagram->value, Network::Facebook->value])
            ->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))
            ->chunkById(200, function ($accounts): void {
                foreach ($accounts as $account) {
                    SyncAccountComments::dispatch($account->id);
                }
            });
    }
}
