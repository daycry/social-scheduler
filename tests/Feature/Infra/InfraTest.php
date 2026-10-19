<?php

use App\Jobs\QueueProbeJob;
use App\Support\HealthCheck;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('queues publish, poll, metrics and reports are configured', function () {
    expect(config('queue.names'))->toBe(['publish', 'poll', 'metrics', 'reports']);
    expect(config('queue.connections.database.driver'))->toBe('database');
});

test('r2 disk is an s3 driver configured for r2', function () {
    $disk = config('filesystems.disks.r2');

    expect($disk['driver'])->toBe('s3')
        ->and($disk['use_path_style_endpoint'])->toBeTrue()
        ->and($disk['region'])->toBe('auto');
    expect(array_keys($disk))->toContain('bucket', 'endpoint', 'url');
});

test('media disk falls back to local in test and r2 is operative when faked', function () {
    expect(config('filesystems.media'))->toBe('local');

    config(['filesystems.media' => 'r2']);
    Storage::fake('r2');

    Storage::disk(config('filesystems.media'))->put('media/a.txt', 'hola');
    Storage::disk('r2')->assertExists('media/a.txt');
});

test('probe jobs are dispatched on each queue', function () {
    Queue::fake();

    Artisan::call('app:queue-probe');

    foreach (['publish', 'poll', 'metrics', 'reports'] as $queue) {
        Queue::assertPushedOn($queue, QueueProbeJob::class);
    }
});

test('database queue persists a probe job per queue and a worker processes it', function () {
    config(['queue.default' => 'database']);

    Artisan::call('app:queue-probe');
    expect(DB::table('jobs')->pluck('queue')->sort()->values()->all())
        ->toBe(['metrics', 'poll', 'publish', 'reports']);

    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => 'publish,poll,metrics,reports',
        '--stop-when-empty' => true,
    ]);

    expect(DB::table('jobs')->count())->toBe(0);
});

test('app:health succeeds when everything is fine', function () {
    Cache::put(HealthCheck::HEARTBEAT_KEY, now()->timestamp);

    $exit = Artisan::call('app:health', ['--json' => true]);
    $result = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)->and($result['ok'])->toBeTrue()
        ->and(array_keys($result['checks']))->toBe(['database', 'queue', 'storage', 'scheduler']);
});

test('app:health fails without scheduler heartbeat unless skipped', function () {
    Cache::forget(HealthCheck::HEARTBEAT_KEY);

    expect(Artisan::call('app:health'))->toBe(1);
    expect(Artisan::call('app:health', ['--skip-scheduler' => true]))->toBe(0);
});

test('app:health reports failed jobs', function () {
    Cache::put(HealthCheck::HEARTBEAT_KEY, now()->timestamp);
    DB::table('failed_jobs')->insert([
        'uuid' => 'x', 'connection' => 'database', 'queue' => 'publish',
        'payload' => '{}', 'exception' => 'boom', 'failed_at' => now(),
    ]);

    expect(Artisan::call('app:health'))->toBe(1);
});

test('stale heartbeat is detected', function () {
    Cache::put(HealthCheck::HEARTBEAT_KEY, now()->subMinutes(10)->timestamp);

    expect(app(HealthCheck::class)->scheduler()['ok'])->toBeFalse();
});

test('skip locked support is detected by version', function (string $driver, string $version, bool $expected) {
    expect(app(HealthCheck::class)->supportsSkipLocked($driver, $version))->toBe($expected);
})->with([
    ['mysql', '8.4.3', true],
    ['mysql', '5.7.44', false],
    ['mysql', '10.11.6-MariaDB', true],
    ['mysql', '10.5.22-MariaDB', false],
    ['sqlite', '3.45.0', false],
]);

test('health endpoint is closed without token and works with it', function () {
    $this->get('/health')->assertNotFound();

    config(['app.healthcheck_token' => 'secreto']);
    $this->get('/health', ['X-Health-Token' => 'mal'])->assertNotFound();

    Cache::put(HealthCheck::HEARTBEAT_KEY, now()->timestamp);
    $this->get('/health', ['X-Health-Token' => 'secreto'])
        ->assertOk()
        ->assertJsonPath('ok', true);

    Cache::forget(HealthCheck::HEARTBEAT_KEY);
    $this->get('/health', ['X-Health-Token' => 'secreto'])->assertStatus(503);
});

test('scheduler registers the heartbeat every minute', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('health-heartbeat');
});

test('systemd unit is a template with recycling and restart policy', function () {
    $unit = file_get_contents(base_path('deploy/systemd/scheduler-worker@.service'));

    expect($unit)->toContain('--queue=%i')
        ->toContain('--max-time=3600')
        ->toContain('--max-jobs=500')
        ->toContain('Restart=always');
});
