<?php

use App\Analytics\Metric;
use App\Analytics\SnapshotCollector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\CollectAccountSnapshot;
use App\Jobs\CollectSnapshots;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\ReconnectRequired;
use App\Services\SocialAccountService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeInsights;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->fake = new FakeInsights;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerInsights(Network::Instagram, $this->fake));
});

function runSnapshot(SocialAccount $account, string $date, bool $posts = false, bool $onlyIfMissing = false): void
{
    (new CollectAccountSnapshot($account->id, $date, $posts, $onlyIfMissing))
        ->handle(app(SnapshotCollector::class), app(SocialAccountService::class));
}

function publishedTarget(SocialAccount $account, array $attrs = []): PostTarget
{
    $post = Post::factory()->for($account->user)->create(['brand_id' => $account->brand_id]);

    return PostTarget::factory()->create(array_merge([
        'post_id' => $post->id, 'social_account_id' => $account->id, 'network' => $account->network,
        'status' => PostStatus::Published, 'external_id' => 'ext-1', 'published_at' => now()->subHours(5),
    ], $attrs));
}

it('guarda un snapshot por cuenta y dia de forma idempotente (upsert)', function () {
    runSnapshot($this->account, '2026-10-31');
    runSnapshot($this->account, '2026-10-31');

    expect(AccountSnapshot::withoutUserScope()->count())->toBe(1);

    $snap = AccountSnapshot::withoutUserScope()->first();
    expect($snap->metrics[Metric::FOLLOWERS])->toBe(100)
        ->and($snap->metrics[Metric::REACH])->toBe(30)
        ->and($snap->metrics[Metric::LIKES])->toBeNull() // no disponible = null, nunca 0
        ->and($snap->user_id)->toBe($this->user->id)
        ->and($snap->brand_id)->toBe($this->brand->id)
        ->and(substr((string) $snap->date, 0, 10))->toBe('2026-10-31');
});

it('un valor posterior actualiza el snapshot y un null posterior no borra lo ya guardado', function () {
    runSnapshot($this->account, '2026-10-31');

    $this->fake->account = [Metric::FOLLOWERS => 120, Metric::VIEWS => null];
    runSnapshot($this->account, '2026-10-31');

    $m = AccountSnapshot::withoutUserScope()->first()->metrics;
    expect($m[Metric::FOLLOWERS])->toBe(120)->and($m[Metric::VIEWS])->toBe(50);
});

it('guarda las metricas de las publicaciones recientes con un snapshot por destino y dia', function () {
    $recent = publishedTarget($this->account);
    publishedTarget($this->account, ['external_id' => 'viejo', 'published_at' => now()->subDays(60)]);
    publishedTarget($this->account, ['external_id' => null]);
    publishedTarget($this->account, ['status' => PostStatus::Failed]);

    runSnapshot($this->account, '2026-10-31', posts: true);
    runSnapshot($this->account, '2026-10-31', posts: true);

    expect(PostMetricSnapshot::withoutUserScope()->count())->toBe(1);
    $snap = PostMetricSnapshot::withoutUserScope()->first();
    expect($snap->post_target_id)->toBe($recent->id)
        ->and($snap->metrics[Metric::VIEWS])->toBe(10)
        ->and($snap->metrics[Metric::SAVED])->toBeNull()
        ->and(substr((string) $snap->date, 0, 10))->toBe('2026-11-01');
});

it('no lee destinos que el lector no puede consultar', function () {
    publishedTarget($this->account);
    $this->fake->readable = false;

    runSnapshot($this->account, '2026-10-31', posts: true);

    expect($this->fake->postCalls)->toBe(0)->and(PostMetricSnapshot::withoutUserScope()->count())->toBe(0);
});

it('una metrica rechazada de forma permanente queda como no disponible y no es un fallo', function () {
    $this->fake->error = new PermanentPublishException('sin permiso', 'permisos');

    runSnapshot($this->account, '2026-10-31');

    $snap = AccountSnapshot::withoutUserScope()->first();
    expect(Metric::isEmpty($snap->metrics))->toBeTrue();
    expect($this->account->fresh()->isActive())->toBeTrue();
});

it('credenciales rechazadas marcan la cuenta para reconectar y avisan una vez', function () {
    Notification::fake();
    $this->fake->error = new ReconnectRequiredException('token revocado');

    runSnapshot($this->account, '2026-10-31');
    runSnapshot($this->account, '2026-10-31');

    expect($this->account->fresh()->status)->toBe(SocialAccount::STATUS_RECONNECT);
    Notification::assertSentToTimes($this->user, ReconnectRequired::class, 1);
});

it('las cuentas no activas no se leen', function () {
    $this->account->update(['status' => SocialAccount::STATUS_DISCONNECTED]);

    runSnapshot($this->account, '2026-10-31');

    expect($this->fake->accountCalls)->toBe(0)->and(AccountSnapshot::withoutUserScope()->count())->toBe(0);
});

it('el despachador encola ayer con publicaciones y los dias de reintento solo si faltan, solo para cuentas activas', function () {
    Bus::fake();
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'status' => SocialAccount::STATUS_DISCONNECTED]);

    (new CollectSnapshots)->handle();

    Bus::assertDispatchedTimes(CollectAccountSnapshot::class, 4); // ayer + 3 dias de reintento
    Bus::assertDispatched(CollectAccountSnapshot::class, fn ($j) => $j->accountId === $this->account->id && $j->date === '2026-10-31' && $j->withPosts && ! $j->onlyIfMissing);
    Bus::assertDispatched(CollectAccountSnapshot::class, fn ($j) => $j->date === '2026-10-30' && ! $j->withPosts && $j->onlyIfMissing);
    Bus::assertDispatched(CollectAccountSnapshot::class, fn ($j) => $j->date === '2026-10-29' && $j->onlyIfMissing);
    Bus::assertDispatched(CollectAccountSnapshot::class, fn ($j) => $j->date === '2026-10-28' && $j->onlyIfMissing);
});

it('los dias de reintento no vuelven a leer lo que ya tiene datos', function () {
    runSnapshot($this->account, '2026-10-30');
    $calls = $this->fake->accountCalls;

    runSnapshot($this->account, '2026-10-30', onlyIfMissing: true);
    expect($this->fake->accountCalls)->toBe($calls);

    runSnapshot($this->account, '2026-10-29', onlyIfMissing: true);
    expect($this->fake->accountCalls)->toBe($calls + 1);
});

it('los jobs son unicos por cuenta y dia, van a la cola metrics y tienen timeout menor que el del worker', function () {
    $job = new CollectAccountSnapshot(5, '2026-10-31');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('5:2026-10-31')
        ->and((new CollectAccountSnapshot(5, '2026-10-30'))->uniqueId())->not->toBe($job->uniqueId())
        ->and($job->queue)->toBe('metrics')->and($job->timeout)->toBeLessThan(120)
        ->and((new CollectSnapshots)->queue)->toBe('metrics')->and((new CollectSnapshots)->timeout)->toBeLessThan(120);
});

it('el snapshot diario esta planificado en el scheduler', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description);

    expect($events)->toContain('collect-snapshots');
});

it('el aislamiento por usuario oculta los snapshots ajenos', function () {
    runSnapshot($this->account, '2026-10-31');
    $other = User::factory()->create();

    $this->actingAs($other);
    expect(AccountSnapshot::count())->toBe(0);

    $this->actingAs($this->user);
    expect(AccountSnapshot::count())->toBe(1);
});
