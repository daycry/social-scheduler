<?php

use App\Events\OutboundDomainEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Brand;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Media\UrlGuard;
use App\Services\Webhooks\WebhookSubscriptions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

chdir('C:/laragon/www/metricool/.cache/t80-physical');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$storage = str_replace('\\', '/', storage_path());
if (config('database.default') !== 'sqlite' || ! str_starts_with($storage, 'C:/laragon/www/metricool/.cache/t80-physical/storage/framework/testing/t80-sqlite-race-')
    || str_replace('\\', '/', config('database.connections.sqlite.database')) !== $storage.'/db.sqlite') {
    throw new RuntimeException('Owned disposable SQLite runtime required.');
}
Http::preventStrayRequests();
Queue::fake();
$app->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
DB::statement('PRAGMA busy_timeout = 1500');
$mode = $argv[1];
if ($mode === 'migrate') {
    Artisan::call('migrate', ['--force' => true]);
    echo json_encode(['migrated' => true]);
} elseif ($mode === 'seed') {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    app(WebhookSubscriptions::class)->create($user, $brand, [
        'name' => 'SQLite race probe', 'url' => 'https://hooks.example.test/receiver',
        'events' => ['post.published'], 'secret' => str_repeat('a', 64),
    ]);
    Event::dispatch(new OutboundDomainEvent($user->id, $brand->id, 'post.published', ['target_id' => 123]));
    echo json_encode(['delivery' => WebhookDelivery::ownedBy($user->id)->sole()->id]);
} elseif ($mode === 'worker') {
    $receipt = $argv[3];
    if (! str_starts_with(str_replace('\\', '/', $receipt), $storage.'/')) {
        throw new RuntimeException('Receipt outside own storage.');
    }
    Http::fake(function () use ($receipt) {
        file_put_contents($receipt, json_encode(['pid' => getmypid()])."\n", FILE_APPEND | LOCK_EX);
        // Solo el receptor simulado: abre la ventana de carrera entre los procesos del probe.
        usleep(200000);

        return Http::response('', 204);
    });
    if ($argv[4] !== '-') {
        file_put_contents($argv[4], 'ready');
        $deadline = microtime(true) + 15;
        while (! file_exists($argv[5])) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Barrier timed out.');
            }
            usleep(10000);
        }
    }
    try {
        app()->call([new DeliverWebhook((int) $argv[2]), 'handle']);
        echo json_encode(['result' => 'handled']);
    } catch (QueryException $error) {
        if (! str_contains(strtolower($error->getMessage()), 'database is locked')) {
            throw $error;
        }
        echo json_encode(['result' => 'retryable_sqlite_lock']);
    }
} elseif ($mode === 'verify') {
    $delivery = WebhookDelivery::withoutUserScope()->findOrFail((int) $argv[2]);
    echo json_encode(['status' => $delivery->status, 'attempts' => $delivery->attempts]);
}
