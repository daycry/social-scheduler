<?php

namespace App\Providers;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Google\Analytics as YouTubeAnalytics;
use App\Connectors\Google\GoogleConnector;
use App\Connectors\Google\YouTubePublisher;
use App\Connectors\Meta\Facebook\FacebookPublisher;
use App\Connectors\Meta\GraphClient;
use App\Connectors\Meta\Insights as MetaInsights;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Connectors\Meta\MetaConnector;
use App\Connectors\Network;
use App\Connectors\TikTok\Display as TikTokDisplay;
use App\Connectors\TikTok\TikTokConnector;
use App\Connectors\TikTok\TikTokPublisher;
use App\Listeners\AuditPasskeyEvents;
use App\Models\Brand;
use App\Models\Report;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Passkeys (T-08): las rutas del paquete no se cargan; las propias (routes/auth.php) llaman a sus Actions
        // a traves de PasskeyGateway. Hay que ignorarlas antes de que arranque el provider del paquete.
        Passkeys::ignoreRoutes();

        // Los conectores de cada proveedor se registran aqui (T-12 Meta, T-13 Google, T-14 TikTok).
        $this->app->singleton(ConnectorRegistry::class, function () {
            return (new ConnectorRegistry)
                ->register(new MetaConnector)
                ->register(new GoogleConnector)
                ->register(new TikTokConnector)
                // Publicadores por red (T-21 Instagram, T-22 Facebook, T-26 YouTube, T-27 TikTok).
                ->registerPublisher(Network::Instagram, new InstagramPublisher)
                ->registerPublisher(Network::Facebook, new FacebookPublisher)
                ->registerPublisher(Network::YouTube, new YouTubePublisher)
                ->registerPublisher(Network::TikTok, new TikTokPublisher)
                // Lectores de metricas por red (T-32 Meta, T-33 YouTube Analytics, T-34 TikTok Display).
                ->registerInsights(Network::Instagram, new MetaInsights(new GraphClient))
                ->registerInsights(Network::Facebook, new MetaInsights(new GraphClient))
                ->registerInsights(Network::YouTube, new YouTubeAnalytics)
                ->registerInsights(Network::TikTok, new TikTokDisplay);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // `php artisan about`: avisa si el handle de usuario de las passkeys cae en APP_KEY (ver config/passkeys.php).
        AboutCommand::add('Passkeys', fn () => [
            'RP ID' => config('passkeys.relying_party_id'),
            'Handle secret' => config('passkeys.user_handle_secret_explicit') ? 'propio' : '<fg=yellow>sale de APP_KEY (define PASSKEYS_USER_HANDLE_SECRET)</>',
        ]);

        // Passkeys (T-09): los eventos del paquete van a la auditoria (channel web), sin material de la credencial.
        Event::listen(PasskeyRegistered::class, [AuditPasskeyEvents::class, 'registered']);
        Event::listen(PasskeyVerified::class, [AuditPasskeyEvents::class, 'verified']);
        Event::listen(PasskeyDeleted::class, [AuditPasskeyEvents::class, 'deleted']);

        // Informes (M-9): los FK en cascada no disparan eventos de modelo; se borran antes los ficheros de la marca/usuario.
        Brand::deleting(fn (Brand $b) => Report::purgeFor('brand_id', $b->id));
        User::deleting(fn (User $u) => Report::purgeFor('user_id', $u->id));

        // MCP (T-28/T-30): limite por API key (tras autenticar) y por IP (antes, contra adivinacion de keys).
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute((int) config('services.mcp.per_minute', 60))
            ->by('mcp:'.($request->user()?->currentAccessToken()?->id ?? $request->ip())));
        // Gestion del 2FA: contador propio por usuario (el `throttle:10,1` anonimo comparte clave con otras rutas).
        RateLimiter::for('two-factor-manage', fn (Request $request) => Limit::perMinute(10)->by('2fa-manage:'.($request->user()?->id ?? $request->ip())));
        // Login con passkey (guest), por IP y con contadores separados: el envio de la assertion (10/min, CA-21) y el GET de
        // opciones (30/min: el autofill lo pide en cada carga de Login y no debe gastar el cupo del envio).
        RateLimiter::for('passkey-login', fn (Request $request) => Limit::perMinute(10)->by('passkey-login:'.$request->ip()));
        RateLimiter::for('passkey-login-options', fn (Request $request) => Limit::perMinute(30)->by('passkey-login-options:'.$request->ip()));
        // Gestion de passkeys (perfil): contador propio por usuario, no compartido con el 2FA ni con el login.
        RateLimiter::for('passkey-manage', fn (Request $request) => Limit::perMinute(20)->by('passkey-manage:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('mcp-ip', fn (Request $request) => Limit::perMinute((int) config('services.mcp.per_minute_ip', 120))->by('mcp-ip:'.$request->ip()));
    }
}
