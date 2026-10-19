<?php

use App\Analytics\Metric;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\GraphClient;
use App\Connectors\Meta\Insights;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const INS_PAGE_ID = '1065269806670394';
const INS_IG_ID = '17841400000000001';

/** Graph simulada que enruta por ruta + parametro `metric`; lo no declarado responde #100 (metrica no valida). */
function fakeMetaInsights(array $responses, array &$calls): void
{
    Http::fake(function (Request $request) use ($responses, &$calls) {
        $url = parse_url($request->url());
        $path = ltrim(preg_replace('#^/v[\d.]+/#', '', $url['path'] ?? ''), '/');
        parse_str($url['query'] ?? '', $q);
        $calls[] = ['path' => $path, 'q' => $q, 'auth' => $request->header('Authorization')[0] ?? null];
        $key = $path.'|'.($q['metric'] ?? ($q['fields'] ?? ''));

        if (array_key_exists($key, $responses)) {
            $r = $responses[$key];

            return $r instanceof Throwable ? throw $r : Http::response($r['body'] ?? $r, $r['status'] ?? 200);
        }

        return Http::response(['error' => ['code' => 100, 'message' => '(#100) must be a valid insights metric']], 400);
    });
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.meta.graph_version' => 'v23.0']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->reader = new Insights(new GraphClient);
    $this->ig = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => INS_IG_ID, 'access_token' => 'page-token',
    ]);
    $this->fb = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Facebook, 'external_id' => INS_PAGE_ID, 'access_token' => 'page-token',
    ]);
});

it('MetaInsights: Instagram usa views (no impressions) con total_value y follower_count como serie', function () {
    $calls = [];
    $tv = fn ($name, $v) => ['data' => [['name' => $name, 'total_value' => ['value' => $v]]]];
    fakeMetaInsights([
        INS_IG_ID.'/insights|views' => $tv('views', 1200),
        INS_IG_ID.'/insights|reach' => $tv('reach', 800),
        INS_IG_ID.'/insights|profile_views' => $tv('profile_views', 40),
        INS_IG_ID.'/insights|accounts_engaged' => $tv('accounts_engaged', 55),
        INS_IG_ID.'/insights|follower_count' => ['data' => [['name' => 'follower_count', 'values' => [['value' => 7, 'end_time' => '2026-10-31T07:00:00+0000']]]]],
        INS_IG_ID.'|followers_count' => ['followers_count' => 4321, 'id' => INS_IG_ID],
    ], $calls);

    $m = $this->reader->accountInsights($this->ig, Carbon::parse('2026-10-31'));

    expect($m)->toHaveKeys(Metric::ACCOUNT)
        ->and($m[Metric::VIEWS])->toBe(1200)->and($m[Metric::REACH])->toBe(800)
        ->and($m[Metric::PROFILE_VIEWS])->toBe(40)->and($m[Metric::ACCOUNTS_ENGAGED])->toBe(55)
        ->and($m[Metric::FOLLOWERS_GAINED])->toBe(7)->and($m[Metric::FOLLOWERS])->toBe(4321)
        ->and($m[Metric::INTERACTIONS])->toBeNull()->and($m[Metric::WATCH_MINUTES])->toBeNull();

    $metrics = array_column(array_column($calls, 'q'), 'metric');
    expect($metrics)->not->toContain('impressions');
    $views = collect($calls)->firstWhere(fn ($c) => ($c['q']['metric'] ?? '') === 'views');
    expect($views['q']['metric_type'])->toBe('total_value')->and($views['q']['since'])->toBe('2026-10-31')->and($views['q']['until'])->toBe('2026-11-01')
        ->and($views['auth'])->toBe('Bearer page-token');
    $follower = collect($calls)->firstWhere(fn ($c) => ($c['q']['metric'] ?? '') === 'follower_count');
    expect($follower['q'])->not->toHaveKey('metric_type');
});

it('MetaInsights: una metrica rechazada por Meta se guarda como null y no como 0', function () {
    $calls = [];
    fakeMetaInsights([
        INS_IG_ID.'/insights|views' => ['data' => [['name' => 'views', 'total_value' => ['value' => 0]]]],
        // reach, profile_views, accounts_engaged, follower_count -> #100
    ], $calls);

    $m = $this->reader->accountInsights($this->ig, Carbon::parse('2026-10-31'));

    expect($m[Metric::VIEWS])->toBe(0)             // un 0 real sigue siendo 0
        ->and($m[Metric::REACH])->toBeNull()        // rechazada = no disponible
        ->and($m[Metric::PROFILE_VIEWS])->toBeNull()
        ->and($m[Metric::FOLLOWERS_GAINED])->toBeNull()
        ->and($m[Metric::FOLLOWERS])->toBeNull();
});

it('MetaInsights: Facebook usa page_media_view, page_post_engagements y page_follows (no las retiradas)', function () {
    $calls = [];
    $series = fn ($name, $v) => ['data' => [['name' => $name, 'period' => 'day', 'values' => [['value' => $v, 'end_time' => '2026-11-01T08:00:00+0000']]]]];
    fakeMetaInsights([
        INS_PAGE_ID.'/insights|page_media_view' => $series('page_media_view', 900),
        INS_PAGE_ID.'/insights|page_post_engagements' => $series('page_post_engagements', 33),
        INS_PAGE_ID.'/insights|page_follows' => $series('page_follows', 2500),
    ], $calls);

    $m = $this->reader->accountInsights($this->fb, Carbon::parse('2026-10-31'));

    expect($m[Metric::VIEWS])->toBe(900)->and($m[Metric::INTERACTIONS])->toBe(33)->and($m[Metric::FOLLOWERS])->toBe(2500)
        ->and($m[Metric::REACH])->toBeNull()->and($m[Metric::PROFILE_VIEWS])->toBeNull();

    $metrics = array_column(array_column($calls, 'q'), 'metric');
    expect($metrics)->not->toContain('page_impressions')->not->toContain('page_fans');
});

it('MetaInsights: usa el page_id de la cuenta de Facebook cuando difiere del external_id', function () {
    $calls = [];
    $this->fb->update(['meta' => ['page_id' => '999']]);
    fakeMetaInsights(['999/insights|page_media_view' => ['data' => [['values' => [['value' => 5]]]]]], $calls);

    $m = $this->reader->accountInsights($this->fb->fresh(), Carbon::parse('2026-10-31'));

    expect($m[Metric::VIEWS])->toBe(5)->and($calls[0]['path'])->toBe('999/insights');
});

it('MetaInsights: los seguidores actuales solo se piden para dias recientes', function () {
    $calls = [];
    fakeMetaInsights([INS_IG_ID.'|followers_count' => ['followers_count' => 10]], $calls);

    $old = $this->reader->accountInsights($this->ig, Carbon::parse('2026-10-10'));

    expect($old[Metric::FOLLOWERS])->toBeNull();
    expect(collect($calls)->pluck('q.fields')->filter()->all())->toBe([]);
});

it('MetaInsights: credenciales rechazadas se propagan al job', function () {
    $calls = [];
    fakeMetaInsights([INS_IG_ID.'/insights|views' => ['status' => 400, 'body' => ['error' => ['code' => 190, 'message' => 'token caducado']]]], $calls);

    expect(fn () => $this->reader->accountInsights($this->ig, Carbon::parse('2026-10-31')))->toThrow(ReconnectRequiredException::class);
});

it('MetaInsights: un fallo transitorio se propaga al job', function () {
    $calls = [];
    fakeMetaInsights([INS_IG_ID.'/insights|views' => ['status' => 500, 'body' => ['error' => ['code' => 2, 'message' => 'x']]]], $calls);

    expect(fn () => $this->reader->accountInsights($this->ig, Carbon::parse('2026-10-31')))->toThrow(TransientPublishException::class);
});

it('MetaInsights: metricas de una media de Instagram', function () {
    $calls = [];
    $rows = [];
    foreach (['views' => 300, 'reach' => 250, 'likes' => 20, 'comments' => 4, 'shares' => 2, 'saved' => 6, 'total_interactions' => 32] as $n => $v) {
        $rows[] = ['name' => $n, 'values' => [['value' => $v]]];
    }
    fakeMetaInsights(['18099448502453789/insights|views,reach,likes,comments,shares,saved,total_interactions' => ['data' => $rows]], $calls);

    $m = $this->reader->postInsights($this->ig, '18099448502453789');

    expect($m)->toBe([
        Metric::VIEWS => 300, Metric::REACH => 250, Metric::LIKES => 20, Metric::COMMENTS => 4, Metric::SHARES => 2,
        Metric::SAVED => 6, Metric::INTERACTIONS => 32, Metric::WATCH_MINUTES => null,
    ]);
    expect($calls)->toHaveCount(1);
});

it('MetaInsights: si Meta rechaza la peticion conjunta de una media se piden una a una y lo rechazado queda null', function () {
    $calls = [];
    fakeMetaInsights([
        '18099/insights|reach' => ['data' => [['name' => 'reach', 'values' => [['value' => 11]]]]],
        '18099/insights|likes' => ['data' => [['name' => 'likes', 'values' => [['value' => 0]]]]],
    ], $calls);

    $m = $this->reader->postInsights($this->ig, '18099');

    expect($m[Metric::REACH])->toBe(11)->and($m[Metric::LIKES])->toBe(0)->and($m[Metric::VIEWS])->toBeNull()->and($m[Metric::SAVED])->toBeNull();
});

it('MetaInsights: publicacion de Facebook con reacciones, comentarios y compartidos', function () {
    $calls = [];
    fakeMetaInsights([
        INS_PAGE_ID.'_55|reactions.summary(true).limit(0),comments.summary(true).limit(0),shares' => [
            'reactions' => ['summary' => ['total_count' => 12]], 'comments' => ['summary' => ['total_count' => 3]],
        ],
        INS_PAGE_ID.'_55/insights|post_media_view' => ['data' => [['values' => [['value' => 420]]]]],
    ], $calls);

    $m = $this->reader->postInsights($this->fb, INS_PAGE_ID.'_55');

    expect($m[Metric::LIKES])->toBe(12)->and($m[Metric::COMMENTS])->toBe(3)->and($m[Metric::SHARES])->toBe(0)
        ->and($m[Metric::INTERACTIONS])->toBe(15)->and($m[Metric::VIEWS])->toBe(420)->and($m[Metric::REACH])->toBeNull();
});

it('MetaInsights: sin ID externo el destino no se puede leer', function () {
    $post = Post::factory()->for($this->user)->create(['brand_id' => $this->brand->id]);
    $t = PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $this->ig->id, 'external_id' => null]);

    expect($this->reader->canReadPost($t))->toBeFalse();
    $t->external_id = '123';
    expect($this->reader->canReadPost($t))->toBeTrue();
});
