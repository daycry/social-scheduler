<?php

use App\Analytics\Metric;
use App\Analytics\Period;
use App\Connectors\Network;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AggregateAnalytics;
use App\Services\AnalyticsService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');
    config(['social.analytics.all_brands_cache_minutes' => 0]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function coverageAccount(Brand $brand, Network $network): SocialAccount
{
    return SocialAccount::factory()->create(['user_id' => $brand->user_id, 'brand_id' => $brand->id, 'network' => $network]);
}

function coverageSnapshot(SocialAccount $account, string $date, array $metrics, ?string $attempt = null): void
{
    AccountSnapshot::withoutUserScope()->create([
        'user_id' => $account->user_id, 'brand_id' => $account->brand_id, 'social_account_id' => $account->id,
        'network' => $account->network, 'date' => $date, 'metrics' => $metrics, 'collected_at' => $attempt,
    ]);
}

test('Metric coverage distinguishes real zero from missing account-days and unsupported networks', function () {
    $ig = coverageAccount($this->brand, Network::Instagram);
    coverageAccount($this->brand, Network::Facebook);
    coverageAccount($this->brand, Network::Bluesky);
    coverageSnapshot($ig, '2026-10-31', [Metric::VIEWS => 0], '2026-11-01 12:00:00');
    coverageSnapshot($ig, '2026-11-01', [Metric::VIEWS => null], '2026-11-02 12:00:00');
    $period = Period::make('2026-10-31', '2026-11-02');

    $cards = collect(app(AnalyticsService::class)->summary($this->user, $this->brand, $period)['cards'])->keyBy('key');
    $views = $cards['views'];
    expect($views['total'])->toBe(0)->and($views['available'])->toBeTrue()
        ->and($views['coverage'])->toMatchArray([
            'status' => 'partial', 'accounts' => 2, 'accounts_with_data' => 1, 'unsupported_accounts' => 1,
            'expected_account_days' => 6, 'snapshot_account_days' => 2, 'observed_account_days' => 1, 'missing_account_days' => 5,
        ])
        ->and($views['freshness'])->toMatchArray([
            'basis' => 'snapshot_attempts', 'latest_attempt_at' => '2026-11-02T12:00:00+00:00',
            'latest_data_date' => '2026-10-31', 'oldest_latest_data_date' => null, 'latest_expected_date' => '2026-11-02',
            'lagging_accounts' => 2, 'is_stale' => true, 'data_updated_at' => null,
        ]);
    $networks = collect($views['by_network'])->keyBy('network');
    expect($networks['facebook']['coverage']['status'])->toBe('no_data')
        ->and($networks['bluesky']['coverage']['status'])->toBe('unsupported');
});

test('Metric coverage excludes open network days and marks complete numeric zero series', function () {
    CarbonImmutable::setTestNow('2026-11-03 03:30:00 UTC'); // Still Nov 2 in Pacific: latest closed day is Nov 1.
    $ig = coverageAccount($this->brand, Network::Instagram);
    coverageSnapshot($ig, '2026-11-01', [Metric::VIEWS => 0], '2026-11-03 03:00:00');
    $result = app(AnalyticsService::class)->network($this->user, $this->brand, Network::Instagram, Period::make('2026-11-01', '2026-11-03'));
    $views = collect($result['metrics'])->firstWhere('key', 'views');

    expect($views['coverage'])->toMatchArray(['status' => 'complete', 'expected_account_days' => 1, 'observed_account_days' => 1, 'missing_account_days' => 0])
        ->and($views['freshness'])->toMatchArray(['is_stale' => false, 'lagging_accounts' => 0, 'latest_expected_date' => '2026-11-01']);
    $future = app(AnalyticsService::class)->network($this->user, $this->brand, Network::Instagram, Period::make('2026-11-03', '2026-11-03'));
    $views = collect($future['metrics'])->firstWhere('key', 'views');
    expect($views['total'])->toBeNull()->and($views['coverage']['status'])->toBe('not_due')->and($views['freshness']['is_stale'])->toBeNull();
});

test('All brands aggregate metric coverage without mixing owners or treating unavailable metrics as zero', function () {
    $otherBrand = Brand::factory()->for($this->user)->create();
    $foreignBrand = Brand::factory()->create();
    $a = coverageAccount($this->brand, Network::Instagram);
    $b = coverageAccount($otherBrand, Network::Facebook);
    coverageSnapshot($a, '2026-11-01', [Metric::VIEWS => 0], '2026-11-02 12:00:00');
    coverageSnapshot($b, '2026-11-01', [Metric::VIEWS => 10], '2026-11-02 13:00:00');
    coverageSnapshot(coverageAccount($foreignBrand, Network::Instagram), '2026-11-02', [Metric::VIEWS => 999], '2026-11-03 11:00:00');

    $cards = collect(app(AggregateAnalytics::class)->summary($this->user, Period::make('2026-11-01', '2026-11-02'))['cards'])->keyBy('key');
    expect($cards['views']['total'])->toBe(10)
        ->and($cards['views']['coverage'])->toMatchArray(['status' => 'partial', 'accounts' => 2, 'expected_account_days' => 4, 'observed_account_days' => 2, 'missing_account_days' => 2])
        ->and($cards['views']['freshness'])->toMatchArray(['latest_attempt_at' => '2026-11-02T13:00:00+00:00', 'latest_data_date' => '2026-11-01', 'lagging_accounts' => 2, 'is_stale' => true]);
    expect($cards['reach']['total'])->toBeNull()->and($cards['reach']['coverage']['status'])->toBe('no_data');
});

test('Metric coverage and freshness survive dashboard and MCP projections without daily series', function () {
    $a = coverageAccount($this->brand, Network::Instagram);
    coverageSnapshot($a, '2026-11-01', [Metric::VIEWS => 0], '2026-11-02 12:00:00');
    $query = ['from' => '2026-11-01', 'to' => '2026-11-02'];

    $this->actingAs($this->user)->get(route('dashboard.all', $query))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('summary.cards.1.coverage.status', 'partial')->where('summary.cards.1.coverage.observed_account_days', 1)
        ->where('summary.cards.1.freshness.is_stale', true));

    foreach ([[], ['brand_id' => $this->brand->id], ['brand_id' => $this->brand->id, 'network' => 'instagram']] as $scope) {
        [$error, $result] = $this->callTool($this->mcpKey($this->user), 'get_stats', $scope + $query);
        $views = collect($result[isset($scope['network']) ? 'metrics' : 'cards'])->firstWhere('key', 'views');
        expect($error)->toBeFalse()->and($views)->not->toHaveKey('series')
            ->and($views['coverage']['status'])->toBe('partial')
            ->and($views['freshness']['latest_attempt_at'])->toBe('2026-11-02T12:00:00+00:00')
            ->and($views['freshness']['data_updated_at'])->toBeNull();
    }
});

test('coverage includes disconnected accounts when their historical snapshots contribute to the same total', function () {
    $a = coverageAccount($this->brand, Network::Instagram);
    $b = coverageAccount($this->brand, Network::Instagram);
    $b->update(['status' => SocialAccount::STATUS_DISCONNECTED]);
    $unused = coverageAccount($this->brand, Network::Instagram);
    $unused->update(['status' => SocialAccount::STATUS_DISCONNECTED]); // No historical contribution in this period.
    coverageSnapshot($a, '2026-11-01', [Metric::VIEWS => 100]);
    coverageSnapshot($a, '2026-11-02', [Metric::VIEWS => 100]);
    coverageSnapshot($b, '2026-11-01', [Metric::VIEWS => 900]);
    $period = Period::make('2026-11-01', '2026-11-02');
    $summary = app(AnalyticsService::class)->summary($this->user, $this->brand, $period);
    $network = app(AnalyticsService::class)->network($this->user, $this->brand, Network::Instagram, $period);
    $aggregate = app(AggregateAnalytics::class)->summary($this->user, $period);
    foreach ([$summary['cards'], $network['metrics'], $aggregate['cards']] as $metrics) {
        $views = collect($metrics)->firstWhere('key', 'views');
        expect($views['total'])->toBe(1100)
            ->and($views['coverage'])->toMatchArray(['status' => 'partial', 'accounts' => 2, 'expected_account_days' => 4, 'observed_account_days' => 3, 'missing_account_days' => 1])
            ->and($views['freshness']['is_stale'])->toBeTrue();
    }
    expect($network['accounts'])->toHaveCount(1); // Connection UI still lists only connected accounts.
});
