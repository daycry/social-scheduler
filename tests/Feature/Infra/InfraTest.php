<?php

use App\Jobs\QueueProbeJob;
use App\Support\HealthCheck;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

test('r2 disk has bounded http timeouts', function () {
    $disk = config('filesystems.disks.r2');

    // Conexion acotada; el total es amplio para transferir videos/informes grandes.
    expect($disk['http']['connect_timeout'])->toBe(3.0)
        ->and($disk['http']['timeout'])->toBe(600.0)
        ->and(config('filesystems.health_timeout'))->toBe(5.0);
    // Verificado a mano: contra una IP no enrutable el put falla a los ~3 s (connect_timeout).
});

test('storage probe uses a short total timeout without touching the real r2 disk', function () {
    config(['filesystems.media' => 'r2']);
    $captured = null;
    Storage::shouldReceive('build')->once()->andReturnUsing(function (array $config) use (&$captured) {
        $captured = $config;
        $fake = Mockery::mock(Filesystem::class);
        $fake->shouldReceive('put')->andReturnTrue();
        $fake->shouldReceive('get')->andReturn('ok');
        $fake->shouldReceive('delete')->andReturnTrue();

        return $fake;
    });

    $result = app(HealthCheck::class)->storage();

    expect($result['ok'])->toBeTrue()
        ->and($captured['http']['timeout'])->toBe(5.0)
        ->and($captured['http']['connect_timeout'])->toBe(3.0)
        ->and(config('filesystems.disks.r2.http.timeout'))->toBe(600.0);
});

test('storage check fails fast and generic when the disk throws', function () {
    Log::spy();
    config(['filesystems.media' => 'r2']);
    $fake = Mockery::mock(Filesystem::class);
    $fake->shouldReceive('put')->andThrow(new RuntimeException('cURL error 28: timeout https://secret-host'));
    Storage::shouldReceive('disk')->with('r2')->andReturn($fake);

    $check = app(HealthCheck::class)->storage();

    expect($check['ok'])->toBeFalse()->and($check['detail'])->not->toContain('secret-host');
    Log::shouldHaveReceived('error')->with('health.check_failed', Mockery::type('array'))->once();
});

test('local disk write failure is reported explicitly', function () {
    $fake = Mockery::mock(Filesystem::class);
    $fake->shouldReceive('put')->andReturn(false);
    Storage::shouldReceive('disk')->andReturn($fake);

    expect(app(HealthCheck::class)->storage())->toMatchArray(['ok' => false]);
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

test('a recent failed job degrades health but does not make it fail', function () {
    Cache::put(HealthCheck::HEARTBEAT_KEY, now()->timestamp);
    DB::table('failed_jobs')->insert([
        'uuid' => 'x', 'connection' => 'database', 'queue' => 'publish',
        'payload' => '{}', 'exception' => 'boom', 'failed_at' => now(),
    ]);

    expect(Artisan::call('app:health', ['--json' => true]))->toBe(0);
    $result = json_decode(Artisan::output(), true);

    expect($result['ok'])->toBeTrue()->and($result['status'])->toBe('degraded')
        ->and($result['checks']['queue']['detail'])->toContain('AVISO', 'fallidos_24h=1');
});

test('an old failed job neither degrades nor breaks health', function () {
    Cache::put(HealthCheck::HEARTBEAT_KEY, now()->timestamp);
    DB::table('failed_jobs')->insert([
        'uuid' => 'old', 'connection' => 'database', 'queue' => 'publish',
        'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()->subDays(10),
    ]);

    $result = app(HealthCheck::class)->run();

    expect($result['ok'])->toBeTrue()->and($result['status'])->toBe('ok')
        ->and($result['checks']['queue']['detail'])->toContain('fallidos_total=1');
});

test('a failing database is reported as down with a generic message', function () {
    Log::spy();
    DB::shouldReceive('connection')->andThrow(new RuntimeException('SQLSTATE[HY000] [2002] host=10.0.0.5 password=hunter2'));

    $check = app(HealthCheck::class)->database();

    expect($check['ok'])->toBeFalse()->and($check['detail'])->not->toContain('hunter2');
});

test('app:queue-probe validates --wait and the queue names', function () {
    expect(Artisan::call('app:queue-probe', ['--wait' => 'abc']))->not->toBe(0);
    expect(Artisan::call('app:queue-probe', ['--wait' => '-3']))->not->toBe(0);

    config(['queue.names' => []]);
    expect(Artisan::call('app:queue-probe'))->toBe(1);
});

test('empty QUEUE_NAMES falls back to the default queues', function () {
    $_ENV['QUEUE_NAMES'] = '';
    putenv('QUEUE_NAMES=');
    $names = (require base_path('config/queue.php'))['names'];
    unset($_ENV['QUEUE_NAMES']);
    putenv('QUEUE_NAMES');

    expect($names)->toBe(['publish', 'poll', 'metrics', 'reports']);
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

test('health endpoint is outside the web group (no session) and rate limited', function () {
    $route = app('router')->getRoutes()->getByName('health');

    expect($route->gatherMiddleware())->not->toContain('web')
        ->and($route->gatherMiddleware())->toContain('throttle:30,1');

    config(['app.healthcheck_token' => 'secreto']);
    Cache::put(HealthCheck::HEARTBEAT_KEY, now()->timestamp);
    $this->get('/health', ['X-Health-Token' => 'secreto'])->assertOk()->assertJsonPath('status', 'ok');
    expect(DB::table('sessions')->count())->toBe(0);

    for ($i = 0; $i < 40; $i++) {
        $last = $this->get('/health', ['X-Health-Token' => 'mal']);
    }
    expect($last->getStatusCode())->toBe(429);
});

test('scheduler registers the heartbeat every minute', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('health-heartbeat');
});

test('deploy script goes down before touching code, builds assets and gates on the manifest', function () {
    $sh = file_get_contents(base_path('deploy/hestia/deploy.sh'));

    expect(strpos($sh, '"$PHP_BIN artisan down'))->toBeLessThan(strpos($sh, '"git pull'))
        ->and(strpos($sh, '"git pull'))->toBeLessThan(strpos($sh, '$COMPOSER_RUN install'));
    expect($sh)->toContain('$NPM ci', '$NPM run build', 'public/build/manifest.json', 'trap on_failure ERR', 'PHP_BIN');
    expect(strpos($sh, 'test -f $MANIFEST'))->toBeLessThan(strpos($sh, 'artisan migrate'));
});

test('systemd unit is a template with recycling and restart policy', function () {
    $unit = file_get_contents(base_path('deploy/systemd/scheduler-worker@.service'));

    expect($unit)->toContain('--queue=%i')
        ->toContain('--max-time=3600')
        ->toContain('--max-jobs=500')
        ->toContain('Restart=always')
        ->toContain('NoNewPrivileges=true', 'PrivateTmp=true', 'ProtectSystem=strict', 'ReadWritePaths=')
        ->toContain('ProtectHome=read-only', 'Environment=PHP_BIN=', '${PHP_BIN} artisan queue:work');
});
