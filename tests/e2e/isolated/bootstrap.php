<?php

declare(strict_types=1);

use App\Analytics\Metric;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeInsights;

// Reuse the independently verified disposable bootstrap without changing T-43's runner.
require_once dirname(__DIR__).'/roadmap/bootstrap.php';

function generalRoot(bool $initialize = false): string
{
    $root = str_replace('\\', '/', (string) getenv('E2E_GENERAL_RUN_ROOT'));
    if ($root !== str_replace('\\', '/', (string) getenv('T43_RUN_ROOT'))
        || ! preg_match('#/run-general-[a-f0-9-]{36}$#', $root)
        || getenv('APP_URL') !== 'http://localhost:8080'
        || str_replace('\\', '/', (string) getenv('PSYSH_CONFIG')) !== $root.'/psysh.php') {
        throw new RuntimeException('General E2E requires its own disposable local environment.');
    }
    $root = t43Root($initialize);
    if ($initialize) {
        foreach (['auth', 'framework/cache', 'psysh', 'framework/views', 'framework/sessions'] as $directory) {
            if (! is_dir($root.'/'.$directory)) {
                mkdir($root.'/'.$directory, 0700, true);
            }
        }
        $psysh = ['configDir' => $root.'/psysh', 'dataDir' => $root.'/psysh', 'historyFile' => $root.'/psysh/history', 'useReadline' => false];
        file_put_contents($root.'/psysh.php', '<?php return '.var_export($psysh, true).';');
        file_put_contents($root.'/.general-run', 'disposable general E2E');
    }
    if (! is_file($root.'/.general-run')) {
        throw new RuntimeException('Missing disposable general E2E marker.');
    }

    return $root;
}

function generalBootstrap(): Application
{
    $root = generalRoot();
    require_once dirname(__DIR__, 3).'/vendor/autoload.php';
    require_once dirname(__DIR__).'/roadmap/fakes.php';
    require_once __DIR__.'/fakes.php';
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    // Never load the real .env: this process receives only the disposable runner's environment.
    $app->addAbsoluteCachePathPrefix(substr($root, 0, 2));
    $app->useEnvironmentPath($root);
    $app->useStoragePath($root);
    // Providers register rate limiters during boot and retain the cache repository they resolve.
    // Set every disposable path after configuration loads, BEFORE any provider can resolve it.
    $app->afterBootstrapping(LoadConfiguration::class, function (Application $app) use ($root): void {
        $app->make('config')->set([
            'cache.default' => 'file', 'cache.limiter' => 'file',
            'cache.stores.file.path' => $root.'/cache', 'cache.stores.file.lock_path' => $root.'/cache',
            'session.driver' => 'database', 'session.connection' => 'sqlite', 'session.files' => $root.'/sessions', 'session.store' => 'file',
            'queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite',
            'filesystems.default' => 'local', 'filesystems.disks.local.root' => $root.'/media', 'filesystems.media' => 'local',
            'logging.channels.single.path' => $root.'/logs/laravel.log',
            'mail.default' => 'array', 'reports.branding.logo' => null,
            'dompdf.options.font_cache' => $root.'/font-cache', 'dompdf.options.temp_dir' => $root.'/pdf-temp',
            'social.backfill_on_connect' => false,
            // Off by default (TikTok then offers Video only). A test that needs photos creates `<run root>/tiktok-domain-on`
            // for its duration; the domain is fictitious and never contacted.
            ...(is_file($root.'/tiktok-domain-on') ? ['social.tiktok.verified_domain' => 'https://media.example.test'] : []),
            // Read-only, pinned weights; all image input/output stays in this run's storage.
            'studio.images.model_path' => dirname(__DIR__, 3).'/storage/app/private/studio-models/birefnet-general-lite.onnx',
        ]);
    });
    $app->make(Kernel::class)->bootstrap();
    if (config('database.default') !== 'sqlite' || realpath(config('database.connections.sqlite.database')) !== realpath($root.'/db.sqlite')) {
        throw new RuntimeException('T43 resolved an unexpected database.');
    }
    Http::preventStrayRequests();
    $registry = new ConnectorRegistry;
    foreach (['meta' => [Network::Instagram, Network::Facebook], 'google' => [Network::YouTube], 'tiktok' => [Network::TikTok], 'bluesky' => [Network::Bluesky], 'threads' => [Network::Threads], 'linkedin' => [Network::LinkedIn], 'pinterest' => [Network::Pinterest]] as $provider => $networks) {
        $registry->register($provider === 'bluesky' ? $app->make(GeneralBlueskyConnector::class) : new T43Connector($provider, $networks));
        foreach ($networks as $network) {
            $registry->registerPublisher($network, new T43Publisher($root));
            $insights = new FakeInsights;
            $insights->account[Metric::VIEWS] = null;
            $insights->post[Metric::VIEWS] = null;
            $registry->registerInsights($network, $insights);
        }
    }
    $app->instance(ConnectorRegistry::class, $registry);
    Event::listen(NotificationSent::class, function ($event) use ($root): void {
        if ($event->notification instanceof ResetPassword) {
            file_put_contents($root.'/mailbox.jsonl', json_encode(['email' => $event->notifiable->email, 'token' => $event->notification->token])."\n", FILE_APPEND | LOCK_EX);
        }
    });

    return $app;
}

/** Actual repositories are inspected because providers can retain a store resolved during boot. */
function generalResourcePaths(): array
{
    $cache = app('cache')->store('file')->getStore();
    $limiter = app(RateLimiter::class);
    $limiterCache = (new ReflectionProperty($limiter, 'cache'))->getValue($limiter);
    $lock = app('cache')->store('file')->lock('general-isolation-proof', 10);
    $lockStore = (new ReflectionProperty($lock, 'store'))->getValue($lock);

    return [
        'database' => config('database.connections.sqlite.database'), 'environment' => app()->environmentPath(),
        'storage' => storage_path(), 'cache' => $cache->getDirectory(), 'limiter' => $limiterCache->getStore()->getDirectory(),
        'locks' => $lockStore->getDirectory(), 'config' => app()->getCachedConfigPath(),
        'packages' => app()->getCachedPackagesPath(), 'services' => app()->getCachedServicesPath(),
    ];
}
