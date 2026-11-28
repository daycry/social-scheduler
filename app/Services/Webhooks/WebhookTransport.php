<?php

namespace App\Services\Webhooks;

use App\Models\Webhook;
use App\Models\WebhookEvent;
use App\Services\Media\UnsafeUrlException;
use App\Services\Media\UrlGuard;
use App\Services\Media\ValidatedUrl;

/** Una petición HTTPS con IP fijada, sin redirección ni proxy. El cuerpo firmado no se recodifica. */
final class WebhookTransport
{
    public function __construct(private readonly UrlGuard $guard) {}

    /** La resolución DNS ocurre antes de adquirir el lock de entrega. */
    public function prepare(string $endpoint): ValidatedUrl
    {
        $url = $this->guard->validate($endpoint);
        if ($url->scheme !== 'https') {
            throw new UnsafeUrlException('El receptor debe usar HTTPS.');
        }

        return $url;
    }

    public function send(Webhook $hook, WebhookEvent $event, ValidatedUrl $url): int
    {
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$event->body, $hook->secret);
        $response = $this->guard->deliveryClient($url)->connectTimeout(3)->timeout(10)
            ->acceptJson()->withBody($event->body, 'application/json')->withHeaders([
                'X-SocialScheduler-Signature' => 't='.$timestamp.',v1='.$signature,
                'X-SocialScheduler-Event' => $event->event,
                'X-SocialScheduler-Event-Id' => $event->id,
            ])->post($url->url);

        return $response->status();
    }
}
