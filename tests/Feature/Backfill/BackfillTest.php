<?php

use App\Analytics\Metric;
use App\Analytics\SnapshotCollector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\BackfillMetrics;
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
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\Support\FakeInsights;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.analytics.backfill_days.instagram' => 5]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->fake = new FakeInsights;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerInsights(Network::Instagram, $this->fake));
});

function runBackfill(SocialAccount $account, int $offset = 0): void
{
    (new BackfillMetrics($account->id, $offset))->handle(app(SnapshotCollector::class), app(SocialAccountService::class));
}

it('Backfill: carga el maximo historico de la red hacia atras desde ayer, un snapshot por dia', function () {
    runBackfill($this->account);

    $dates = AccountSnapshot::withoutUserScope()->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all();

    expect($dates)->toBe(['2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31']);
});

it('Backfill: es idempotente y no vuelve a pedir los dias que ya tienen datos', function () {
    runBackfill($this->account);
    $calls = $this->fake->accountCalls;

    runBackfill($this->account);

    expect($this->fake->accountCalls)->toBe($calls)->and(AccountSnapshot::withoutUserScope()->count())->toBe(5);
});

it('Backfill: sin presupuesto de tiempo se reencola con el desplazamiento por donde iba', function () {
    Bus::fake();
    config(['social.analytics.backfill_budget_seconds' => 1]);
    $slow = new class extends FakeInsights
    {
        public function accountInsights(SocialAccount $account, CarbonInterface $day): array
        {
            usleep(1_100_000);

            return parent::accountInsights($account, $day);
        }
    };
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerInsights(Network::Instagram, $slow));

    runBackfill($this->account);

    expect($slow->accountCalls)->toBe(1);
    Bus::assertDispatched(BackfillMetrics::class, fn ($j) => $j->accountId === $this->account->id && $j->offset === 1);
});

it('Backfill: sin dias configurados (TikTok) no hace nada', function () {
    config(['social.analytics.backfill_days.instagram' => 0]);

    runBackfill($this->account);

    expect($this->fake->accountCalls)->toBe(0);
});

it('Backfill: un fallo transitorio no reencola la continuacion (lo reintenta la cola)', function () {
    Bus::fake();
    $this->fake->error = new TransientPublishException('red', 'red');

    runBackfill($this->account);

    Bus::assertNotDispatched(BackfillMetrics::class);
    expect(AccountSnapshot::withoutUserScope()->count())->toBe(0);
});

it('Backfill: el job es unico por cuenta hasta que empieza, va a metrics y su timeout es menor que el del worker', function () {
    $job = new BackfillMetrics(9);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)->and($job->uniqueId())->toBe('9')
        ->and($job->queue)->toBe('metrics')->and($job->timeout)->toBeLessThan(120);
});

it('Backfill: conectar una cuenta encola el historico inicial', function () {
    Bus::fake();
    config(['social.analytics.backfill_on_connect' => true]);

    $account = app(SocialAccountService::class)->connect($this->user, $this->brand, new ConnectionCandidate(
        network: Network::Instagram, externalId: 'nuevo-1', name: 'Nueva', username: 'nueva', accessToken: 'tok', refreshToken: null, tokenExpiresAt: null,
    ));

    Bus::assertDispatched(BackfillMetrics::class, fn ($j) => $j->accountId === $account->id);
});

function recentTarget(SocialAccount $account, string $externalId, string $publishedAt, PostStatus $status = PostStatus::Published): PostTarget
{
    $post = Post::factory()->for($account->user)->create(['brand_id' => $account->brand_id]);

    return PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $account->id, 'network' => $account->network,
        'status' => $status, 'external_id' => $externalId, 'published_at' => $publishedAt,
    ]);
}

it('Backfill: el refresco de publicaciones lee solo las de las ultimas 72 h y es idempotente por dia', function () {
    $new = recentTarget($this->account, 'nueva', '2026-10-31 10:00:00');
    recentTarget($this->account, 'vieja', '2026-10-20 10:00:00');
    recentTarget($this->account, 'fallida', '2026-10-31 10:00:00', PostStatus::Failed);

    foreach ([1, 2] as $_) {
        (new RefreshPostMetrics($this->account->id))->handle(app(SnapshotCollector::class), app(SocialAccountService::class));
    }

    expect(PostMetricSnapshot::withoutUserScope()->count())->toBe(1)
        ->and(PostMetricSnapshot::withoutUserScope()->first()->post_target_id)->toBe($new->id)
        ->and(PostMetricSnapshot::withoutUserScope()->first()->metrics[Metric::VIEWS])->toBe(10);
});

it('Backfill: el despachador del refresco solo encola cuentas activas con publicaciones recientes', function () {
    Bus::fake();
    recentTarget($this->account, 'nueva', '2026-10-31 10:00:00');
    $idle = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $off = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::YouTube, 'status' => SocialAccount::STATUS_DISCONNECTED]);
    recentTarget($off, 'v', '2026-10-31 10:00:00');

    $n = RefreshPostMetrics::dispatchDue();

    expect($n)->toBe(1);
    Bus::assertDispatched(RefreshPostMetrics::class, fn ($j) => $j->accountId === $this->account->id);
    Bus::assertNotDispatched(RefreshPostMetrics::class, fn ($j) => $j->accountId === $idle->id || $j->accountId === $off->id);
});

it('Backfill: el refresco esta planificado y su job es unico y de timeout acotado', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description);
    $job = new RefreshPostMetrics(3);

    expect($events)->toContain('refresh-post-metrics')
        ->and($job->uniqueId())->toBe('3')->and($job->queue)->toBe('metrics')->and($job->timeout)->toBeLessThan(120);
});
