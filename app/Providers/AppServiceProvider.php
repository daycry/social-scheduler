<?php

namespace App\Providers;

use App\Connectors\Bluesky\BlueskyConnector;
use App\Connectors\Bluesky\BlueskyInsights;
use App\Connectors\Bluesky\BlueskyPublisher;
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
use App\Connectors\Threads\ThreadsConnector;
use App\Connectors\Threads\ThreadsInsights;
use App\Connectors\Threads\ThreadsPublisher;
use App\Connectors\TikTok\Display as TikTokDisplay;
use App\Connectors\TikTok\TikTokConnector;
use App\Connectors\TikTok\TikTokPublisher;
use App\Listeners\AuditPasskeyEvents;
use App\Models\Brand;
use App\Models\PersonalAccessToken;
use App\Models\Report;
use App\Models\User;
use App\Services\Auth\Social\FakeSocialiteProvider;
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
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\GoogleProvider;

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
            $registry = (new ConnectorRegistry)
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

            // Bluesky (C-01, D1: handle + app password). Se puede apagar con BLUESKY_ENABLED=false.
            if (config('social.bluesky.enabled', true)) {
                $registry->register($this->app->make(BlueskyConnector::class))
                    ->registerPublisher(Network::Bluesky, $this->app->make(BlueskyPublisher::class))
                    ->registerInsights(Network::Bluesky, $this->app->make(BlueskyInsights::class));
            }

            // Threads (C-02, D2: app de Meta propia). Solo con THREADS_ENABLED y THREADS_APP_ID: sin ellos la red no existe en ninguna superficie.
            if (Network::Threads->isAvailable()) {
                $registry->register($this->app->make(ThreadsConnector::class))
                    ->registerPublisher(Network::Threads, $this->app->make(ThreadsPublisher::class))
                    ->registerInsights(Network::Threads, $this->app->make(ThreadsInsights::class));
            }

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Login social (ADR-004): drivers con las credenciales PROPIAS del login (services.*_login), distintas de las de los conectores.
        Socialite::extend('google-login', fn () => Socialite::buildProvider(GoogleProvider::class, (array) config('services.google_login')));
        // Facebook: la version de Graph sale de la config (la del driver puede ser antigua) y solo se piden email y public_profile.
        Socialite::extend('facebook-login', fn () => Socialite::buildProvider(FacebookProvider::class, (array) config('services.facebook_login'))
            ->usingGraphVersion((string) config('social.meta.graph_version', 'v23.0'))
            ->scopes(['email', 'public_profile'])
            ->fields(['id', 'name', 'email']));

        // Bateria E2E (T-23): proveedor falso solo con APP_ENV=e2e y un APP_URL local; en el resto de entornos no hace nada.
        FakeSocialiteProvider::register();

        // Tokens con origen OAuth opcional (oauth_client_id); las API keys msk_ existentes no cambian.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

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
        // «Publicar ahora»: 30/min por usuario compartido entre posts.publish-now y las rutas del compositor (store/update) cuando
        // llevan `publish_now`; guardar un borrador o programar normal no gasta ese cupo.
        RateLimiter::for('publish-now', fn (Request $request) => $request->boolean('publish_now') || $request->routeIs('posts.publish-now')
            ? Limit::perMinute(30)->by('publish-now:'.($request->user()?->id ?? $request->ip()))
            : Limit::none());
        RateLimiter::for('giphy', fn (Request $request) => Limit::perMinute((int) config('social.giphy.per_user_per_minute', 20))
            ->by('giphy:'.($request->user()?->id ?? $request->ip())));

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
        // Callback del login social (ADR-004), por IP: 20/min.
        RateLimiter::for('social-login', fn (Request $request) => Limit::perMinute(20)->by('social-login:'.$request->ip()));
        // Servidor OAuth del MCP (ADR-004): DCR abierto a Internet, 10/h por IP; token endpoint, 30/min por IP.
        RateLimiter::for('mcp-oauth-register', fn (Request $request) => Limit::perHour(10)->by('mcp-oauth-register:'.self::ipKey($request->ip())));
        // Consentimiento OAuth: 30/min por usuario.
        RateLimiter::for('mcp-oauth-authorize', fn (Request $request) => Limit::perMinute(30)->by('mcp-oauth-authorize:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('mcp-oauth-token', fn (Request $request) => Limit::perMinute(30)->by('mcp-oauth-token:'.$request->ip()));
        RateLimiter::for('mcp-ip', fn (Request $request) => Limit::perMinute((int) config('services.mcp.per_minute_ip', 120))->by('mcp-ip:'.$request->ip()));
    }

    /** IPv4 tal cual; IPv6 agrupada por su /64 (un cliente recibe un /64 entero y rotaria de direccion). */
    private static function ipKey(?string $ip): string
    {
        $bin = $ip === null || ! str_contains($ip, ':') ? false : @inet_pton($ip);
        if ($bin === false) {
            return (string) $ip;
        }
        // IPv4 mapeada (::ffff:a.b.c.d, Nginx en dual-stack) y loopback: la direccion entera, no su /64 a ceros.
        if (str_starts_with($bin, str_repeat(chr(0), 10).chr(255).chr(255))) {
            return inet_ntop(substr($bin, 12));
        }
        if ($bin === str_repeat(chr(0), 15).chr(1)) {
            return '::1';
        }

        return bin2hex(substr($bin, 0, 8)).'/64';
    }
}
