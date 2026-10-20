<?php

namespace App\Jobs;

use App\Jobs\Concerns\GuardsInsightsErrors;
use App\Models\SocialAccount;
use App\Services\InboxService;
use App\Services\SocialAccountService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sincroniza los comentarios de UNA cuenta (cola `poll`, PA-10). Unico por cuenta mientras corre; `upsert` por
 * (cuenta, comentario) hace inocuo repetirlo. $timeout (90 s) < `--timeout` del worker (120 s).
 */
class SyncAccountComments implements ShouldBeUnique, ShouldQueue
{
    use GuardsInsightsErrors, InteractsWithQueue, Queueable;

    public int $timeout = 90;

    public int $tries = 3;

    public int $backoff = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $accountId)
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

        $this->guarded($account, $accounts, function () use ($inbox, $account): void {
            $inbox->sync($account);
        });
    }
}
