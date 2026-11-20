<?php

namespace App\Services\Webhooks;

use App\Models\Brand;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Media\UnsafeUrlException;
use App\Services\Media\ValidatedUrl;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;

/** Serializa intentos; el receptor deduplica por event id si debe repetirse una petición ambigua. */
final class WebhookDeliveryRunner
{
    public const MAX_ATTEMPTS = 5;

    public const BACKOFF = [60, 300, 900, 3600];

    public function __construct(private readonly WebhookTransport $transport) {}

    /** @return ?int Segundos hasta reintentar; null indica que no debe reencolarse. */
    public function run(int $id): ?int
    {
        [$url, $preparationError] = $this->prepareUrl($id);

        // Sin reintentos automáticos de la transacción: repetiría el efecto HTTP de un intento ambiguo.
        return DB::transaction(function () use ($id, $url, $preparationError): ?int {
            $delivery = WebhookDelivery::withoutUserScope()->lockForUpdate()->find($id);
            if ($delivery === null || $delivery->status !== 'pending') {
                return null;
            }
            if ($delivery->next_attempt_at?->isFuture()) {
                return max(1, (int) ceil(now()->diffInSeconds($delivery->next_attempt_at)));
            }
            $event = WebhookEvent::ownedBy($delivery->user_id)->find($delivery->webhook_event_id);
            $hook = Webhook::ownedBy($delivery->user_id)->find($delivery->webhook_id);
            if (! $this->eligible($delivery, $hook, $event)) {
                $this->finish($delivery, 'cancelled', 'subscription_unavailable');

                return null;
            }
            if ($delivery->attempts >= self::MAX_ATTEMPTS) {
                $this->finish($delivery, 'exhausted', $delivery->last_error);

                return null;
            }
            // Una reactivación durante la prelectura se reevalúa sin enviar una URL sin validar.
            if ($url === null && $preparationError === null) {
                return 1;
            }
            $delivery->attempts++;
            // Toma también el lock de escritura SQLite antes de cualquier efecto HTTP.
            $delivery->save();
            $outcome = $preparationError !== null
                ? ['state' => 'blocked', 'error' => $preparationError, 'http' => null]
                : $this->send($hook, $event, $url);
            $delivery->last_http_status = $outcome['http'];
            $delivery->last_error = $outcome['error'];
            if ($outcome['state'] !== 'pending') {
                if ($outcome['state'] === 'delivered') {
                    $delivery->delivered_at = now();
                }
                $this->finish($delivery, $outcome['state'], $outcome['error']);

                return null;
            }
            if ($delivery->attempts >= self::MAX_ATTEMPTS) {
                $this->finish($delivery, 'exhausted', $delivery->last_error);

                return null;
            }
            $delay = self::BACKOFF[$delivery->attempts - 1];
            $delivery->forceFill(['next_attempt_at' => now()->addSeconds($delay)])->save();

            return $delay;
        });
    }

    /** @return array{?ValidatedUrl, ?string} */
    private function prepareUrl(int $id): array
    {
        $delivery = WebhookDelivery::withoutUserScope()->find($id);
        if ($delivery === null || $delivery->status !== 'pending' || $delivery->next_attempt_at?->isFuture()
            || $delivery->attempts >= self::MAX_ATTEMPTS) {
            return [null, null];
        }
        $hook = Webhook::ownedBy($delivery->user_id)->find($delivery->webhook_id);
        if ($hook === null || ! $hook->enabled) {
            return [null, null];
        }
        try {
            return [$this->transport->prepare($hook->url), null];
        } catch (UnsafeUrlException) {
            return [null, 'unsafe_url'];
        }
    }

    private function eligible(WebhookDelivery $delivery, ?Webhook $hook, ?WebhookEvent $event): bool
    {
        return $event !== null && $hook !== null && $hook->enabled && $hook->brand_id === $event->brand_id
            && in_array($event->event, $hook->events, true)
            && Brand::ownedBy($delivery->user_id)->whereKey($event->brand_id)->exists();
    }

    /** @return array{state: string, error: ?string, http: ?int} */
    private function send(Webhook $hook, WebhookEvent $event, ValidatedUrl $url): array
    {
        try {
            return $this->classify($this->transport->send($hook, $event, $url));
        } catch (DecryptException) {
            return ['state' => 'blocked', 'error' => 'invalid_secret', 'http' => null];
        } catch (ConnectionException|RequestException|TransferException) {
            return ['state' => 'pending', 'error' => 'connection_error', 'http' => null];
        }
    }

    /** @return array{state: string, error: ?string, http: int} */
    private function classify(int $status): array
    {
        [$state, $error] = match (true) {
            $status >= 200 && $status < 300 => ['delivered', null],
            $status >= 300 && $status < 400 => ['blocked', 'redirect'],
            $status >= 500 || in_array($status, [408, 425, 429], true) => ['pending', 'http_error'],
            default => ['failed', 'http_error'],
        };

        return ['state' => $state, 'error' => $error, 'http' => $status];
    }

    public function markFailed(int $id): void
    {
        WebhookDelivery::withoutUserScope()->whereKey($id)->where('status', 'pending')->update([
            'status' => 'exhausted', 'last_error' => 'worker_failed', 'finished_at' => now(), 'next_attempt_at' => null,
        ]);
    }

    private function finish(WebhookDelivery $delivery, string $status, ?string $error): void
    {
        $delivery->forceFill(['status' => $status, 'last_error' => $error, 'finished_at' => now(), 'next_attempt_at' => null])->save();
    }
}
