<?php

namespace App\Providers;

use App\Events\OutboundDomainEvent;
use App\Models\InboxComment;
use App\Models\PostTarget;
use App\Observers\WebhookDomainObserver;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class WebhookServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('webhook-settings', fn (Request $request) => Limit::perMinute(max(1, (int) config('webhooks.settings_per_minute', 30)))
            ->by('webhook-settings:'.$request->user()->id));
        PostTarget::observe(WebhookDomainObserver::class);
        InboxComment::observe(WebhookDomainObserver::class);
        Event::listen(OutboundDomainEvent::class, [WebhookDispatcher::class, 'handle']);
    }
}
