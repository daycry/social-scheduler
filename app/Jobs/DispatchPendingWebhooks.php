<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Recupera el tramo DB confirmada → cola caída, sin saltarse la fecha de backoff ni enviar HTTP. */
final class DispatchPendingWebhooks implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue((string) config('webhooks.queue', 'poll'));
    }

    public function handle(WebhookDispatcher $dispatcher): void
    {
        foreach (WebhookDelivery::withoutUserScope()->where('status', 'pending')->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->orderBy('id')->limit(500)->get(['id']) as $delivery) {
            $dispatcher->enqueue((int) $delivery->id);
        }
    }
}
