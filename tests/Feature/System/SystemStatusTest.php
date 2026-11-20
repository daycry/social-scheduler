<?php

use App\Connectors\Google\YouTubeQuota;
use App\Connectors\Network;
use App\Jobs\QueueProbeJob;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SystemStatus;
use App\Support\HealthCheck;
use App\Support\SystemAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->freezeTime();
    config(['health.admin_emails' => ['operator@example.test'], 'health.native' => false,
        'social.linkedin.enabled' => false, 'backup.health' => false, 'filesystems.media' => 'local']);
    Storage::fake('local');
    Http::preventStrayRequests();
    Queue::fake();
    Cache::put(HealthCheck::HEARTBEAT_KEY, now()->timestamp);
});

test('SystemStatus: a verified configured administrator sees the canonical app health report with observation time', function () {
    $admin = User::factory()->create(['email' => 'operator@example.test']);
    $expected = app(HealthCheck::class)->run();
    $this->actingAs($admin)->get('/system')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('System/Index')->where('report.health', $expected)
        ->where('report.observed_at', now()->toIso8601String()));
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('SystemStatus: ordinary users cannot run or read system diagnostics', function () {
    $this->mock(HealthCheck::class)->shouldNotReceive('run');
    $user = User::factory()->create();
    $this->actingAs($user)->get('/system')->assertForbidden();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('SystemStatus: an empty administrator configuration is closed rather than promoting the first user', function () {
    config(['health.admin_emails' => []]);
    $this->mock(HealthCheck::class)->shouldNotReceive('run');
    $user = User::factory()->create(['email' => 'operator@example.test']);
    $this->actingAs($user)->get('/system')->assertForbidden();
});

test('SystemStatus: global token dates and local quota have explicit provenance without exposing credentials', function () {
    $admin = User::factory()->create(['email' => 'operator@example.test']);
    $other = User::factory()->create();
    SocialAccount::factory()->for($other)->create([
        'network' => Network::Instagram, 'access_token' => 'SYSTEM-ACCESS-PRIVATE', 'refresh_token' => 'SYSTEM-REFRESH-PRIVATE',
        'token_expires_at' => now()->addDay(), 'refresh_expires_at' => null, 'data_access_expires_at' => now()->addDays(7),
    ]);
    SocialAccount::factory()->for($other)->create([
        'network' => Network::Instagram, 'token_expires_at' => null,
        'refresh_expires_at' => null, 'data_access_expires_at' => null,
    ]);
    app(YouTubeQuota::class)->charge(1200);
    $reset = app(YouTubeQuota::class)->resetsAt()->toIso8601String();
    $response = $this->actingAs($admin)->get('/system');
    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('System/Index')
        ->where('report.tokens.instagram.account_count', 2)
        ->where('report.tokens.instagram.expires_at', now()->addDay()->toIso8601String())
        ->where('report.tokens.instagram.unknown_expiry_count', 1)
        ->where('report.quotas.youtube.source', 'local_counter')
        ->where('report.quotas.youtube.used', 1200)
        ->where('report.quotas.youtube.resets_at', $reset)
        ->where('report.quotas.instagram.used', null)
        ->where('report.quotas.instagram.observed_at', null));
    expect($response->getContent())->not->toContain('SYSTEM-ACCESS-PRIVATE')->not->toContain('SYSTEM-REFRESH-PRIVATE');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('SystemStatus: queue observations distinguish an actual processed probe from a queue without evidence', function () {
    config(['queue.names' => ['publish'], 'media.import_queue' => 'media', 'queue.studio_render' => 'studio-render']);
    $admin = User::factory()->create(['email' => 'operator@example.test']);
    $probe = (new QueueProbeJob('system-status-fixture'))->onQueue('media');
    $probe->handle();
    $this->actingAs($admin)->get('/system')->assertOk()->assertInertia(fn (Assert $page) => $page->component('System/Index')
        ->has('report.queues', 3)
        ->where('report.queues.0.name', 'publish')->where('report.queues.0.last_processed_at', null)
        ->where('report.queues.1.name', 'media')->where('report.queues.1.last_processed_at', now()->toIso8601String())
        ->where('report.queues.2.name', 'studio-render')->where('report.queues.2.last_processed_at', null));
    expect(Cache::has(QueueProbeJob::cacheKey('system-status-fixture')))->toBeTrue();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('SystemStatus: an unreadable local quota remains unknown without hiding the canonical health result', function () {
    $admin = User::factory()->create(['email' => 'operator@example.test']);
    $expected = ['ok' => false, 'status' => 'down', 'checks' => ['scheduler' => ['ok' => false, 'detail' => 'sin latido']]];
    $this->mock(HealthCheck::class)->shouldReceive('run')->once()->with(false, true)->andReturn($expected);
    $this->mock(YouTubeQuota::class)->shouldReceive('used')->once()->andThrow(new RuntimeException('PRIVATE-CACHE-CREDENTIAL'));
    $report = app(SystemStatus::class)->snapshot($admin);
    expect($report['health'])->toBe($expected)
        ->and($report['quotas']['youtube']['used'])->toBeNull()
        ->and($report['quotas']['youtube']['observed_at'])->toBeNull()
        ->and($report['quotas']['youtube']['unavailable_reason'])->toBe('read_failed')
        ->and(json_encode($report))->not->toContain('PRIVATE-CACHE-CREDENTIAL');
});

test('SystemStatus: an unreadable token observation is not presented as zero connected accounts', function () {
    $admin = User::factory()->create(['email' => 'operator@example.test']);
    $expected = ['ok' => false, 'status' => 'down', 'checks' => ['database' => ['ok' => false, 'detail' => 'error de conexión']]];
    $this->mock(HealthCheck::class)->shouldReceive('run')->once()->with(false, true)->andReturn($expected);
    DB::partialMock()->shouldReceive('table')->once()->with('social_accounts')->andThrow(new RuntimeException('PRIVATE-DATABASE-HOST'));
    $report = app(SystemStatus::class)->snapshot($admin);
    expect($report['health'])->toBe($expected)->and($report['tokens'])->toBe([])
        ->and($report['tokens_available'])->toBeFalse()
        ->and(json_encode($report))->not->toContain('PRIVATE-DATABASE-HOST');
});

test('SystemStatus: a failed probe cache read is distinct from a probe that never ran', function () {
    config(['queue.names' => ['publish']]);
    $admin = User::factory()->create(['email' => 'operator@example.test']);
    $this->mock(HealthCheck::class)->shouldReceive('run')->once()->with(false, true)
        ->andReturn(['ok' => false, 'status' => 'down', 'checks' => []]);
    $quota = $this->mock(YouTubeQuota::class);
    $quota->shouldReceive('used')->once()->andReturn(0);
    $quota->shouldReceive('limit')->once()->andReturn(10000);
    $quota->shouldReceive('resetsAt')->once()->andReturn(CarbonImmutable::instance(now())->addDay());
    Cache::partialMock()->shouldReceive('get')->once()->with(QueueProbeJob::latestKey('publish'))
        ->andThrow(new RuntimeException('PRIVATE-PROBE-CACHE'));
    $report = app(SystemStatus::class)->snapshot($admin);
    expect($report['queues'][0]['available'])->toBeFalse()
        ->and($report['queues'][0]['last_processed_at'])->toBeNull()
        ->and(json_encode($report))->not->toContain('PRIVATE-PROBE-CACHE');
});

test('SystemStatus: the navigation permission follows the same verified server allowlist', function () {
    $admin = User::factory()->create(['email' => 'operator@example.test']);
    $this->actingAs($admin)->get('/profile')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canViewSystemStatus', true));
    $ordinary = User::factory()->create();
    $this->actingAs($ordinary)->get('/profile')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canViewSystemStatus', false));
});

test('SystemStatus: changing a profile to an operator address does not bypass email verification', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name, 'email' => 'operator@example.test', 'is_admin' => true,
    ])->assertSessionHasNoErrors();
    expect($user->fresh()->email_verified_at)->toBeNull()
        ->and(SystemAccess::allows($user->fresh()))->toBeFalse();
    $this->actingAs($user->fresh())->get('/system')->assertRedirect(route('verification.notice'));
});

test('SystemStatus: operator allowlist matching normalizes case and whitespace without accepting invalid entries', function () {
    config(['health.admin_emails' => ['', null, 1, ['operator@example.test'], '  OPERATOR@EXAMPLE.TEST  ']]);
    $admin = User::factory()->create(['email' => 'operator@example.test']);
    expect(SystemAccess::allows($admin))->toBeTrue();
    config(['health.admin_emails' => ['', null, 1, ['operator@example.test']]]);
    expect(SystemAccess::allows($admin))->toBeFalse();
});

test('SystemStatus: token groups have stable alphabetical ordering independent of insertion and exclude disconnected accounts', function () {
    $admin = User::factory()->create(['email' => 'operator@example.test']);
    $owner = User::factory()->create();
    foreach ([Network::YouTube, Network::Instagram, Network::Facebook] as $network) {
        SocialAccount::factory()->for($owner)->create(['network' => $network]);
    }
    SocialAccount::factory()->for($owner)->create(['network' => Network::Bluesky, 'status' => SocialAccount::STATUS_DISCONNECTED, 'token_expires_at' => now()->subDay()]);
    $report = app(SystemStatus::class)->snapshot($admin);
    expect(array_keys($report['tokens']))->toBe(['facebook', 'instagram', 'youtube']);
    expect($report['tokens'])->not->toHaveKey('bluesky');
});

test('SystemStatus: token aggregation never selects encrypted credential columns', function () {
    $admin = User::factory()->create(['email' => 'operator@example.test']);
    SocialAccount::factory()->create();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if (str_contains(strtolower($query->sql), 'social_accounts')) {
            $queries[] = $query->sql;
        }
    });
    app(SystemStatus::class)->snapshot($admin);
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $sql) {
        expect($sql)->not->toMatch('/\b(?:access_token|refresh_token)\b/i');
        if (! str_starts_with(strtolower($sql), 'select exists(')) {
            expect($sql)->not->toMatch('/select\s+\*/i');
        }
    }
});

test('SystemStatus: individual probe expires at ten minutes while latest observation lasts twenty four hours', function () {
    $probe = (new QueueProbeJob('ttl-review-fixture'))->onQueue('media');
    $probe->handle();
    $individual = QueueProbeJob::cacheKey('ttl-review-fixture');
    $latest = QueueProbeJob::latestKey('media');
    expect(Cache::has($individual))->toBeTrue()->and(Cache::has($latest))->toBeTrue();
    $this->travel(11)->minutes();
    expect(Cache::has($individual))->toBeFalse()->and(Cache::has($latest))->toBeTrue();
    $this->travel(25)->hours();
    expect(Cache::has($latest))->toBeFalse();
});

test('SystemStatus: native maximum cache age is reported exactly without a fresh native probe', function () {
    config(['health.native_cache_seconds' => 321]);
    $admin = User::factory()->create(['email' => 'operator@example.test']);
    $this->mock(HealthCheck::class)->shouldReceive('run')->once()->with(false, true)->andReturn(['ok' => true, 'status' => 'ok', 'checks' => []]);
    expect(app(SystemStatus::class)->snapshot($admin)['native_cache_seconds'])->toBe(321);
});
