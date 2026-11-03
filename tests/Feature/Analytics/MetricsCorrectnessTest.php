<?php

use App\Analytics\Calculator;
use App\Analytics\Metric;
use App\Analytics\Period;
use App\Connectors\Google\Analytics as YouTubeAnalytics;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AggregateAnalytics;
use App\Services\AnalyticsService;
use App\Services\CampaignAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function accuracyAccount(array $overrides = []): SocialAccount
{
    return SocialAccount::factory()->for(test()->user)->create($overrides + [
        'brand_id' => test()->brand->id, 'network' => Network::Instagram,
    ]);
}

function accuracySnapshot(SocialAccount $account, string $date, array $metrics): void
{
    AccountSnapshot::withoutUserScope()->create([
        'user_id' => $account->user_id, 'brand_id' => $account->brand_id,
        'social_account_id' => $account->id, 'network' => $account->network,
        'date' => $date, 'metrics' => $metrics,
    ]);
}

test('Metrics accuracy: account engagement pairs both metrics within the same account and day', function () {
    $a = accuracyAccount();
    $b = accuracyAccount();
    accuracySnapshot($a, '2026-10-01', [Metric::VIEWS => 100, Metric::INTERACTIONS => 10]);
    accuracySnapshot($a, '2026-10-02', [Metric::VIEWS => 900, Metric::INTERACTIONS => null]);
    accuracySnapshot($b, '2026-10-01', [Metric::VIEWS => 1000, Metric::INTERACTIONS => null]);
    accuracySnapshot($b, '2026-10-03', [Metric::VIEWS => null, Metric::INTERACTIONS => 50]);

    $result = app(AnalyticsService::class)->network($this->user, $this->brand, Network::Instagram, Period::make('2026-10-01', '2026-10-03'));

    expect($result['kpis']['engagement_rate'])->toBe(10.0);
    // Individual totals remain truthful partial totals; they are not discarded for having no rate pair.
    expect(collect($result['metrics'])->firstWhere('key', Metric::VIEWS)['total'])->toBe(2000)
        ->and(collect($result['metrics'])->firstWhere('key', Metric::INTERACTIONS)['total'])->toBe(60);
});

test('Metrics accuracy: account engagement is unavailable when no account-day has a metric pair', function () {
    accuracySnapshot(accuracyAccount(), '2026-10-01', [Metric::VIEWS => 100, Metric::INTERACTIONS => null]);
    accuracySnapshot(accuracyAccount(), '2026-10-01', [Metric::VIEWS => null, Metric::INTERACTIONS => 20]);

    $result = app(AnalyticsService::class)->network($this->user, $this->brand, Network::Instagram, Period::make('2026-10-01', '2026-10-01'));

    expect($result['kpis']['engagement_rate'])->toBeNull();
});

test('Metrics accuracy: daily campaign rate pairs metrics per target before aggregating', function () {
    $campaign = Campaign::factory()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id]);
    $account = accuracyAccount();
    foreach ([[100, 10], [900, null], [null, 500]] as [$views, $interactions]) {
        $post = Post::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'campaign_id' => $campaign->id]);
        $target = PostTarget::factory()->create([
            'post_id' => $post->id, 'social_account_id' => $account->id, 'network' => $account->network,
            'status' => PostStatus::Published, 'published_at' => '2026-10-01 12:00:00',
        ]);
        foreach (['2026-10-02', '2026-10-03'] as $date) {
            PostMetricSnapshot::withoutUserScope()->create([
                'user_id' => $this->user->id, 'post_target_id' => $target->id,
                'social_account_id' => $account->id, 'network' => $account->network, 'date' => $date,
                'metrics' => [Metric::VIEWS => $views, Metric::INTERACTIONS => $date === '2026-10-03' && $views !== null ? null : $interactions],
            ]);
        }
    }

    $days = collect(app(CampaignAnalytics::class)->summary($this->user, $campaign)['daily'])->keyBy('date');

    expect($days['2026-10-02']['engagement_rate'])->toBe(10.0)
        ->and($days['2026-10-03']['engagement_rate'])->toBeNull()
        ->and($days['2026-10-02']['views'])->toBe(1000)
        ->and($days['2026-10-02']['interactions'])->toBe(510);
});

test('Metrics accuracy: every YouTube read addresses the connected channel rather than an implicit first channel', function () {
    $account = accuracyAccount(['network' => Network::YouTube, 'external_id' => 'UCselected', 'access_token' => 'fake-access']);
    Http::fake(function (Request $request) {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        if (str_contains($request->url(), 'youtube/v3/channels')) {
            return Http::response(['items' => [['id' => $query['id'] ?? 'UCdefault', 'statistics' => [
                'subscriberCount' => ($query['id'] ?? null) === 'UCselected' ? '20' : '9000',
            ]]]]);
        }
        $views = ($query['ids'] ?? null) === 'channel==UCselected' ? 200 : 9000;

        return Http::response(($query['dimensions'] ?? null) === 'day'
            ? ['columnHeaders' => [['name' => 'day'], ['name' => 'views']], 'rows' => [['2026-11-02', $views]]]
            : ['columnHeaders' => [['name' => 'views']], 'rows' => [[$views]]]);
    });
    $reader = app(YouTubeAnalytics::class);
    $day = CarbonImmutable::parse('2026-11-02');

    expect($reader->accountInsights($account, $day))->toMatchArray([Metric::VIEWS => 200, Metric::FOLLOWERS => 20])
        ->and($reader->accountInsightsRange($account, $day, $day)['2026-11-02'][Metric::VIEWS])->toBe(200)
        ->and($reader->postInsights($account, 'video-id')[Metric::VIEWS])->toBe(200);
});

test('Metrics accuracy: unequal available samples do not manufacture a period performance delta', function () {
    config(['social.analytics.all_brands_cache_minutes' => 0]);
    $a = accuracyAccount();
    accuracySnapshot($a, '2026-09-29', [Metric::VIEWS => 100]);
    accuracySnapshot($a, '2026-09-30', [Metric::VIEWS => 100]);
    accuracySnapshot($a, '2026-10-01', [Metric::VIEWS => 100]);
    $period = Period::make('2026-10-01', '2026-10-02');
    $compare = $period->comparison('previous');
    $summary = app(AnalyticsService::class)->summary($this->user, $this->brand, $period, $compare);
    $views = collect($summary['cards'])->firstWhere('key', 'views');
    expect($views['total'])->toBe(100)->and($views['previous'])->toBe(200)
        ->and($views['delta_pct'])->toBeNull()->and($views['delta_abs'])->toBeNull()
        ->and($views['comparison_quality']['status'])->toBe('unequal_coverage')
        ->and($views['comparison_quality']['previous_coverage']['observed_account_days'])->toBe(2);
    $network = app(AnalyticsService::class)->network($this->user, $this->brand, Network::Instagram, $period, $compare);
    expect(collect($network['metrics'])->firstWhere('key', 'views')['delta_pct'])->toBeNull();
    $all = app(AggregateAnalytics::class)->summary($this->user, $period, $compare);
    expect(collect($all['cards'])->firstWhere('key', 'views')['delta_pct'])->toBeNull();
});

test('Metrics accuracy: equal totals of observed days from different accounts are not comparable samples', function () {
    $a = accuracyAccount();
    $b = accuracyAccount();
    accuracySnapshot($a, '2026-10-01', [Metric::VIEWS => 10]);
    accuracySnapshot($b, '2026-09-29', [Metric::VIEWS => 20]);
    $period = Period::make('2026-10-01', '2026-10-02');
    $summary = app(AnalyticsService::class)->summary($this->user, $this->brand, $period, $period->comparison('previous'));
    $views = collect($summary['cards'])->firstWhere('key', 'views');
    expect($views['delta_pct'])->toBeNull()->and($views['comparison_quality']['comparable'])->toBeFalse();
});

test('Metrics accuracy: single-point stocks cannot dilute a growth percentage with an unknown baseline', function () {
    $growth = Calculator::growthAcross([
        ['2026-10-01' => 100, '2026-10-02' => 200],
        ['2026-10-02' => 900],
    ]);
    expect($growth['end'])->toBe(1100)->and($growth['change'])->toBe(100)
        ->and($growth['pct'])->toBeNull()->and($growth['unmeasured_accounts'])->toBe(1)
        ->and($growth['status'])->toBe('partial');
});

test('Metrics accuracy: daily KPIs identify the observed denominator when days are missing', function () {
    $a = accuracyAccount();
    accuracySnapshot($a, '2026-10-01', [Metric::VIEWS => 100, Metric::INTERACTIONS => 0]);
    $network = app(AnalyticsService::class)->network($this->user, $this->brand, Network::Instagram, Period::make('2026-10-01', '2026-10-03'));
    expect($network['kpis']['per_day']['views'])->toBe(100.0)
        ->and($network['kpis']['per_day_basis']['views'])->toMatchArray(['basis' => 'days_with_data', 'observed_days' => 1, 'requested_days' => 3, 'missing_days' => 2]);
});
