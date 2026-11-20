<?php

namespace App\Jobs;

use App\Services\Webhooks\WebhookDeliveryRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\CountCrashesAsExceptions;
use Throwable;

#[CountCrashesAsExceptions]
final class DeliverWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Los errores de infraestructura no gastan los cinco intentos HTTP de la entrega.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 20;

    public int $uniqueFor = 7200;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue((string) config('webhooks.queue', 'poll'));
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function backoff(): array
    {
        return WebhookDeliveryRunner::BACKOFF;
    }

    public function handle(WebhookDeliveryRunner $runner): void
    {
        if (($delay = $runner->run($this->deliveryId)) !== null) {
            $this->release($delay);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(WebhookDeliveryRunner::class)->markFailed($this->deliveryId);
    }
}
