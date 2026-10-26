<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\CampaignAnalytics;
use Illuminate\Support\Carbon;

// Panel de campaña (C-03, CA-11): suma de los ULTIMOS snapshots de cada destino publicado; «sin datos» (null) no es 0.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->campaign = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id]);
    $this->svc = app(CampaignAnalytics::class);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
});

function campPost(?Campaign $campaign = null, array $over = []): Post
{
    return Post::factory()->create(array_merge(['brand_id' => test()->brand->id, 'user_id' => test()->user->id, 'campaign_id' => $campaign?->id], $over));
}

function campTarget(Post $post, SocialAccount $account, array $snaps = [], PostStatus $status = PostStatus::Published): PostTarget
{
    $t = PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $account->id, 'network' => $account->network,
        'status' => $status, 'external_id' => 'e'.$post->id.$account->id, 'published_at' => '2026-10-03 10:00:00',
    ]);
    foreach ($snaps as $date => $metrics) {
        PostMetricSnapshot::withoutUserScope()->create(['user_id' => test()->user->id, 'post_target_id' => $t->id, 'social_account_id' => $account->id,
            'network' => $account->network->value, 'date' => $date, 'metrics' => $metrics]);
    }

    return $t;
}

test('CampaignAnalytics: los KPIs suman el ultimo snapshot de cada destino (CA-11)', function () {
    $post = campPost($this->campaign);
    campTarget($post, $this->ig, [
        '2026-10-04' => [Metric::VIEWS => 10, Metric::REACH => 8, Metric::LIKES => 1, Metric::INTERACTIONS => 2],
        '2026-10-09' => [Metric::VIEWS => 100, Metric::REACH => 80, Metric::LIKES => 10, Metric::COMMENTS => 2, Metric::SHARES => 1, Metric::SAVED => 3, Metric::INTERACTIONS => 16],
    ]);
    campTarget($post, $this->fb, ['2026-10-08' => [Metric::VIEWS => 50, Metric::REACH => 40, Metric::LIKES => 4, Metric::INTERACTIONS => 5]]);

    $r = $this->svc->summary($this->user, $this->campaign);

    expect($r['kpis'])->toMatchArray([
        'posts' => 1, 'targets' => 2, 'views' => 150, 'reach' => 120, 'interactions' => 21, 'likes' => 14, 'comments' => 2, 'shares' => 1, 'saved' => 3,
        'engagement_rate' => 14.0,
    ]);
});

test('CampaignAnalytics: una metrica sin datos es null y no 0, tambien sin ningun snapshot', function () {
    $post = campPost($this->campaign);
    campTarget($post, $this->ig, ['2026-10-09' => [Metric::VIEWS => 40, Metric::INTERACTIONS => null]]);
    campTarget($post, $this->fb); // sin snapshots

    $r = $this->svc->summary($this->user, $this->campaign);

    expect($r['kpis']['views'])->toBe(40)->and($r['kpis']['interactions'])->toBeNull()->and($r['kpis']['reach'])->toBeNull()
        ->and($r['kpis']['engagement_rate'])->toBeNull()->and($r['kpis']['targets'])->toBe(2);

    $empty = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id]);
    $none = $this->svc->summary($this->user, $empty);
    expect($none['kpis']['posts'])->toBe(0)->and($none['kpis']['views'])->toBeNull()->and($none['by_network'])->toBe([])->and($none['daily'])->toBe([])->and($none['posts'])->toBe([]);
});

test('CampaignAnalytics: desglose por red y lista de posts con sus destinos', function () {
    $post = campPost($this->campaign, ['text' => 'Campaña de otoño']);
    campTarget($post, $this->ig, ['2026-10-09' => [Metric::VIEWS => 100, Metric::INTERACTIONS => 20]]);
    campTarget($post, $this->fb, ['2026-10-09' => [Metric::VIEWS => 50, Metric::INTERACTIONS => null]]);

    $r = $this->svc->summary($this->user, $this->campaign);
    $by = collect($r['by_network'])->keyBy('network');

    expect($by['instagram'])->toMatchArray(['views' => 100, 'interactions' => 20, 'targets' => 1, 'engagement_rate' => 20.0])
        ->and($by['facebook']['interactions'])->toBeNull()
        ->and($r['posts'])->toHaveCount(1)
        ->and($r['posts'][0]['id'])->toBe($post->id)
        ->and($r['posts'][0]['targets'])->toHaveCount(2)
        ->and(collect($r['posts'][0]['targets'])->firstWhere('network', 'facebook')['has_data'])->toBeTrue();
});

test('CampaignAnalytics: solo cuentan los destinos publicados de los posts de la campaña', function () {
    campTarget(campPost($this->campaign), $this->ig, ['2026-10-09' => [Metric::VIEWS => 10]]);
    campTarget(campPost($this->campaign), $this->ig, ['2026-10-09' => [Metric::VIEWS => 1000]], PostStatus::Failed);
    campTarget(campPost(null), $this->ig, ['2026-10-09' => [Metric::VIEWS => 5000]]); // otro post, sin campaña

    $r = $this->svc->summary($this->user, $this->campaign);

    expect($r['kpis']['posts'])->toBe(2)->and($r['kpis']['targets'])->toBe(1)->and($r['kpis']['views'])->toBe(10);
});

test('CampaignAnalytics: serie diaria = suma de los snapshots de cada dia, sin arrastrar valores ni convertir null en 0', function () {
    $post = campPost($this->campaign);
    campTarget($post, $this->ig, ['2026-10-04' => [Metric::VIEWS => 10, Metric::INTERACTIONS => 1], '2026-10-06' => [Metric::VIEWS => 30, Metric::INTERACTIONS => 3]]);
    campTarget($post, $this->fb, ['2026-10-05' => [Metric::VIEWS => 5, Metric::INTERACTIONS => null]]);

    $daily = collect($this->svc->summary($this->user, $this->campaign)['daily'])->keyBy('date');

    expect($daily->keys()->all())->toBe(['2026-10-04', '2026-10-05', '2026-10-06'])
        ->and($daily['2026-10-04'])->toMatchArray(['views' => 10, 'interactions' => 1])
        ->and($daily['2026-10-05']['views'])->toBe(5)->and($daily['2026-10-05']['interactions'])->toBeNull()
        ->and($daily['2026-10-06'])->toMatchArray(['views' => 30, 'interactions' => 3])
        ->and($daily['2026-10-04']['reach'])->toBeNull();
});

test('CampaignAnalytics: no lee datos de otro usuario', function () {
    $other = User::factory()->create();

    $r = $this->svc->summary($other, $this->campaign);

    expect($r['kpis']['posts'])->toBe(0)->and($r['posts'])->toBe([]);
});

test('withLatestSnapshot acota la subconsulta lm a los destinos del conjunto y no cambia el resultado', function () {
    $post = campPost($this->campaign);
    campTarget($post, $this->ig, ['2026-10-04' => [Metric::VIEWS => 10], '2026-10-09' => [Metric::VIEWS => 100]]);
    // Snapshots de OTRO destino (fuera de la campaña): no deben entrar en `lm` ni alterar la lectura.
    campTarget(campPost(null), $this->ig, ['2026-10-20' => [Metric::VIEWS => 9999]]);

    DB::enableQueryLog();
    $r = $this->svc->summary($this->user, $this->campaign);
    $sql = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'last_date'));
    DB::disableQueryLog();

    expect($r['kpis']['views'])->toBe(100)
        ->and($sql)->toContain('"post_target_id" in (select "post_targets"."id"');
});

test('CampaignAnalytics: la lista se recorta a MAX_POSTS con el total, los KPIs cuentan todos y el texto no se carga entero', function () {
    $max = CampaignAnalytics::MAX_POSTS;
    for ($i = 0; $i < $max + 2; $i++) {
        campTarget(campPost($this->campaign, ['text' => str_repeat('é', 500)]), $this->ig, ['2026-10-09' => [Metric::VIEWS => 1]]);
    }

    $r = $this->svc->summary($this->user, $this->campaign);

    expect($r['posts'])->toHaveCount($max)->and($r['posts_total'])->toBe($max + 2)->and($r['posts_limit'])->toBe($max)->and($r['posts_truncated'])->toBeTrue()
        ->and($r['kpis']['posts'])->toBe($max + 2)->and($r['kpis']['views'])->toBe($max + 2)
        ->and(mb_strlen($r['posts'][0]['text']))->toBeLessThanOrEqual(140);
});

test('CampaignAnalytics: fecha en la zona de la marca y estado con etiqueta en español', function () {
    $this->brand->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    $post = campPost($this->campaign, ['scheduled_at' => '2026-10-10 20:30:00', 'status' => PostStatus::Published]);
    campTarget($post, $this->ig, ['2026-10-11' => [Metric::VIEWS => 1]]);

    $p = $this->svc->summary($this->user, $this->campaign)['posts'][0];

    expect($p['scheduled_local'])->toBe('2026-10-11 05:30')->and($p['timezone'])->toBe('Asia/Tokyo')
        ->and($p['status_label'])->toBe(PostStatus::Published->label())->and($p['targets'][0]['status_label'])->toBe(PostStatus::Published->label());
});
