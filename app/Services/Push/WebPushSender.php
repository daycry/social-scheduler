<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Services\Media\UnsafeUrlException;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/** RFC8291/VAPID mediante la biblioteca mantenida; el contenido privado nunca sale del servidor. */
final class WebPushSender implements PushSender
{
    public function __construct(private readonly PushHttpClient $client, private readonly PushConfiguration $configuration) {}

    public function send(PushSubscription $subscription): PushResult
    {
        if (! $this->configuration->enabled()) {
            return PushResult::Rejected;
        }
        try {
            $factory = new HttpFactory;
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('push.vapid.subject'),
                'publicKey' => config('push.vapid.public_key'),
                'privateKey' => config('push.vapid.private_key'),
            ]], ['TTL' => 300, 'urgency' => 'normal'], $this->client, $factory, $factory);
            // El worker público fija todo el texto y la navegación; el payload solo despierta el evento.
            $report = $webPush->sendOneNotification(Subscription::create([
                'endpoint' => $subscription->endpoint, 'keys' => $subscription->keys, 'contentEncoding' => 'aes128gcm',
            ]), '{}');
            $status = $report->getResponse()?->getStatusCode();

            return match (true) {
                $status !== null && $status >= 200 && $status < 300 => PushResult::Delivered,
                $report->isSubscriptionExpired() => PushResult::Expired,
                $status === null, in_array($status, [408, 425, 429], true), $status >= 500 => PushResult::Retry,
                default => PushResult::Rejected,
            };
        } catch (ConnectionException) {
            return PushResult::Retry;
        } catch (UnsafeUrlException) {
            return PushResult::Rejected;
        } catch (Throwable $error) {
            Log::warning('No se pudo preparar el aviso push.', ['exception_class' => $error::class]);

            return PushResult::Rejected;
        }
    }
}
