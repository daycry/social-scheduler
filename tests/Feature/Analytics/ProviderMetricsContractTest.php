<?php

use App\Analytics\Calculator;
use App\Analytics\Metric;
use App\Analytics\Period;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AggregateAnalytics;
use App\Services\AnalyticsService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');
    config(['social.analytics.all_brands_cache_minutes' => 0]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('T-03 contract: every network declares its expected daily flows and none uses a metric Meta retired', function () {
    foreach (Network::cases() as $network) {
        expect(Metric::EXPECTED_FLOWS)->toHaveKey($network->value);
    }

    foreach ([...Metric::ACCOUNT, ...Metric::POST, ...array_merge(...array_values(Metric::EXPECTED_FLOWS))] as $key) {
        expect($key)->not->toBeIn(['impressions', 'page_impressions', 'page_fans', 'fans']);
    }
});

test('T-03 contract: networks without a metrics reader (LinkedIn, waiting for community_management) report unsupported and null, never zero', function () {
    foreach ([Network::LinkedIn] as $network) {
        expect(app(ConnectorRegistry::class)->insightsFor($network))->toBeNull();
        SocialAccount::factory()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'network' => $network]);
    }

    $period = Period::make('2026-10-31', '2026-11-02');
    $cards = collect(app(AnalyticsService::class)->summary($this->user, $this->brand, $period)['cards'])->keyBy('key');
    $all = collect(app(AggregateAnalytics::class)->summary($this->user, $period)['cards'])->keyBy('key');

    foreach (['views', 'reach', 'interactions', 'followers'] as $key) {
        expect($cards[$key]['total'])->toBeNull()->and($cards[$key]['available'])->toBeFalse()
            ->and($cards[$key]['coverage']['status'])->toBe('unsupported')
            ->and($all[$key]['total'])->toBeNull();
    }
});

test('T-03 contract: ratios and comparisons keep null for unknown and zero only for a measured zero', function () {
    expect(Calculator::engagementRate(5, 0))->toBeNull()
        ->and(Calculator::engagementRate(null, 100))->toBeNull()
        ->and(Calculator::engagementRate(0, 100))->toBe(0.0)
        ->and(Calculator::delta(10, 0))->toBe(['abs' => 10, 'pct' => null])
        ->and(Calculator::delta(0, 10))->toBe(['abs' => -10, 'pct' => -100.0])
        ->and(Calculator::delta(null, 10))->toBe(['abs' => null, 'pct' => null])
        ->and(Calculator::delta(10, null))->toBe(['abs' => null, 'pct' => null]);
});

test('T-03 contract: all-brands comparison does not turn a brand without previous data into a zero baseline', function () {
    $other = Brand::factory()->for($this->user)->create();
    $a = SocialAccount::factory()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $b = SocialAccount::factory()->create(['user_id' => $this->user->id, 'brand_id' => $other->id, 'network' => Network::Instagram]);
    foreach ([[$a, '2026-10-30', 10], [$a, '2026-10-31', 10], [$b, '2026-10-31', 20]] as [$acc, $date, $views]) {
        AccountSnapshot::withoutUserScope()->create([
            'user_id' => $acc->user_id, 'brand_id' => $acc->brand_id, 'social_account_id' => $acc->id, 'network' => $acc->network,
            'date' => $date, 'metrics' => array_merge(Metric::blankAccount(), [Metric::VIEWS => $views]), 'collected_at' => '2026-11-01 12:00:00',
        ]);
    }

    $card = collect(app(AggregateAnalytics::class)->summary($this->user, Period::make('2026-10-31', '2026-10-31'))['cards'])->keyBy('key')['views'];

    expect($card['total'])->toBe(30)->and($card['reach'] ?? null)->toBeNull();
    // El periodo previo (30 oct) solo tiene 10 de una marca; la muestra no es comparable con 2 cuentas: sin porcentaje.
    expect($card['delta']['pct'] ?? null)->toBeNull();
});
