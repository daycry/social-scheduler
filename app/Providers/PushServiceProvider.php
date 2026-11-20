<?php

namespace App\Providers;

use App\Services\Push\PushSender;
use App\Services\Push\WebPushSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class PushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PushSender::class, WebPushSender::class);
    }

    public function boot(): void
    {
        RateLimiter::for('push-settings', fn (Request $request) => Limit::perMinute(max(1, (int) config('push.settings_per_minute', 30)))
            ->by('push-settings:'.$request->user()->id));
        // Application::configure descubre app/Listeners por su handle tipado; no registrarlos dos veces.
    }
}
