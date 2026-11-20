<?php

use App\Analytics\DashboardSnapshotCache;
use App\Analytics\Metric;
use App\Analytics\Period;
use App\Analytics\SnapshotCollector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\InsightsReader;
use App\Connectors\Contracts\RangeInsightsReader;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard reuses cached snapshots and invalidates only the brand that collected a snapshot', function (bool $networkPage) {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $other = Brand::factory()->for($user)->create();
    $account = SocialAccount::factory()->for($user)->for($brand)->create(['network' => Network::Instagram]);
    $otherAccount = SocialAccount::factory()->for($user)->for($other)->create(['network' => Network::Instagram]);
    $day = CarbonImmutable::parse('2026-10-01');
    $reader = Mockery::mock(InsightsReader::class);
    $reader->shouldReceive('accountInsights')->with($account, $day)->times(3)->andReturn([Metric::VIEWS => 10], [Metric::VIEWS => 40], [Metric::VIEWS => 20]);
    $reader->shouldReceive('accountInsights')->with($otherAccount, $day)->once()->andReturn([Metric::VIEWS => 30]);
    app(ConnectorRegistry::class)->registerInsights(Network::Instagram, $reader);
    $collector = app(SnapshotCollector::class);
    $collector->collectAccount($account, $day);
    $collector->collectAccount($otherAccount, $day);
    $route = $networkPage ? 'dashboard.network' : 'dashboard';
    $metric = $networkPage ? 'detail.metrics' : 'summary.cards';
    $url = fn (Brand $brand) => route($route, [
        'brand_id' => $brand->id, 'from' => $day->toDateString(), 'to' => $day->toDateString(),
    ] + ($networkPage ? ['network' => 'instagram'] : []));

    $this->actingAs($user)->get($url($brand))->assertOk();
    $this->get($url($other))->assertOk();
    DB::enableQueryLog();
    try {
        $this->get($url($brand))->assertOk();
        expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'account_snapshots')))->toBeEmpty();

        DB::beginTransaction();
        try {
            $collector->collectAccount($account, $day);
        } finally {
            DB::rollBack();
        }
        DB::flushQueryLog();
        $this->get($url($brand))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where($metric, fn ($metrics) => collect($metrics)->firstWhere('key', Metric::VIEWS)['total'] === 10));
        expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'account_snapshots')))->toBeEmpty();

        $collector->collectAccount($account, $day);
        DB::flushQueryLog();
        $this->get($url($other))->assertOk();
        expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'account_snapshots')))->toBeEmpty();

        $this->get($url($brand))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where($metric, fn ($metrics) => collect($metrics)->firstWhere('key', Metric::VIEWS)['total'] === 20));
    } finally {
        DB::disableQueryLog();
    }
})->with(['summary' => false, 'network' => true]);

test('dashboard sees a historical batch even when a later write fails', function (bool $partial) {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $account = SocialAccount::factory()->for($user)->for($brand)->create(['network' => Network::Facebook]);
    $reader = Mockery::mock(RangeInsightsReader::class);
    $reader->shouldReceive('maxRangeDays')->with($account)->once()->andReturn(30);
    $reader->shouldReceive('accountInsightsRange')->once()->andReturn([
        '2026-10-01' => [Metric::VIEWS => 10],
        '2026-10-02' => [Metric::VIEWS => 12],
    ]);
    app(ConnectorRegistry::class)->registerInsights(Network::Facebook, $reader);
    $url = route('dashboard', ['brand_id' => $brand->id, 'from' => '2026-10-01', 'to' => '2026-10-02']);
    $assertViews = fn (?int $views) => $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('summary.cards', fn ($cards) => collect($cards)->firstWhere('key', Metric::VIEWS)['total'] === $views));

    $this->actingAs($user);
    $assertViews(null);
    $collect = fn () => app(SnapshotCollector::class)->collectRange($account, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-02'));
    if ($partial) {
        Event::listen('eloquent.creating: '.AccountSnapshot::class, function (AccountSnapshot $snapshot): void {
            if ($snapshot->date === '2026-10-02') {
                throw new RuntimeException('Historical write failed');
            }
        });
        expect($collect)->toThrow(RuntimeException::class, 'Historical write failed');
        $assertViews(10);
    } else {
        expect($collect())->toBe(2);
        $assertViews(22);
    }
})->with(['complete' => false, 'partially written' => true]);

test('dashboard serves cached results for a minute then refreshes them after the response', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $period = Period::make('2026-10-01', '2026-10-02');
    $computations = 0;
    $load = function () use (&$computations): array {
        return [++$computations];
    };
    $read = fn () => DashboardSnapshotCache::remember($user, $brand, $period, null, null, collect(), $load);

    expect($read())->toBe([1]);
    $this->travel(59)->seconds();
    expect($read())->toBe([1]);
    $this->travel(2)->seconds();
    expect($read())->toBe([1]);
    app(DeferredCallbackCollection::class)->invoke();
    expect($read())->toBe([2]);
    $this->travel(300)->seconds();
    expect($read())->toBe([3]);
    $this->travelBack();
});

test('a warm network view still reads account names and publication metrics live', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $account = SocialAccount::factory()->for($user)->for($brand)->create(['network' => Network::Instagram]);
    $url = route('dashboard.network', ['network' => 'instagram', 'brand_id' => $brand->id, 'from' => '2026-10-01', 'to' => '2026-10-02']);
    $this->actingAs($user)->get($url)->assertOk();

    $account->update(['name' => 'Cuenta renombrada']);
    $post = Post::factory()->for($user)->for($brand)->create();
    $target = PostTarget::factory()->for($post)->create([
        'social_account_id' => $account->id, 'network' => Network::Instagram,
        'status' => PostStatus::Published, 'published_at' => '2026-10-01 12:00:00',
    ]);
    PostMetricSnapshot::withoutUserScope()->create([
        'user_id' => $user->id, 'post_target_id' => $target->id, 'social_account_id' => $account->id,
        'network' => Network::Instagram, 'date' => '2026-10-02', 'metrics' => [Metric::VIEWS => 17],
    ]);

    DB::enableQueryLog();
    try {
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.accounts.0.name', 'Cuenta renombrada')
            ->where('detail.posts.0.metrics.views', 17));
        expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'account_snapshots')))->toBeEmpty();
    } finally {
        DB::disableQueryLog();
    }
});
