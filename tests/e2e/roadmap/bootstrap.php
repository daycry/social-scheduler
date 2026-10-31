<?php

declare(strict_types=1);
use App\Analytics\Metric;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeInsights;

/** Bootstrap used only by the disposable T-43 CLI and PHP development router. */
function t43Root(bool $initialize = false): string
{
    $project = dirname(__DIR__, 3);
    $prefix = str_replace('\\', '/', $project.'/storage/framework/testing/t43/');
    $root = str_replace('\\', '/', (string) getenv('T43_RUN_ROOT'));
    $url = parse_url((string) getenv('APP_URL'));
    if (getenv('APP_ENV') !== 'e2e' || ! str_starts_with($root, $prefix)
        || ! preg_match('#^run-[A-Za-z0-9-]+$#', substr($root, strlen($prefix)))
        || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_URL') !== ''
        || str_replace('\\', '/', (string) getenv('DB_DATABASE')) !== $root.'/db.sqlite'
        || str_replace('\\', '/', (string) getenv('APP_CONFIG_CACHE')) !== $root.'/config.php'
        || ! in_array($url['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('T43 refuses any environment outside its disposable local SQLite run.');
    }
    if ($initialize) {
        foreach (['storage', 'storage/framework', 'storage/framework/testing', 'storage/framework/testing/t43', 'storage/framework/testing/t43/'.basename($root)] as $parent) {
            $existing = realpath($project.'/'.$parent);
            if (is_link($project.'/'.$parent) || ($existing !== false && strcasecmp(str_replace('\\', '/', $existing), str_replace('\\', '/', $project.'/'.$parent)) !== 0)) {
                throw new RuntimeException('T43 refuses symlinked storage parents.');
            }
        }
        foreach ([$root, $root.'/views', $root.'/cache', $root.'/media', $root.'/logs', $root.'/sessions', $root.'/fonts', $root.'/font-cache', $root.'/pdf-temp'] as $directory) {
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Cannot create disposable T43 directory.');
            }
        }
        if (is_link($root) || is_link($root.'/db.sqlite')) {
            throw new RuntimeException('T43 refuses symlinks.');
        }
        touch($root.'/db.sqlite');
        file_put_contents($root.'/.t43-run', 'disposable T43 database');
    }
    if (! is_file($root.'/.t43-run') || realpath($root) === false || is_link($root)
        || realpath($root.'/db.sqlite') !== realpath($root).DIRECTORY_SEPARATOR.'db.sqlite') {
        throw new RuntimeException('T43 requires its own initialized database marker.');
    }

    return $root;
}

function t43Bootstrap(): Application
{
    $root = t43Root();
    require_once dirname(__DIR__, 3).'/vendor/autoload.php';
    require_once __DIR__.'/fakes.php';
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    // Never load the real .env: this process receives only the disposable runner's environment.
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
        ]);
    });
    $app->make(Kernel::class)->bootstrap();
    if (config('database.default') !== 'sqlite' || realpath(config('database.connections.sqlite.database')) !== realpath($root.'/db.sqlite')) {
        throw new RuntimeException('T43 resolved an unexpected database.');
    }
    Http::preventStrayRequests();
    $registry = new ConnectorRegistry;
    foreach (['meta' => [Network::Instagram, Network::Facebook], 'google' => [Network::YouTube], 'tiktok' => [Network::TikTok]] as $provider => $networks) {
        $registry->register(new T43Connector($provider, $networks));
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
