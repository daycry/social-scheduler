<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\QueueJobFailedAlert;
use App\Support\HealthCheck;
use App\Support\Retention;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Cache::put(HealthCheck::HEARTBEAT_KEY, now()->timestamp);
    config(['app.healthcheck_token' => 'secreto']);
});

function pendingJob(string $queue, int $ageSeconds, ?int $reservedAt = null): void
{
    DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reservedAt,
        'available_at' => now()->subSeconds($ageSeconds)->timestamp, 'created_at' => now()->subSeconds($ageSeconds)->timestamp]);
}

test('the oldest pending job per queue past its threshold degrades health and names the queue', function () {
    pendingJob('publish', 30);
    pendingJob('publish', 900);
    pendingJob('media', 900);          // tolera 1800 s
    pendingJob('metrics', 5000, now()->timestamp);   // reservado: lo está procesando un worker

    $check = app(HealthCheck::class)->queue();

    expect($check['ok'])->toBeTrue()->and($check['warn'])->toBeTrue()
        ->and($check['detail'])->toContain('atrasadas=publish:900s')->not->toContain('media:')->not->toContain('metrics:');
    expect(app(HealthCheck::class)->run()['status'])->toBe('degraded');
});

test('queues within their threshold do not warn', function () {
    pendingJob('publish', 60);

    expect(app(HealthCheck::class)->queue())->not->toHaveKey('warn');
});

test('stale snapshots warn only when accounts are connected', function () {
    expect(app(HealthCheck::class)->snapshots())->toMatchArray(['ok' => true, 'detail' => 'sin cuentas activas con antiguedad suficiente']);

    $user = User::factory()->create();
    $account = SocialAccount::factory()->for($user)->create();
    DB::table('social_accounts')->where('id', $account->id)->update(['created_at' => now()->subDays(10)]);
    $check = app(HealthCheck::class)->snapshots();
    expect($check['warn'])->toBeTrue()->and($check['detail'])->toContain('aun no hay snapshots');

    DB::table('account_snapshots')->insert(['user_id' => $user->id, 'brand_id' => $account->brand_id, 'social_account_id' => $account->id, 'network' => $account->network->value ?? $account->network,
        'date' => now()->subDays(3)->toDateString(), 'metrics' => '{}', 'collected_at' => now()->subHours(60), 'created_at' => now(), 'updated_at' => now()]);
    expect(app(HealthCheck::class)->snapshots()['detail'])->toContain('ultimo snapshot hace 60h');

    DB::table('account_snapshots')->update(['collected_at' => now()->subHours(3)]);
    expect(app(HealthCheck::class)->snapshots())->not->toHaveKey('warn');
});

test('native binaries missing produce a WARN naming each one', function () {
    config(['health.native' => true, 'studio.chromium_path' => '', 'studio.images.model_path' => '/no/existe.onnx']);
    Process::fake(['*' => Process::result(output: 'v1', exitCode: 0)]);
    Process::fake(fn ($process) => str_contains(is_array($process->command) ? implode(' ', $process->command) : $process->command, 'ffprobe')
        ? Process::result(errorOutput: 'not found', exitCode: 127) : Process::result(output: 'v1', exitCode: 0));

    $check = app(HealthCheck::class)->native();

    expect($check['ok'])->toBeTrue()->and($check['warn'])->toBeTrue()
        ->and($check['detail'])->toContain('ffprobe')->toContain('modelo')->not->toContain('faltan ffmpeg');

    $result = app(HealthCheck::class)->run();
    expect($result['ok'])->toBeTrue()->and($result['status'])->toBe('degraded')->and($result['checks'])->toHaveKey('native');
});

test('native check passes when every binary, Chromium and the model are present', function () {
    $file = tempnam(sys_get_temp_dir(), 'hc');
    config(['health.native' => true, 'studio.chromium_path' => $file, 'studio.images.model_path' => $file]);
    Process::fake(['*' => Process::result(output: 'v1', exitCode: 0)]);

    expect(app(HealthCheck::class)->native())->not->toHaveKey('warn');
    @unlink($file);
});

test('APP_DEBUG=true in production is reported', function () {
    config(['app.debug' => true]);
    expect(app(HealthCheck::class)->debug())->not->toHaveKey('warn');

    $this->app['env'] = 'production';
    $check = app(HealthCheck::class)->debug();
    expect($check['warn'])->toBeTrue()->and($check['detail'])->toContain('APP_DEBUG=true');
    $this->artisan('app:health')->expectsOutputToContain('APP_DEBUG=true')->assertSuccessful();
});

test('/health keeps its contract without strict and returns 503 with strict when there are warnings', function () {
    pendingJob('publish', 900);

    $this->get('/health', ['X-Health-Token' => 'secreto'])->assertOk()->assertJsonPath('status', 'degraded');
    $this->get('/health?strict=1', ['X-Health-Token' => 'secreto'])->assertStatus(503)->assertJsonPath('strict', true)->assertJsonPath('ok', false);

    DB::table('jobs')->delete();
    $this->get('/health?strict=1', ['X-Health-Token' => 'secreto'])->assertOk()->assertJsonPath('status', 'ok');
});

test('/health strict also fails with a recent failed job', function () {
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'publish', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

    $this->get('/health', ['X-Health-Token' => 'secreto'])->assertOk();
    $this->get('/health?strict=1', ['X-Health-Token' => 'secreto'])->assertStatus(503);
});

test('app:health --strict exits 1 on warnings but the default exit stays 0', function () {
    pendingJob('publish', 900);

    expect(Artisan::call('app:health'))->toBe(0)
        ->and(Artisan::call('app:health', ['--strict' => true]))->toBe(1);
});

test('a failed job emails the administrator, throttled, and never when no address is set', function () {
    Notification::fake();
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Demo');
    $job->shouldReceive('getQueue')->andReturn('publish');
    $event = new JobFailed('database', $job, new RuntimeException('secreto'));

    event($event);
    Notification::assertNothingSent();

    config(['health.alert_email' => 'admin@example.test', 'health.alert_throttle_minutes' => 60]);
    event($event);
    event($event);
    Notification::assertSentOnDemandTimes(QueueJobFailedAlert::class, 1);
    Notification::assertSentOnDemand(QueueJobFailedAlert::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'admin@example.test'
        && $n->job === 'App\\Jobs\\Demo' && $n->queue === 'publish' && $n->exception === RuntimeException::class);

    Cache::forget('health:failed-job-alert');
    event($event);
    Notification::assertSentOnDemandTimes(QueueJobFailedAlert::class, 2);
});

test('snapshots ignore inactive accounts and give a new account a grace period', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->for($user)->create(['status' => 'desconectada']);
    expect(app(HealthCheck::class)->snapshots())->not->toHaveKey('warn');

    $account->forceFill(['status' => 'activa'])->save();
    DB::table('social_accounts')->where('id', $account->id)->update(['created_at' => now()->subHours(2)]);
    expect(app(HealthCheck::class)->snapshots())->not->toHaveKey('warn');

    DB::table('social_accounts')->where('id', $account->id)->update(['created_at' => now()->subDays(5)]);
    expect(app(HealthCheck::class)->snapshots()['warn'])->toBeTrue();
});

test('native is cached for HTTP probes and Chromium is not guessed outside the CLI', function () {
    config(['health.native' => true, 'studio.chromium_path' => '', 'studio.images.model_path' => __FILE__]);
    Process::fake(['*' => Process::result(output: 'v1', exitCode: 0)]);
    Cache::forget('health:native');

    app(HealthCheck::class)->run(false, true);
    app(HealthCheck::class)->run(false, true);
    Process::assertRanTimes(fn () => true, 3);   // ffmpeg, ffprobe, python: una sola vez, no dos

    // Entorno de FPM (clear_env): sin HOME ni PLAYWRIGHT_BROWSERS_PATH no se adivina Chromium desde HTTP; por CLI sí avisa.
    $saved = [getenv('HOME'), getenv('USERPROFILE'), getenv('PLAYWRIGHT_BROWSERS_PATH')];
    putenv('HOME='.sys_get_temp_dir().'/sin-home');
    putenv('USERPROFILE='.sys_get_temp_dir().'/sin-home');
    putenv('PLAYWRIGHT_BROWSERS_PATH');
    try {
        expect(app(HealthCheck::class)->native(false))->not->toHaveKey('warn')
            ->and(app(HealthCheck::class)->native(true)['detail'])->toContain('faltan chromium');
    } finally {
        foreach (['HOME', 'USERPROFILE', 'PLAYWRIGHT_BROWSERS_PATH'] as $i => $name) {
            putenv($saved[$i] === false ? $name : "$name={$saved[$i]}");
        }
    }
});

test('the notifications prune deletes in batches and the read_at index exists', function () {
    foreach (range(1, 5) as $i) {
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => 'App\Models\User', 'notifiable_id' => 1, 'data' => '{}', 'read_at' => now()->subDays(200), 'created_at' => now()->subDays(200), 'updated_at' => now()]);
    }
    config(['retention.notifications_days' => 90]);

    expect(Retention::pruneNotifications(2))->toBe(5)
        ->and(DB::table('notifications')->count())->toBe(0)
        ->and(collect(Schema::getIndexes('notifications'))->flatten()->contains('notifications_read_at_prune'))->toBeTrue();
});

test('a broken cache never breaks the native check, over HTTP or CLI', function () {
    config(['health.native' => true, 'studio.chromium_path' => __FILE__, 'studio.images.model_path' => __FILE__]);
    Process::fake(['*' => Process::result(output: 'v1', exitCode: 0)]);
    Cache::partialMock()->shouldReceive('remember')->andThrow(new RuntimeException('cache caida'))
        ->shouldReceive('put')->with('health:native', Mockery::any(), Mockery::any())->andThrow(new RuntimeException('cache caida'));

    $http = app(HealthCheck::class)->run(true, true);
    $cli = app(HealthCheck::class)->run(true, false);

    expect($http['checks']['native']['detail'])->toStartWith('ok:')->and($cli['checks']['native']['detail'])->toStartWith('ok:');
});
