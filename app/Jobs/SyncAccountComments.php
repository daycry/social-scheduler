<?php

namespace App\Jobs;

use App\Jobs\Concerns\GuardsInsightsErrors;
use App\Models\SocialAccount;
use App\Services\InboxService;
use App\Services\SocialAccountService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sincroniza los comentarios de UNA cuenta (cola `poll`, PA-10). Unico por cuenta hasta que empieza (asi puede encadenarse); `upsert` por
 * (cuenta, comentario) hace inocuo repetirlo. $timeout (90 s) < `--timeout` del worker (120 s). Trabaja con presupuesto de tiempo
 * (`social.inbox.budget_seconds`, 60 s < $timeout): si no caben todas las publicaciones, se reencola con el desplazamiento por donde iba.
 */
class SyncAccountComments implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use GuardsInsightsErrors, InteractsWithQueue, Queueable;

    public int $timeout = 90;

    public int $tries = 3;

    public int $backoff = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $accountId, public readonly int $offset = 0)
    {
        $this->onQueue((string) config('social.inbox.queue', 'poll'));
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(InboxService $inbox, SocialAccountService $accounts): void
    {
        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        $account = SocialAccount::withoutUserScope()->find($this->accountId);
        if (! $account) {
            return;
        }

        $next = null;

        $ok = $this->guarded($account, $accounts, function () use ($inbox, $account, &$next): void {
            $next = $inbox->syncBatch($account, $this->offset, (float) config('social.inbox.budget_seconds', 60))['next'];
        });

        if ($ok && $next !== null) {
            self::dispatch($this->accountId, $next);
        }
    }
}
