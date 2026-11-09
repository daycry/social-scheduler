<?php

namespace App\Jobs;

use App\Connectors\Network;
use App\Models\SocialAccount;
use App\Services\InboxService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

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
            ->whereIn('network', array_map(fn (Network $n) => $n->value, InboxService::NETWORKS))
            ->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))
            ->chunkById(200, function ($accounts): void {
                foreach ($accounts as $account) {
                    // YouTube gasta cupo por llamada: como mucho una pasada por hora y cuenta (el resto del tiempo se omite).
                    if ($account->network === Network::YouTube && ! Cache::add('inbox:youtube-sync:'.$account->id, 1, max(1, (int) config('social.inbox.youtube_sync_minutes', 60)) * 60)) {
                        continue;
                    }
                    SyncAccountComments::dispatch($account->id);
                }
            });
    }
}
