<?php

declare(strict_types=1);

$repo = dirname(__DIR__, 5);
$relative = 'storage/framework/testing/t12-dataset-api/implementation/pest-'.bin2hex(random_bytes(16));
$root = $repo.'/'.$relative;
foreach (['app/private', 'framework/views', 'framework/cache/data', 'logs', 'cache'] as $directory) mkdir($root.'/'.$directory, 0700, true);
$values = [
    'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('d', 32)), 'APP_DEBUG' => 'false', 'APP_URL' => 'http://localhost',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'FILESYSTEM_DISK' => 'local', 'MEDIA_DISK' => 'local',
    'LARAVEL_STORAGE_PATH' => $root, 'APP_CONFIG_CACHE' => $relative.'/absent-config.php',
    'APP_PACKAGES_CACHE' => $relative.'/cache/packages.php', 'APP_SERVICES_CACHE' => $relative.'/cache/services.php',
    'APP_EVENTS_CACHE' => $relative.'/cache/events.php', 'APP_ROUTES_CACHE' => $relative.'/cache/routes.php',
    'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'BROADCAST_CONNECTION' => 'null',
];
foreach ($values as $key => $value) { putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value; }
require_once $repo.'/vendor/autoload.php';
$seed = require $repo.'/bootstrap/app.php';
$seed->useEnvironmentPath($root)->loadEnvironmentFrom('.absent-sentinel-env');
$seed->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config = $seed['config']->all();
$config['database']['default'] = 'sqlite';
$config['database']['connections']['sqlite']['database'] = ':memory:';
$config['database']['connections']['sqlite']['url'] = null;
$config['session']['driver'] = 'array';
$config['cache']['default'] = 'array';
$config['filesystems']['default'] = 'local';
$config['filesystems']['media'] = 'local';
$config['filesystems']['disks']['local']['root'] = $root.'/app/private';
$config['app']['key'] = $values['APP_KEY'];
$check = static function ($value) use (&$check): void {
    if (is_array($value)) { foreach ($value as $item) $check($item); }
    elseif (is_object($value) || is_resource($value)) throw new RuntimeException('Non-exportable cached config.');
};
$check($config);
file_put_contents($root.'/disposable-config.php', '<?php return '.var_export($config, true).';');
$cachedPath = $relative.'/disposable-config.php';
putenv('APP_CONFIG_CACHE='.$cachedPath); $_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = $cachedPath;
unset($seed, $config);
$proof = require $repo.'/bootstrap/app.php';
if (! $proof->configurationIsCached()) throw new RuntimeException('Config must be cached before Dotenv bootstrap.');
$proof->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($proof['config']->get('database.default') !== 'sqlite' || $proof['config']->get('database.connections.sqlite.database') !== ':memory:'
    || $proof['config']->get('app.key') !== $values['APP_KEY'] || $proof->storagePath() !== $root) throw new RuntimeException('Disposable cached config proof failed.');
file_put_contents($root.'/isolation-proof.json', json_encode(['cached' => true, 'database' => ':memory:', 'storage' => $root, 'environment_seed' => $root.'/.absent-sentinel-env']));
fwrite(STDOUT, 'disposable_cached_config='.$root."\n");
\Illuminate\Foundation\Bootstrap\HandleExceptions::flushState();
\Illuminate\Support\Facades\Facade::clearResolvedInstances();
