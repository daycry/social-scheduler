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
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->svc = app(AnalyticsService::class);
});

function anaRvAccount(User $u, Brand $b, Network $n = Network::Instagram): SocialAccount
{
    return SocialAccount::factory()->for($u)->create(['brand_id' => $b->id, 'network' => $n]);
}

function anaRvSnap(SocialAccount $a, string $date, array $metrics): void
{
    AccountSnapshot::withoutUserScope()->create([
        'user_id' => $a->user_id, 'brand_id' => $a->brand_id, 'social_account_id' => $a->id, 'network' => $a->network->value,
        'date' => $date, 'metrics' => array_merge(Metric::blankAccount(), $metrics),
    ]);
}

function anaRvPost(SocialAccount $a, string $publishedAt, ?array $metrics = null, string $ext = 'x'): PostTarget
{
    $post = Post::factory()->for($a->user)->create(['brand_id' => $a->brand_id, 'text' => 'p '.$ext]);
    $t = PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $a->id, 'network' => $a->network,
        'status' => PostStatus::Published, 'external_id' => $ext, 'published_at' => $publishedAt,
    ]);

    if ($metrics !== null) {
        PostMetricSnapshot::withoutUserScope()->create(['user_id' => $a->user_id, 'post_target_id' => $t->id, 'social_account_id' => $a->id,
            'network' => $a->network->value, 'date' => '2026-10-20', 'metrics' => array_merge(Metric::blankPost(), $metrics)]);
    }

    return $t;
}

it('Crecimiento: una cuenta conectada a mitad de periodo no produce un salto (se suma la variacion de cada cuenta)', function () {
    $a = anaRvAccount($this->user, $this->brand);
    $b = anaRvAccount($this->user, $this->brand);

    for ($d = 1; $d <= 31; $d++) {
        $date = sprintf('2026-10-%02d', $d);
        anaRvSnap($a, $date, [Metric::FOLLOWERS => 1000 + $d]);

        if ($d >= 15) {
            anaRvSnap($b, $date, [Metric::FOLLOWERS => 50000]);
        }
    }

    $r = $this->svc->network($this->user, $this->brand, Network::Instagram, Period::make('2026-10-01', '2026-10-31'));

    expect($r['growth']['change'])->toBe(30)->and($r['growth']['accounts'])->toBe(2);
    // Total de seguidores (stock) = suma del ultimo valor de cada cuenta.
    expect(collect($r['metrics'])->firstWhere('key', 'followers')['total'])->toBe(51031);
});

it('Crecimiento: una cuenta que deja de tener snapshots no hunde el crecimiento ni el total', function () {
    $a = anaRvAccount($this->user, $this->brand);
    $b = anaRvAccount($this->user, $this->brand);

    for ($d = 15; $d <= 31; $d++) {
        $date = sprintf('2026-10-%02d', $d);
        anaRvSnap($a, $date, [Metric::FOLLOWERS => 1000 + $d]);

        if ($d <= 25) {
            anaRvSnap($b, $date, [Metric::FOLLOWERS => 50000]);
        }
    }

    $r = $this->svc->network($this->user, $this->brand, Network::Instagram, Period::make('2026-10-15', '2026-10-31'));
    $followers = collect($r['metrics'])->firstWhere('key', 'followers');

    expect($r['growth']['change'])->toBe(16)           // solo la variacion propia de A (B no se movio)
        ->and($followers['total'])->toBe(51031)         // B conserva su ultimo valor conocido
        ->and(collect($followers['series'])->firstWhere('date', '2026-10-20')['value'])->toBe(1020 + 50000)
        ->and(collect($followers['series'])->firstWhere('date', '2026-10-27')['value'])->toBe(1027); // B ya no suma tras su ultimo dato
});

it('Crecimiento: un solo punto no cuenta como variacion pero su valor suma a los seguidores del fin del periodo', function () {
    $a = anaRvAccount($this->user, $this->brand);
    $b = anaRvAccount($this->user, $this->brand);
    anaRvSnap($a, '2026-10-05', [Metric::FOLLOWERS => 100]);
    anaRvSnap($a, '2026-10-20', [Metric::FOLLOWERS => 110]);
    anaRvSnap($b, '2026-10-25', [Metric::FOLLOWERS => 40]);

    $r = $this->svc->network($this->user, $this->brand, Network::Instagram, Period::make('2026-10-01', '2026-10-31'));
    $total = collect($r['metrics'])->firstWhere('key', 'followers')['total'];

    expect($r['growth']['change'])->toBe(10)->and($r['growth']['end'])->toBe(150)->and($r['growth']['end'])->toBe($total);
});

it('Crecimiento: una cuenta con un solo punto no cuenta como variacion y sin ninguna medible no hay crecimiento', function () {
    $a = anaRvAccount($this->user, $this->brand);
    anaRvSnap($a, '2026-10-05', [Metric::FOLLOWERS => 300]);

    $r = $this->svc->network($this->user, $this->brand, Network::Instagram, Period::make('2026-10-01', '2026-10-31'));

    expect($r['growth']['change'])->toBeNull()->and($r['growth']['start'])->toBe(300);
});

it('Interacciones y engagement de cuenta de Instagram con total_interactions', function () {
    $a = anaRvAccount($this->user, $this->brand);
    anaRvSnap($a, '2026-10-02', [Metric::VIEWS => 1000, Metric::INTERACTIONS => 50]);

    $r = $this->svc->network($this->user, $this->brand, Network::Instagram, Period::make('2026-10-01', '2026-10-10'));
    $m = collect($r['metrics'])->keyBy('key');

    expect($m['interactions']['total'])->toBe(50)->and($m['interactions']['available'])->toBeTrue()
        ->and($r['kpis']['engagement_rate'])->toBe(5.0);
});

it('KPIs por publicacion: se agregan sobre TODAS las del periodo y la lista es un top N explicito con aviso del total', function () {
    config(['social.analytics.dashboard_posts' => 3]);
    $a = anaRvAccount($this->user, $this->brand);

    foreach (range(1, 8) as $i) {
        anaRvPost($a, '2026-10-0'.min($i, 9).' 10:00:00', [Metric::VIEWS => 100 * $i, Metric::INTERACTIONS => 10 * $i], "m$i");
    }

    anaRvPost($a, '2026-10-09 10:00:00', null, 'sin-datos');

    $r = $this->svc->network($this->user, $this->brand, Network::Instagram, Period::make('2026-10-01', '2026-10-10'));

    // 9 publicaciones: la lista muestra 3 (las de mas interacciones) pero los KPIs usan las 8 con datos.
    expect($r['posts'])->toHaveCount(3)->and($r['posts_total'])->toBe(9)->and($r['posts_limit'])->toBe(3)->and($r['posts_truncated'])->toBeTrue()
        ->and(array_column(array_column($r['posts'], 'metrics'), Metric::INTERACTIONS))->toBe([80, 70, 60])
        ->and($r['kpis']['per_post']['posts'])->toBe(9)
        ->and($r['kpis']['per_post']['views'])->toBe(450.0)          // media de 100..800
        ->and($r['kpis']['per_post']['interactions'])->toBe(45.0)
        ->and($r['kpis']['per_post']['engagement_rate'])->toBe(10.0);
});

it('KPIs por publicacion: un valor null de la API no cuenta como 0 en la media', function () {
    $a = anaRvAccount($this->user, $this->brand);
    anaRvPost($a, '2026-10-03 10:00:00', [Metric::VIEWS => 100, Metric::INTERACTIONS => 10], 'a');
    anaRvPost($a, '2026-10-04 10:00:00', [Metric::VIEWS => 300, Metric::INTERACTIONS => null], 'b');

    $r = $this->svc->network($this->user, $this->brand, Network::Instagram, Period::make('2026-10-01', '2026-10-10'));

    expect($r['kpis']['per_post']['interactions'])->toBe(10.0)->and($r['kpis']['per_post']['views'])->toBe(200.0);
});

it('Periodo y filtro de publicaciones en la zona horaria de la marca', function () {
    $this->brand->update(['timezone' => 'Pacific/Auckland']); // UTC+13 en noviembre-octubre
    $a = anaRvAccount($this->user, $this->brand);
    // 2026-10-10 20:00 UTC = 2026-10-11 09:00 en Auckland: pertenece al 11, no al 10.
    anaRvPost($a, '2026-10-10 20:00:00', [Metric::VIEWS => 1], 'borde');

    $on10 = $this->svc->network($this->user, $this->brand->fresh(), Network::Instagram, Period::make('2026-10-10', '2026-10-10'));
    $on11 = $this->svc->network($this->user, $this->brand->fresh(), Network::Instagram, Period::make('2026-10-11', '2026-10-11'));

    expect($on10['posts_total'])->toBe(0)->and($on11['posts_total'])->toBe(1);

    // "Ayer" en la zona de la marca seria el 1-nov en Auckland (12:00 UTC del 1-nov ya es 2-nov), pero ese dia aun no esta
    // recogido (el ultimo dia cerrado de Meta/YouTube, en el Pacifico, es el 31-oct): el periodo termina en el 31.
    expect(Period::make(null, null, 'Pacific/Auckland')->toArray()['to'])->toBe('2026-10-31')
        ->and(Period::make(null, null)->toArray()['to'])->toBe('2026-10-31')
        ->and(Period::make(null, null, 'Zona/Invalida')->toArray()['to'])->toBe('2026-10-31');
});

it('Comparacion con el ano anterior: misma longitud de periodo aunque haya 29 de febrero', function () {
    $p = Period::make('2028-02-20', '2028-03-05'); // 15 dias, con 29-feb
    $y = $p->comparison('year');

    expect($y->days())->toBe($p->days())->and($y->toArray()['from'])->toBe('2027-02-20');

    $leap = Period::make('2028-03-01', '2028-03-31')->comparison('year');
    expect($leap->days())->toBe(31);
});

it('network() solo lee los snapshots de la red pedida', function () {
    $ig = anaRvAccount($this->user, $this->brand);
    $yt = anaRvAccount($this->user, $this->brand, Network::YouTube);
    anaRvSnap($ig, '2026-10-02', [Metric::VIEWS => 5]);
    anaRvSnap($yt, '2026-10-02', [Metric::VIEWS => 7]);

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        if (str_contains($q->sql, 'account_snapshots')) {
            $queries[] = [$q->sql, $q->bindings];
        }
    });

    $r = $this->svc->network($this->user, $this->brand, Network::YouTube, Period::make('2026-10-01', '2026-10-10'));

    expect(collect($r['metrics'])->firstWhere('key', 'views')['total'])->toBe(7)
        ->and($queries)->toHaveCount(1)
        ->and($queries[0][1])->toContain('youtube');
});
