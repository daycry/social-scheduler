<?php

use App\Analytics\SnapshotCollector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\BackfillMetrics;
use App\Jobs\RefreshPostMetrics;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAccountService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\Support\FakeInsights;

// T-21 (G1): una cuota agotada (429 de Pinterest, limites de Meta/LinkedIn) NO es un fallo definitivo: el job se libera con backoff y respeta
// `Retry-After`, en el backfill y en el refresco de publicaciones, para todas las redes que comparten GuardsInsightsErrors.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.analytics.backfill_days.instagram' => 5, 'social.analytics.settle_days' => 3]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->fake = new FakeInsights;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerInsights(Network::Instagram, $this->fake));
});

function quotaJob(object $job, int $attempts, ?int $expectDelay): object
{
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempts);
    $expectDelay === null ? $queueJob->shouldNotReceive('release') : $queueJob->shouldReceive('release')->once()->with($expectDelay);
    $job->setJob($queueJob);

    return $job;
}

it('Backfill: una cuota agotada libera el job con backoff, sin reencolar la continuacion ni marcar la cuenta', function () {
    Bus::fake();
    $this->fake->error = new QuotaExceededException('limite', null, 'rate_limit');

    $job = quotaJob(new BackfillMetrics($this->account->id), 1, 300);
    $job->handle(app(SnapshotCollector::class), app(SocialAccountService::class));

    Bus::assertNotDispatched(BackfillMetrics::class);
    expect(AccountSnapshot::withoutUserScope()->count())->toBe(0)->and($this->account->fresh()->status)->toBe(SocialAccount::STATUS_ACTIVE);
});

it('Backfill: el backoff crece con los intentos y respeta Retry-After (el mayor de los dos, con tope de una hora)', function () {
    $this->fake->error = new QuotaExceededException('limite', now()->addSeconds(900), 'rate_limit');
    quotaJob(new BackfillMetrics($this->account->id), 1, 900)->handle(app(SnapshotCollector::class), app(SocialAccountService::class));

    $this->fake->error = new QuotaExceededException('limite', null, 'rate_limit');
    quotaJob(new BackfillMetrics($this->account->id), 3, 1200)->handle(app(SnapshotCollector::class), app(SocialAccountService::class));

    $this->fake->error = new QuotaExceededException('limite', now()->addDay(), 'cuota');
    quotaJob(new BackfillMetrics($this->account->id), 1, 3600)->handle(app(SnapshotCollector::class), app(SocialAccountService::class));
});

it('RefreshPostMetrics: una cuota agotada libera el job y no lee mas pines', function () {
    Bus::fake([RefreshPostMetrics::class]);
    $post = Post::factory()->for($this->user)->create(['brand_id' => $this->brand->id]);
    PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $this->account->id, 'network' => Network::Instagram, 'status' => PostStatus::Published,
        'external_id' => 'ext-1', 'published_at' => now()->subHours(2),
    ]);
    $this->fake->error = new QuotaExceededException('limite', null, 'rate_limit');

    $job = quotaJob(new RefreshPostMetrics($this->account->id, 72), 1, 300);
    $job->handle(app(SnapshotCollector::class), app(SocialAccountService::class));

    Bus::assertNotDispatched(RefreshPostMetrics::class);
    expect($this->fake->postCalls)->toBe(1);
});
