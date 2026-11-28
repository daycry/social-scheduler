<?php

namespace App\Services\Webhooks;

use App\Events\OutboundDomainEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Brand;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/** Outbox transaccional; el cuerpo firmado queda inmutable y cada suscripción recibe una entrega independiente. */
final class WebhookDispatcher
{
    public function handle(OutboundDomainEvent $event): void
    {
        if (! array_key_exists($event->name, Webhook::EVENTS)) {
            throw new InvalidArgumentException('Unsupported outbound event.');
        }
        if (! Brand::ownedBy($event->ownerId)->whereKey($event->brandId)->exists()) {
            return;
        }
        $hooks = Webhook::ownedBy($event->ownerId)->where('brand_id', $event->brandId)->where('enabled', true)
            ->whereJsonContains('events', $event->name)->get(['id']);
        if ($hooks->isEmpty()) {
            return;
        }
        DB::transaction(function () use ($event, $hooks): void {
            $stored = WebhookEvent::ownedBy($event->ownerId)->find($event->id);
            if ($stored === null) {
                $stored = new WebhookEvent;
                $stored->forceFill([
                    'id' => $event->id, 'user_id' => $event->ownerId, 'brand_id' => $event->brandId,
                    'event' => $event->name, 'body' => json_encode([
                        'id' => $event->id, 'event' => $event->name, 'occurred_at' => $event->occurredAt,
                        'brand_id' => $event->brandId, 'data' => $event->data,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ])->save();
            }
            foreach ($hooks as $hook) {
                $delivery = WebhookDelivery::ownedBy($event->ownerId)->where('webhook_id', $hook->id)->where('webhook_event_id', $event->id)->first();
                if ($delivery !== null) {
                    continue;
                }
                $delivery = new WebhookDelivery;
                $delivery->forceFill([
                    'user_id' => $event->ownerId, 'webhook_id' => $hook->id, 'webhook_event_id' => $event->id,
                    'next_attempt_at' => now(),
                ])->save();
                $id = (int) $delivery->id;
                DB::afterCommit(fn () => $this->enqueue($id));
            }
        });
    }

    public function enqueue(int $id): void
    {
        try {
            DeliverWebhook::dispatch($id);
        } catch (Throwable $error) {
            // PendingDispatch libera su lock único si falla la cola; el barrido puede volver a intentarlo.
            Log::warning('webhooks.enqueue_failed', ['delivery_id' => $id, 'exception' => $error::class]);
        }
    }
}
