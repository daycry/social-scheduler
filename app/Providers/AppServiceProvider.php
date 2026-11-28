<?php

namespace App\Providers;

use App\Connectors\Bluesky\BlueskyComments;
use App\Connectors\Bluesky\BlueskyCompetitors;
use App\Connectors\Bluesky\BlueskyConnector;
use App\Connectors\Bluesky\BlueskyInsights;
use App\Connectors\Bluesky\BlueskyPublisher;
use App\Connectors\CompetitorSources;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Google\Analytics as YouTubeAnalytics;
use App\Connectors\Google\GoogleConnector;
use App\Connectors\Google\YouTubeComments;
use App\Connectors\Google\YouTubeCompetitors;
use App\Connectors\Google\YouTubePublisher;
use App\Connectors\LinkedIn\LinkedInConnector;
use App\Connectors\LinkedIn\LinkedInPublisher;
use App\Connectors\Mastodon\MastodonConnector;
use App\Connectors\Mastodon\MastodonPublisher;
use App\Connectors\Meta\Comments;
use App\Connectors\Meta\Facebook\FacebookPublisher;
use App\Connectors\Meta\GraphClient;
use App\Connectors\Meta\Insights as MetaInsights;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Connectors\Meta\InstagramCompetitors;
use App\Connectors\Meta\MetaConnector;
use App\Connectors\Network;
use App\Connectors\Pinterest\PinterestConnector;
use App\Connectors\Pinterest\PinterestInsightsReader;
use App\Connectors\Pinterest\PinterestPublisher;
use App\Connectors\Telegram\TelegramConnector;
use App\Connectors\Telegram\TelegramPublisher;
use App\Connectors\Threads\ThreadsComments;
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
use App\Notifications\QueueJobFailedAlert;
use App\Services\Auth\Social\FakeSocialiteProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkeys;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Throwable;

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

        // Fuentes de competidores (T-25), una por red detras del contrato `CompetitorSource`.
        $this->app->singleton(CompetitorSources::class, fn ($app) => new CompetitorSources([
            $app->make(InstagramCompetitors::class),
            $app->make(BlueskyCompetitors::class),
            $app->make(YouTubeCompetitors::class),
        ]));

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

            // LinkedIn y Pinterest (T-18): solo con su app configurada (config/env, vacia por defecto) y su interruptor encendido.
            if (Network::LinkedIn->isAvailable()) {
                $linkedin = $this->app->make(LinkedInPublisher::class);
                $registry->register($this->app->make(LinkedInConnector::class))->registerPublisher(Network::LinkedIn, $linkedin);
            }
            if (Network::Pinterest->isAvailable()) {
                $registry->register($this->app->make(PinterestConnector::class))
                    ->registerPublisher(Network::Pinterest, $this->app->make(PinterestPublisher::class))
                    ->registerInsights(Network::Pinterest, $this->app->make(PinterestInsightsReader::class));
            }

            // Mastodon y Telegram (T-87): se conectan con credenciales y solo publican (sin comentarios ni metricas). Se apagan con MASTODON_ENABLED / TELEGRAM_ENABLED.
            if (Network::Mastodon->isAvailable()) {
                $registry->register($this->app->make(MastodonConnector::class))->registerPublisher(Network::Mastodon, $this->app->make(MastodonPublisher::class));
            }
            if (Network::Telegram->isAvailable()) {
                $registry->register($this->app->make(TelegramConnector::class))->registerPublisher(Network::Telegram, $this->app->make(TelegramPublisher::class));
            }

            // Comentarios del inbox (T-43): una fuente por red; Meta sirve a Instagram y Facebook y es la unica que oculta. El inbox, el MCP y la
            // cobertura derivan sus redes y `can_hide` de este registro: una red nueva con `CommentSource` aparece sin tocar `InboxService`.
            $meta = $this->app->make(Comments::class);
            $registry->registerComments(Network::Instagram, $meta)->registerComments(Network::Facebook, $meta)
                ->registerComments(Network::Threads, $this->app->make(ThreadsComments::class))
                ->registerComments(Network::Bluesky, $this->app->make(BlueskyComments::class))
                ->registerComments(Network::YouTube, $this->app->make(YouTubeComments::class));

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Builder::macro('whereLikeEscaped', function (string $column, string $text, string $boolean = 'and'): Builder {
            $column = $this->getGrammar()->wrap($column);
            $literal = strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']);

            return $this->whereRaw("{$column} LIKE ? ESCAPE '!'", ['%'.$literal.'%'], $boolean);
        });

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

        // Salud (A-09): un job que agota sus reintentos avisa por correo al administrador (HEALTH_ALERT_EMAIL), como mucho una vez
        // por intervalo para no inundar. Un fallo del propio aviso nunca debe afectar al worker.
        Queue::failing(function (JobFailed $event): void {
            $email = (string) config('health.alert_email');
            if ($email === '') {
                return;
            }
            try {
                $ttl = max(1, (int) config('health.alert_throttle_minutes', 60));
                if (! Cache::add('health:failed-job-alert', 1, now()->addMinutes($ttl))) {
                    return;
                }
                Notification::route('mail', $email)->notifyNow(new QueueJobFailedAlert($event->job->resolveName(), (string) $event->job->getQueue(), $event->exception::class));
            } catch (Throwable $e) {
                Log::warning('health.failed_job_alert_error', ['exception' => $e::class]);
            }
        });

        // Informes (M-9): los FK en cascada no disparan eventos de modelo; se borran antes los ficheros de la marca/usuario.
        Brand::deleting(fn (Brand $b) => Report::purgeFor('brand_id', $b->id));
        User::deleting(fn (User $u) => Report::purgeFor('user_id', $u->id));

        // Las rutas por nombre y los grupos comparten el criterio usuario/IP, con un contador por nombre.
        foreach (['groups', 'limits'] as $section) {
            foreach ((array) config("rate_limits.{$section}") as $name => $default) {
                RateLimiter::for($name, fn (Request $request) => Limit::perMinute((int) (config("rate_limits.{$section}")[$name] ?? $default))
                    ->by($name.':'.($request->user()?->id ?? $request->ip())));
            }
        }

        foreach ((array) config('rate_limits.per_ip') as $name => $default) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute((int) config("rate_limits.per_ip.{$name}", $default))
                ->by($name.':'.$request->ip()));
        }

        // Guardar o programar sin publicar ahora no consume el cupo.
        RateLimiter::for('publish-now', fn (Request $request) => $request->boolean('publish_now') || $request->routeIs('posts.publish-now')
            ? Limit::perMinute((int) config('rate_limits.publish_now'))->by('publish-now:'.($request->user()?->id ?? $request->ip()))
            : Limit::none());
        RateLimiter::for('studio-link-comments', fn (Request $request) => Limit::perMinute((int) config('rate_limits.studio_link_comments'))
            ->by('studio-link-comments:'.hash('sha256', (string) $request->route('token'))));
        // Feed .ics (T-90): un cupo por token (el cliente de calendario que lo consulta) y un tope por IP de respaldo; los servicios de calendario
        // consultan desde IPs compartidas, asi que no comparte el cupo de `web-public-parity` con SmartLinks y el acortador.
        RateLimiter::for('calendar-feed', fn (Request $request) => [
            Limit::perMinute((int) config('rate_limits.calendar_feed.per_token'))->by('calendar-feed:'.hash('sha256', (string) $request->route('token'))),
            Limit::perMinute((int) config('rate_limits.calendar_feed.per_ip'))->by('calendar-feed-ip:'.self::ipKey($request->ip())),
        ]);
        RateLimiter::for('mcp-oauth-register', fn (Request $request) => Limit::perHour((int) config('rate_limits.mcp_oauth_register'))
            ->by('mcp-oauth-register:'.self::ipKey($request->ip())));
        RateLimiter::for('two-factor-manage', fn (Request $request) => Limit::perMinute((int) config('rate_limits.two_factor_manage'))
            ->by('2fa-manage:'.($request->user()?->id ?? $request->ip())));

        // Los límites que ya tienen configuración de dominio conservan sus claves y variables de entorno.
        RateLimiter::for('giphy', fn (Request $request) => Limit::perMinute((int) config('social.giphy.per_user_per_minute', 20))
            ->by('giphy:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute((int) config('services.mcp.per_minute', 60))
            ->by('mcp:'.($request->user()?->currentAccessToken()?->id ?? $request->ip())));
        RateLimiter::for('csp-report', fn (Request $request) => [
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perMinute((int) config('security.csp_report_global_per_minute'))->by('global'),
        ]);
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
