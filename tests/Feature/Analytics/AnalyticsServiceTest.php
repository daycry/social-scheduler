<?php

use App\Analytics\Metric;
use App\Analytics\Period;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->svc = app(AnalyticsService::class);
    $this->period = Period::make('2026-10-01', '2026-10-10');
});

function snap(SocialAccount $account, string $date, array $metrics): AccountSnapshot
{
    return AccountSnapshot::withoutUserScope()->create([
        'user_id' => $account->user_id, 'brand_id' => $account->brand_id, 'social_account_id' => $account->id,
        'network' => $account->network->value, 'date' => $date, 'metrics' => array_merge(Metric::blankAccount(), $metrics),
    ]);
}

function acct(User $user, Brand $brand, Network $network): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => $network]);
}

it('Analytics: el resumen suma por red, indica las redes sin dato y construye la linea temporal', function () {
    $ig = acct($this->user, $this->brand, Network::Instagram);
    $yt = acct($this->user, $this->brand, Network::YouTube);
    $tt = acct($this->user, $this->brand, Network::TikTok);

    snap($ig, '2026-10-01', [Metric::FOLLOWERS => 100, Metric::VIEWS => 10, Metric::REACH => 8]);
    snap($ig, '2026-10-02', [Metric::FOLLOWERS => 110, Metric::VIEWS => 20]);
    snap($yt, '2026-10-02', [Metric::FOLLOWERS => 500, Metric::VIEWS => 5, Metric::INTERACTIONS => 2]);
    snap($tt, '2026-10-02', []); // sin scopes: todo no disponible

    $s = $this->svc->summary($this->user, $this->brand, $this->period);
    $cards = collect($s['cards'])->keyBy('key');

    expect($cards['views']['total'])->toBe(35)->and($cards['views']['kind'])->toBe('flow')
        ->and($cards['followers']['total'])->toBe(610)->and($cards['followers']['kind'])->toBe('stock')
        ->and($cards['reach']['total'])->toBe(8)
        ->and($cards['interactions']['total'])->toBe(2);

    $byNet = collect($cards['views']['by_network'])->keyBy('network');
    expect($byNet['instagram']['value'])->toBe(30)->and($byNet['youtube']['value'])->toBe(5)
        ->and($byNet['tiktok']['value'])->toBeNull()->and($byNet['tiktok']['available'])->toBeFalse();

    expect($cards['views']['series'])->toHaveCount(10)
        ->and($cards['views']['series'][1]['total'])->toBe(25)
        ->and($cards['views']['series'][1]['values'])->toMatchArray(['instagram' => 20, 'youtube' => 5])
        ->and($cards['views']['series'][5]['total'])->toBeNull();
});

it('Analytics: sin ningun snapshot los totales son no disponibles y no 0', function () {
    acct($this->user, $this->brand, Network::Facebook);

    $s = $this->svc->summary($this->user, $this->brand, $this->period);

    foreach ($s['cards'] as $card) {
        expect($card['total'])->toBeNull()->and($card['available'])->toBeFalse();
    }
});

it('Analytics: la comparacion calcula variaciones frente al periodo anterior', function () {
    $ig = acct($this->user, $this->brand, Network::Instagram);
    snap($ig, '2026-09-25', [Metric::VIEWS => 100]);
    snap($ig, '2026-10-05', [Metric::VIEWS => 150]);

    $s = $this->svc->summary($this->user, $this->brand, $this->period, $this->period->comparison('previous'));
    $views = collect($s['cards'])->firstWhere('key', 'views');

    expect($views['total'])->toBe(150)->and($views['previous'])->toBe(100)->and($views['delta_pct'])->toBe(50.0)->and($views['delta_abs'])->toBe(50)
        ->and($s['comparison']['from'])->toBe('2026-09-21');
});

it('Analytics: varias cuentas de la misma red se suman', function () {
    snap(acct($this->user, $this->brand, Network::Instagram), '2026-10-01', [Metric::VIEWS => 10, Metric::FOLLOWERS => 100]);
    snap(acct($this->user, $this->brand, Network::Instagram), '2026-10-01', [Metric::VIEWS => 5, Metric::FOLLOWERS => 50]);

    $cards = collect($this->svc->summary($this->user, $this->brand, $this->period)['cards'])->keyBy('key');

    expect($cards['views']['total'])->toBe(15)->and($cards['followers']['total'])->toBe(150);
});

it('Analytics: la pagina de red calcula crecimiento, KPIs y marca como no disponible lo que la API no da', function () {
    $ig = acct($this->user, $this->brand, Network::Instagram);
    snap($ig, '2026-10-01', [Metric::FOLLOWERS => 200, Metric::VIEWS => 100, Metric::REACH => 80]);
    snap($ig, '2026-10-05', [Metric::FOLLOWERS => 230, Metric::VIEWS => 300]);

    $post = Post::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'text' => 'Hola mundo']);
    $t = PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $ig->id, 'network' => Network::Instagram,
        'status' => PostStatus::Published, 'external_id' => 'm1', 'published_at' => '2026-10-03 10:00:00',
    ]);
    PostMetricSnapshot::withoutUserScope()->create(['user_id' => $this->user->id, 'post_target_id' => $t->id, 'social_account_id' => $ig->id,
        'network' => 'instagram', 'date' => '2026-10-04', 'metrics' => [Metric::VIEWS => 100, Metric::INTERACTIONS => 10]]);
    PostMetricSnapshot::withoutUserScope()->create(['user_id' => $this->user->id, 'post_target_id' => $t->id, 'social_account_id' => $ig->id,
        'network' => 'instagram', 'date' => '2026-10-06', 'metrics' => [Metric::VIEWS => 200, Metric::INTERACTIONS => 30]]);

    $d = $this->svc->network($this->user, $this->brand, Network::Instagram, $this->period);
    $m = collect($d['metrics'])->keyBy('key');

    expect($d['growth'])->toMatchArray(['start' => 200, 'end' => 230, 'change' => 30, 'pct' => 15.0])
        ->and($m['views']['total'])->toBe(400)->and($m['reach']['total'])->toBe(80)
        ->and($m['profile_views']['available'])->toBeFalse()->and($m['profile_views']['total'])->toBeNull()
        ->and($m)->not->toHaveKey('watch_minutes')
        ->and($d['kpis']['per_day']['views'])->toBe(200.0)
        ->and($d['kpis']['engagement_rate'])->toBeNull() // no hay interacciones a nivel de cuenta en IG
        ->and($d['posts'])->toHaveCount(1)
        ->and($d['posts'][0]['metrics'][Metric::INTERACTIONS])->toBe(30)      // el ultimo snapshot
        ->and($d['posts'][0]['engagement_rate'])->toBe(15.0)
        ->and($d['kpis']['per_post'])->toMatchArray(['posts' => 1, 'views' => 200.0, 'interactions' => 30.0, 'engagement_rate' => 15.0]);
});

it('Analytics: un destino sin snapshots aparece con datos no disponibles', function () {
    $ig = acct($this->user, $this->brand, Network::Instagram);
    $post = Post::factory()->for($this->user)->create(['brand_id' => $this->brand->id]);
    PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $ig->id, 'network' => Network::Instagram,
        'status' => PostStatus::Published, 'external_id' => 'x', 'published_at' => '2026-10-03 10:00:00']);

    $d = $this->svc->network($this->user, $this->brand, Network::Instagram, $this->period);

    expect($d['posts'][0]['has_data'])->toBeFalse()->and($d['posts'][0]['engagement_rate'])->toBeNull()
        ->and($d['kpis']['per_post']['views'])->toBeNull();
});

it('Analytics: aislamiento por usuario, no se leen datos de otra marca ni de otro usuario', function () {
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    snap(acct($other, $otherBrand, Network::Instagram), '2026-10-02', [Metric::VIEWS => 999]);
    snap(acct($this->user, Brand::factory()->for($this->user)->create(), Network::Instagram), '2026-10-02', [Metric::VIEWS => 777]);
    acct($this->user, $this->brand, Network::Instagram);

    $views = collect($this->svc->summary($this->user, $this->brand, $this->period)['cards'])->firstWhere('key', 'views');

    expect($views['total'])->toBeNull();
    expect(fn () => $this->svc->summary($this->user, $otherBrand, $this->period))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->svc->network($this->user, $otherBrand, Network::Instagram, $this->period))->toThrow(InvalidArgumentException::class);
});
