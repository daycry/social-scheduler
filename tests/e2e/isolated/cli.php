<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('General E2E commands are CLI-only.');
}
$action = $argv[1] ?? '';
if ($action === 'init') {
    generalRoot(true);
}
$app = generalBootstrap();
$kernel = $app->make(Kernel::class);
if ($action === 'init') {
    $exit = $kernel->call('migrate', ['--force' => true]);
    echo $kernel->output();
    exit($exit);
}
if ($action !== 'tinker' || ! isset($argv[2])) {
    throw new RuntimeException('Unknown general E2E command.');
}
$exit = $kernel->call('tinker', ['--execute' => $argv[2]]);
echo $kernel->output();
exit($exit);
