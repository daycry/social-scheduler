<?php

use App\Analytics\Metric;
use App\Analytics\Period;
use App\Connectors\Network;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AggregateAnalytics;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->period = Period::make('2026-10-01', '2026-10-10');
    $this->svc = app(AggregateAnalytics::class);
});

function aggAccount(User $user, Brand $brand, Network $network): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => $network]);
}

function aggSnap(SocialAccount $a, string $date, array $metrics): void
{
    AccountSnapshot::withoutUserScope()->create([
        'user_id' => $a->user_id, 'brand_id' => $a->brand_id, 'social_account_id' => $a->id,
        'network' => $a->network->value, 'date' => $date, 'metrics' => array_merge(Metric::blankAccount(), $metrics),
    ]);
}

it('CA-12: cada metrica es la suma de las marcas con dato y hay una fila por marca', function () {
    $a = Brand::factory()->for($this->user)->create(['name' => 'Alfa']);
    $b = Brand::factory()->for($this->user)->create(['name' => 'Beta']);
    $ia = aggAccount($this->user, $a, Network::Instagram);
    $ib = aggAccount($this->user, $b, Network::Instagram);
    aggSnap($ia, '2026-10-02', [Metric::FOLLOWERS => 100, Metric::VIEWS => 10, Metric::INTERACTIONS => 3]);
    aggSnap($ia, '2026-10-03', [Metric::FOLLOWERS => 110, Metric::VIEWS => 20]);
    aggSnap($ib, '2026-10-02', [Metric::FOLLOWERS => 40, Metric::VIEWS => 5]);

    $r = $this->svc->summary($this->user, $this->period);
    $cards = collect($r['cards'])->keyBy('key');

    expect($cards['views']['total'])->toBe(35)
        ->and($cards['followers']['total'])->toBe(150)->and($cards['followers']['kind'])->toBe('stock')
        ->and($cards['interactions']['total'])->toBe(3) // solo Alfa tiene dato
        ->and($cards['reach']['total'])->toBeNull()->and($cards['reach']['available'])->toBeFalse()
        ->and($r['brands'])->toHaveCount(2)
        ->and(collect($r['brands'])->pluck('name')->all())->toBe(['Alfa', 'Beta'])
        ->and($r['brands'][0]['values']['views'])->toBe(30)->and($r['brands'][1]['values']['views'])->toBe(5)
        ->and($r['brands'][1]['values']['interactions'])->toBeNull()
        ->and($cards['views']['series'])->toHaveCount(10)
        ->and(collect($cards['views']['series'])->firstWhere('date', '2026-10-02')['total'])->toBe(15);
});

it('Todas las marcas: el desglose por red suma entre marcas y respeta null por red (Bluesky sin VIEWS)', function () {
    $a = Brand::factory()->for($this->user)->create();
    $b = Brand::factory()->for($this->user)->create();
    aggSnap(aggAccount($this->user, $a, Network::Instagram), '2026-10-02', [Metric::VIEWS => 10]);
    aggSnap(aggAccount($this->user, $b, Network::Instagram), '2026-10-02', [Metric::VIEWS => 7]);
    aggSnap(aggAccount($this->user, $b, Network::Bluesky), '2026-10-02', [Metric::FOLLOWERS => 9]);

    $cards = collect($this->svc->summary($this->user, $this->period)['cards'])->keyBy('key');
    $views = collect($cards['views']['by_network'])->keyBy('network');

    expect($views['instagram']['value'])->toBe(17)
        ->and($views['bluesky']['value'])->toBeNull()->and($views['bluesky']['available'])->toBeFalse()
        ->and(collect($cards['followers']['by_network'])->firstWhere('network', 'bluesky')['value'])->toBe(9);
});

it('Todas las marcas: solo agrega las marcas del usuario', function () {
    $mine = Brand::factory()->for($this->user)->create();
    $other = User::factory()->create();
    $foreign = Brand::factory()->for($other)->create();
    aggSnap(aggAccount($this->user, $mine, Network::Instagram), '2026-10-02', [Metric::VIEWS => 10]);
    aggSnap(aggAccount($other, $foreign, Network::Instagram), '2026-10-02', [Metric::VIEWS => 999]);

    $r = $this->svc->summary($this->user, $this->period);

    expect($r['brands'])->toHaveCount(1)->and(collect($r['cards'])->firstWhere('key', 'views')['total'])->toBe(10);
});

it('Todas las marcas: la comparacion suma los periodos previos y sin marcas todo es no disponible', function () {
    $a = Brand::factory()->for($this->user)->create();
    $ia = aggAccount($this->user, $a, Network::Instagram);
    aggSnap($ia, '2026-09-25', [Metric::VIEWS => 5]);
    aggSnap($ia, '2026-10-02', [Metric::VIEWS => 10]);

    $r = $this->svc->summary($this->user, $this->period, $this->period->comparison('previous'));
    $views = collect($r['cards'])->firstWhere('key', 'views');
    expect($views['total'])->toBe(10)->and($views['previous'])->toBe(5)->and($views['delta_pct'])->toBe(100.0);

    $empty = $this->svc->summary(User::factory()->create(), $this->period);
    expect($empty['brands'])->toBe([])->and(collect($empty['cards'])->every(fn ($c) => $c['total'] === null))->toBeTrue();
});

it('Todas las marcas: con cache activa el segundo calculo del mismo periodo no vuelve a consultar', function () {
    config(['social.analytics.all_brands_cache_minutes' => 5]);
    $a = Brand::factory()->for($this->user)->create();
    aggSnap(aggAccount($this->user, $a, Network::Instagram), '2026-10-02', [Metric::VIEWS => 10]);

    $this->svc->summary($this->user, $this->period);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $r = $this->svc->summary($this->user, $this->period);

    expect(DB::getQueryLog())->toBe([])->and(collect($r['cards'])->firstWhere('key', 'views')['total'])->toBe(10);
});
