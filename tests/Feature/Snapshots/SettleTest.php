<?php

use App\Analytics\Days;
use App\Analytics\Metric;
use App\Analytics\SnapshotCollector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Google\Analytics;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\BackfillMetrics;
use App\Jobs\CollectAccountSnapshot;
use App\Jobs\CollectSnapshots;
use App\Jobs\RefreshPostMetrics;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAccountService;
use Carbon\CarbonInterface;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeInsights;

const SETTLE_YT_NAMES = ['views', 'estimatedMinutesWatched', 'likes', 'comments', 'shares', 'subscribersGained', 'subscribersLost'];

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.analytics.settle_days' => 3]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

function settleYouTube(User $user, Brand $brand): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => Network::YouTube, 'external_id' => 'UCx', 'access_token' => 't']);
}

function settleRun(SocialAccount $account, string $date, bool $onlyIfMissing = false): void
{
    (new CollectAccountSnapshot($account->id, $date, false, $onlyIfMissing))->handle(app(SnapshotCollector::class), app(SocialAccountService::class));
}

function settleFakeYouTube(array $rows, string $subs = '1520'): void
{
    Http::swap(new Factory);
    Http::fake([
        'youtubeanalytics.googleapis.com/*' => Http::response(['columnHeaders' => array_map(fn ($n) => ['name' => $n], SETTLE_YT_NAMES), 'rows' => $rows]),
        'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['statistics' => ['subscriberCount' => $subs, 'videoCount' => '48']]]]),
    ]);
}

it('Settle: un snapshot con solo stocks no esta completo y el reintento recoge los flujos de YouTube cuando llegan', function () {
    $yt = settleYouTube($this->user, $this->brand);

    // Dia 1: la Data API da suscriptores pero Analytics aun no tiene filas (latencia).
    settleFakeYouTube([]);
    settleRun($yt, '2026-10-31');
    $row = AccountSnapshot::withoutUserScope()->first();
    expect($row->metrics[Metric::FOLLOWERS])->toBe(1520)->and($row->metrics[Metric::VIEWS])->toBeNull()
        ->and($row->settled_at)->toBeNull(); // solo stocks = NO completo

    // Dias siguientes: los datos ya existen; el despachador reintenta (onlyIfMissing) y esta vez SI lee.
    Carbon::setTestNow('2026-11-02 12:00:00');
    settleFakeYouTube([[340, 1200, 25, 6, 3, 9, 2]], '1530');
    settleRun($yt, '2026-10-31', onlyIfMissing: true);

    $row = AccountSnapshot::withoutUserScope()->first();
    expect($row->metrics[Metric::VIEWS])->toBe(340)->and($row->metrics[Metric::FOLLOWERS_GAINED])->toBe(9)->and($row->metrics[Metric::INTERACTIONS])->toBe(34)
        ->and($row->settled_at)->not->toBeNull();

    // Ya asentado: otro reintento no vuelve a leer.
    Http::fake(fn () => throw new RuntimeException('no debe leerse'));
    settleRun($yt, '2026-10-31', onlyIfMissing: true);
});

it('Settle: pasada la ventana de reintentos el snapshot incompleto se da por asentado', function () {
    $yt = settleYouTube($this->user, $this->brand);
    settleFakeYouTube([]);

    settleRun($yt, '2026-10-31'); // ultimo cerrado: incompleto, pendiente
    settleRun($yt, '2026-10-28'); // 3 dias antes del ultimo cerrado: en el limite de la ventana -> asentado
    settleRun($yt, '2026-10-20'); // historico

    $rows = AccountSnapshot::withoutUserScope()->orderBy('date')->get()->keyBy(fn ($r) => substr((string) $r->date, 0, 10));
    expect($rows['2026-10-31']->settled_at)->toBeNull()
        ->and($rows['2026-10-28']->settled_at)->not->toBeNull()
        ->and($rows['2026-10-20']->settled_at)->not->toBeNull();
});

it('Settle: un snapshot completo de Instagram (flujos esperados) queda asentado y TikTok (solo stocks) tambien', function () {
    $ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $tt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok]);
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerInsights(Network::Instagram, new FakeInsights)->registerInsights(Network::TikTok, tap(new FakeInsights, fn ($f) => $f->account = [Metric::FOLLOWERS => 5])));

    settleRun($ig, '2026-10-31');
    settleRun($tt, '2026-10-31');

    expect(AccountSnapshot::withoutUserScope()->where('social_account_id', $ig->id)->first()->settled_at)->not->toBeNull()
        ->and(AccountSnapshot::withoutUserScope()->where('social_account_id', $tt->id)->first()->settled_at)->not->toBeNull();

    // Instagram con un flujo esperado en null: pendiente.
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerInsights(Network::Instagram, tap(new FakeInsights, fn ($f) => $f->account = [Metric::FOLLOWERS => 1, Metric::VIEWS => 3])));
    settleRun($ig, '2026-10-30');
    expect(AccountSnapshot::withoutUserScope()->where('social_account_id', $ig->id)->where('date', '2026-10-30')->first()->settled_at)->toBeNull();
});

it('Dia por red: el ultimo dia cerrado se calcula en el Pacifico, no en UTC', function () {
    // 02:00 UTC del 1-nov = 19:00 PDT del 31-oct: el 31-oct del Pacifico aun no ha cerrado.
    Carbon::setTestNow('2026-11-01 02:00:00');
    expect(Days::closed(Network::YouTube)->format('Y-m-d'))->toBe('2026-10-30')
        ->and(Days::closed(Network::Instagram)->format('Y-m-d'))->toBe('2026-10-30')
        ->and(Days::closed(Network::TikTok)->format('Y-m-d'))->toBe('2026-10-31');

    Carbon::setTestNow('2026-11-01 12:00:00');
    expect(Days::closed(Network::YouTube)->format('Y-m-d'))->toBe('2026-10-31');
});

it('Dia por red: el despachador encola el dia cerrado de cada red en su zona y la hora diaria deja cerrado el dia del Pacifico', function () {
    Bus::fake();
    Carbon::setTestNow('2026-11-01 02:00:00');
    $yt = settleYouTube($this->user, $this->brand);
    $tt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok]);

    (new CollectSnapshots)->handle();

    Bus::assertDispatched(CollectAccountSnapshot::class, fn ($j) => $j->accountId === $yt->id && $j->date === '2026-10-30' && ! $j->onlyIfMissing);
    Bus::assertDispatched(CollectAccountSnapshot::class, fn ($j) => $j->accountId === $tt->id && $j->date === '2026-10-31' && ! $j->onlyIfMissing);

    // La hora por defecto (UTC) es posterior al cierre del dia del Pacifico (07:00/08:00 UTC).
    expect(Carbon::parse('2026-11-01 '.config('social.analytics.daily_at'), 'UTC')->hour)->toBeGreaterThanOrEqual(9);
});

it('Presupuesto: las publicaciones del snapshot diario se leen en un job aparte, por bloques con continuacion', function () {
    $ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $slow = new class extends FakeInsights
    {
        public function postInsights(SocialAccount $account, string $externalId): array
        {
            usleep(600_000);

            return parent::postInsights($account, $externalId);
        }
    };
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerInsights(Network::Instagram, $slow));

    foreach (range(1, 4) as $i) {
        $post = Post::factory()->for($this->user)->create(['brand_id' => $this->brand->id]);
        PostTarget::factory()->create([
            'post_id' => $post->id, 'social_account_id' => $ig->id, 'network' => Network::Instagram, 'status' => PostStatus::Published,
            'external_id' => "ext-$i", 'published_at' => now()->subHours($i),
        ]);
    }

    // El job de cuenta ya no lee publicaciones: solo encola el job por bloques.
    Bus::fake([RefreshPostMetrics::class]);
    (new CollectAccountSnapshot($ig->id, '2026-10-31', true))->handle(app(SnapshotCollector::class), app(SocialAccountService::class));
    Bus::assertDispatched(RefreshPostMetrics::class, fn ($j) => $j->accountId === $ig->id && $j->hours === 720 && $j->offset === 0);
    expect($slow->postCalls)->toBe(0);

    // El job por bloques respeta el presupuesto y se encadena con el desplazamiento por donde iba.
    (new UniqueLock(app(Repository::class)))->release(new RefreshPostMetrics($ig->id, 720)); // el candado unico se libera al EMPEZAR a procesar (UntilProcessing)
    config(['social.analytics.posts_budget_seconds' => 1.5]);
    (new RefreshPostMetrics($ig->id, 720, 0))->handle(app(SnapshotCollector::class), app(SocialAccountService::class));

    expect($slow->postCalls)->toBe(2)->and(PostMetricSnapshot::withoutUserScope()->count())->toBe(2);
    Bus::assertDispatched(RefreshPostMetrics::class, fn ($j) => $j->offset === 2 && $j->hours === 720);

    // Sin presion de tiempo, el ultimo bloque termina y no encadena mas.
    config(['social.analytics.posts_budget_seconds' => 60]);
    (new UniqueLock(app(Repository::class)))->release(new RefreshPostMetrics($ig->id, 720));
    Bus::fake([RefreshPostMetrics::class]);
    (new RefreshPostMetrics($ig->id, 720, 2))->handle(app(SnapshotCollector::class), app(SocialAccountService::class));
    expect(PostMetricSnapshot::withoutUserScope()->count())->toBe(4);
    Bus::assertNotDispatched(RefreshPostMetrics::class);
});

it('Presupuesto: los jobs de analitica tienen timeout menor que el del worker y los continuables liberan el candado al empezar', function () {
    foreach ([new CollectAccountSnapshot(1, '2026-10-31'), new RefreshPostMetrics(1), new BackfillMetrics(1), new CollectSnapshots] as $job) {
        expect($job->timeout)->toBeLessThan(120);
    }

    expect(new RefreshPostMetrics(1))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and((new RefreshPostMetrics(1, 720))->uniqueId())->not->toBe((new RefreshPostMetrics(1))->uniqueId())
        ->and((int) config('social.analytics.posts_budget_seconds'))->toBeLessThan((new RefreshPostMetrics(1))->timeout);
});

it('Backfill por rango: YouTube se carga con UN informe por tramo en vez de uno por dia', function () {
    config(['social.analytics.backfill_days.youtube' => 40]);
    $yt = settleYouTube($this->user, $this->brand);
    $rows = [];

    for ($d = 0; $d < 40; $d++) {
        $rows[] = array_merge([Carbon::parse('2026-10-31')->subDays($d)->format('Y-m-d')], [10 + $d, 100, 1, 1, 1, 2, 1]);
    }

    Http::fake([
        'youtubeanalytics.googleapis.com/*' => Http::response(['columnHeaders' => array_map(fn ($n) => ['name' => $n], array_merge(['day'], SETTLE_YT_NAMES)), 'rows' => $rows]),
        'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['statistics' => ['subscriberCount' => '9']]]]),
    ]);
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerInsights(Network::YouTube, new Analytics));

    (new BackfillMetrics($yt->id))->handle(app(SnapshotCollector::class), app(SocialAccountService::class));

    $reports = Http::recorded(fn ($req) => str_contains($req->url(), 'youtubeanalytics'))->values();
    // 3 dias recientes uno a uno + 1 tramo de 37 dias (< 90) = 4 informes, no 40.
    expect($reports)->toHaveCount(4)->and(AccountSnapshot::withoutUserScope()->count())->toBe(40);

    $old = AccountSnapshot::withoutUserScope()->where('date', '2026-09-30')->first();
    expect($old->metrics[Metric::VIEWS])->toBe(10 + 31)->and($old->metrics[Metric::FOLLOWERS])->toBeNull()->and($old->settled_at)->not->toBeNull();

    $query = [];
    parse_str(parse_url($reports[3][0]->url(), PHP_URL_QUERY), $query);
    expect($query['dimensions'])->toBe('day');
});

it('Backfill por rango: el presupuesto se comprueba antes de cada unidad con la duracion de la mas lenta', function () {
    config(['social.analytics.backfill_days.instagram' => 6, 'social.analytics.backfill_budget_seconds' => 1]);
    Bus::fake([BackfillMetrics::class]);
    $ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $slow = new class extends FakeInsights
    {
        public function accountInsights(SocialAccount $account, CarbonInterface $day): array
        {
            usleep(600_000);

            return parent::accountInsights($account, $day);
        }
    };
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerInsights(Network::Instagram, $slow));

    (new BackfillMetrics($ig->id))->handle(app(SnapshotCollector::class), app(SocialAccountService::class));

    // Cada dia cuesta 0,6 s: tras uno, 0,6 + 0,6 > 1 s -> no empieza otro y se reencola desde el dia 1.
    expect($slow->accountCalls)->toBe(1);
    Bus::assertDispatched(BackfillMetrics::class, fn ($j) => $j->offset === 1);
});
