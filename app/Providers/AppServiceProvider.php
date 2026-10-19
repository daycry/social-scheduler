<?php

namespace App\Providers;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Google\GoogleConnector;
use App\Connectors\Meta\MetaConnector;
use App\Connectors\TikTok\TikTokConnector;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Los conectores de cada proveedor se registran aqui (T-12 Meta, T-13 Google, T-14 TikTok).
        $this->app->singleton(ConnectorRegistry::class, function () {
            return (new ConnectorRegistry)
                ->register(new MetaConnector)
                ->register(new GoogleConnector)
                ->register(new TikTokConnector);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
