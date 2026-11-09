<?php

namespace Tests\Support;

use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/** A disposable application for presentation persistence tests, without environment-file loading. */
final class StudioPresentationTestApplication
{
    private static array $environment = [];

    public static function create(): Application
    {
        $repo = dirname(__DIR__, 2);
        $relative = 'storage/framework/testing/studio-presentation/run-'.bin2hex(random_bytes(16));
        $isolated = $repo.'/'.$relative;
        foreach (['app/private', 'framework/views', 'framework/cache/data', 'logs', 'cache'] as $directory) {
            if (! mkdir($isolated.'/'.$directory, 0700, true) && ! is_dir($isolated.'/'.$directory)) {
                throw new RuntimeException('Cannot create isolated presentation storage.');
            }
        }
        $values = [
            'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('p', 32)), 'APP_DEBUG' => 'false', 'APP_URL' => 'http://localhost',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'FILESYSTEM_DISK' => 'local', 'MEDIA_DISK' => 'local',
            'LARAVEL_STORAGE_PATH' => $isolated, 'APP_CONFIG_CACHE' => $relative.'/absent-config.php',
            'APP_PACKAGES_CACHE' => $relative.'/cache/packages.php', 'APP_SERVICES_CACHE' => $relative.'/cache/services.php',
            'APP_EVENTS_CACHE' => $relative.'/cache/events.php', 'APP_ROUTES_CACHE' => $relative.'/cache/routes.php',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'BROADCAST_CONNECTION' => 'null',
            'SOCIAL_BACKFILL_ON_CONNECT' => 'false', 'MEDIA_IMPORT_MIN_PASS_SECONDS' => '0', 'SOCIAL_BEST_TIMES_CACHE_MINUTES' => '0',
        ];
        foreach ($values as $key => $value) {
            self::setEnvironment($key, $value);
        }
        $dotenvAlreadyLoaded = class_exists('Dotenv\\Dotenv', false);
        // Only configuration is loaded in the seed: no Kernel, providers or environment loader.
        $seed = require $repo.'/bootstrap/app.php';
        $seed->useEnvironmentPath($isolated)->loadEnvironmentFrom('.absent-sentinel-env');
        $seed->bootstrapWith([LoadConfiguration::class]);
        (new class extends RegisterProviders
        {
            public function mergeConfiguration(ApplicationContract $app): void
            {
                $this->mergeAdditionalProviders($app);
            }
        })->mergeConfiguration($seed);
        $config = $seed['config']->all();
        $config['database']['default'] = 'sqlite';
        $config['database']['connections']['sqlite']['database'] = ':memory:';
        $config['database']['connections']['sqlite']['url'] = null;
        $config['session']['driver'] = 'array';
        $config['cache']['default'] = 'array';
        $config['filesystems']['default'] = $config['filesystems']['media'] = 'local';
        $config['filesystems']['disks']['local']['root'] = $isolated.'/app/private';
        $config['app']['key'] = $values['APP_KEY'];
        if (file_put_contents($isolated.'/disposable-config.php', '<?php return '.var_export($config, true).';') === false) {
            throw new RuntimeException('Cannot write isolated presentation configuration.');
        }
        self::setEnvironment('APP_CONFIG_CACHE', $relative.'/disposable-config.php');
        unset($seed, $config);
        $app = require $repo.'/bootstrap/app.php';
        if (! $app->configurationIsCached()) {
            throw new RuntimeException('Presentation configuration must be cached before Kernel bootstrap.');
        }
        $app->make(Kernel::class)->bootstrap();
        if (class_exists('Dotenv\\Dotenv', false) !== $dotenvAlreadyLoaded
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app->storagePath() !== $isolated
            || ! in_array(AppServiceProvider::class, $app['config']->get('app.providers', []), true)
            || ! is_callable(RateLimiter::limiter('mcp'))) {
            throw new RuntimeException('Isolated presentation application proof failed.');
        }
        file_put_contents($isolated.'/isolation-proof.json', json_encode([
            'cached' => true, 'dotenv_loaded_before' => $dotenvAlreadyLoaded, 'dotenv_loaded_after' => class_exists('Dotenv\\Dotenv', false),
            'database' => ':memory:', 'storage' => $isolated, 'seed_bootstrappers' => [LoadConfiguration::class],
            'provider_merge_without_registration' => true, 'mcp_limiter_registered' => true,
        ], JSON_PRETTY_PRINT));

        return $app;
    }

    private static function setEnvironment(string $key, string $value): void
    {
        if (! array_key_exists($key, self::$environment)) {
            self::$environment[$key] = [getenv($key), array_key_exists($key, $_ENV), $_ENV[$key] ?? null, array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null];
        }
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    /** Restore process state after the test application and its migrations have been torn down. */
    public static function restoreEnvironment(): void
    {
        foreach (self::$environment as $key => [$process, $hasEnv, $env, $hasServer, $server]) {
            putenv($process === false ? $key : $key.'='.$process);
            if ($hasEnv) {
                $_ENV[$key] = $env;
            } else {
                unset($_ENV[$key]);
            }
            if ($hasServer) {
                $_SERVER[$key] = $server;
            } else {
                unset($_SERVER[$key]);
            }
        }
        self::$environment = [];
    }
}
