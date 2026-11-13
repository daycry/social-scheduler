<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Jobs\DispatchDuePosts;
use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\Post;
use App\Models\PostSeries;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\SeriesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// T-32: las ocurrencias de una serie heredan la configuracion de aprobacion de la serie (requires_approval).

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-09 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
});

function approvalSeriesInput(array $over = []): array
{
    return array_replace([
        'brand_id' => test()->brand->id, 'frequency' => 'diaria', 'time_local' => '10:00', 'starts_on' => '2026-11-10', 'max_occurrences' => 3,
        'text' => 'Serie', 'media' => [], 'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ], $over);
}

test('con requires_approval cada ocurrencia nace como borrador pendiente de aprobacion y no se programa', function () {
    $series = app(SeriesService::class)->create($this->user, $this->brand, approvalSeriesInput(['requires_approval' => true]));

    $posts = Post::withoutUserScope()->where('series_id', $series->id)->get();
    expect($posts)->toHaveCount(3)
        ->and($posts->every(fn (Post $p) => $p->approval_status === Post::APPROVAL_PENDING && $p->status === PostStatus::Draft))->toBeTrue()
        ->and(app(SeriesService::class)->present($series)['requires_approval'])->toBeTrue();
});

test('sin requires_approval las ocurrencias se programan como siempre', function () {
    $series = app(SeriesService::class)->create($this->user, $this->brand, approvalSeriesInput());

    $posts = Post::withoutUserScope()->where('series_id', $series->id)->get();
    expect($posts->every(fn (Post $p) => $p->approval_status === null && $p->status === PostStatus::Scheduled))->toBeTrue()
        ->and(app(SeriesService::class)->present($series)['requires_approval'])->toBeFalse();
});

test('editar la serie actualiza el texto de las ocurrencias pendientes y las deja pendientes de aprobacion', function () {
    $series = app(SeriesService::class)->create($this->user, $this->brand, approvalSeriesInput(['requires_approval' => true]));

    app(SeriesService::class)->update($this->user, $series, ['text' => 'Nuevo texto']);

    $posts = Post::withoutUserScope()->where('series_id', $series->id)->get();
    expect($posts->every(fn (Post $p) => $p->text === 'Nuevo texto' && $p->approval_status === Post::APPROVAL_PENDING && $p->status === PostStatus::Draft))->toBeTrue();
});

test('la API acepta requires_approval al crear la serie y una ocurrencia aprobada queda aprobada', function () {
    $res = $this->actingAs($this->user)->postJson(route('workflow.series.store'), approvalSeriesInput(['requires_approval' => true]))->assertCreated();
    expect($res->json('series.requires_approval'))->toBeTrue();

    $post = Post::withoutUserScope()->where('series_id', $res->json('series.id'))->orderBy('scheduled_at')->first();
    $this->actingAs($this->user)->postJson(route('workflow.approval.approve', $post->id), ['signature' => app(PostService::class)->contentSignature($post)])->assertOk();
    expect($post->fresh()->approval_status)->toBe(Post::APPROVAL_APPROVED)->and(PostSeries::withoutUserScope()->count())->toBe(1);
});

function seriesPosts(PostSeries $series)
{
    return Post::withoutUserScope()->where('series_id', $series->id)->orderBy('scheduled_at')->get();
}

function approverSig(Post $post): string
{
    return app(PostService::class)->contentSignature($post);
}

test('aprobar una ocurrencia la programa en su fecha sin sacarla de la serie y rechazarla no la publica', function () {
    $approver = User::factory()->create();
    BrandCollaborator::query()->create(['brand_id' => $this->brand->id, 'owner_id' => $this->user->id, 'user_id' => $approver->id, 'role' => 'aprobador']);
    $series = app(SeriesService::class)->create($this->user, $this->brand, approvalSeriesInput(['requires_approval' => true]));
    [$a, $b] = [seriesPosts($series)[0], seriesPosts($series)[1]];

    $this->actingAs($approver)->postJson(route('workflow.approval.approve', $a->id), ['signature' => approverSig($a)])->assertOk();
    $this->actingAs($approver)->postJson(route('workflow.approval.reject', $b->id), ['signature' => approverSig($b), 'comment' => 'No'])->assertOk();

    $a = $a->fresh();
    $b = $b->fresh();
    expect($a->status)->toBe(PostStatus::Scheduled)->and($a->targets()->first()->status)->toBe(PostStatus::Scheduled)
        ->and($a->series_id)->toBe($series->id)->and((bool) $a->series_exception)->toBeFalse()
        ->and($b->status)->toBe(PostStatus::Draft)->and($b->targets()->first()->status)->toBe(PostStatus::Draft);

    Carbon::setTestNow($b->scheduled_at->copy()->addMinute()); // llega la hora de las dos: sale la aprobada y nunca la rechazada
    $due = DispatchDuePosts::dueTargets()->all();
    expect($due)->toBe([$a->targets()->first()->id]);
});

test('activar la aprobacion en una serie con ocurrencias ya programadas las retiene y desactivarla las vuelve a programar', function () {
    $series = app(SeriesService::class)->create($this->user, $this->brand, approvalSeriesInput());
    expect(seriesPosts($series)->every(fn (Post $p) => $p->status === PostStatus::Scheduled))->toBeTrue();

    app(SeriesService::class)->update($this->user, $series, ['requires_approval' => true]);
    $held = seriesPosts($series);
    expect($held->every(fn (Post $p) => $p->approval_status === Post::APPROVAL_PENDING && $p->status === PostStatus::Draft && $p->targets()->first()->status === PostStatus::Draft))->toBeTrue();

    // Ningun camino publica una ocurrencia retenida: publicar ya, programar, API de lotes y despachador.
    $post = $held[0];
    expect(fn () => app(PostService::class)->publishNow($this->user, $post))->toThrow(PostValidationException::class)
        ->and(fn () => app(PostService::class)->schedule($this->user, $post->fresh()))->toThrow(PostValidationException::class)
        ->and(fn () => app(PostService::class)->retryFailed($this->user, $post->fresh()))->toThrow(PostValidationException::class);
    $post->targets()->update(['status' => PostStatus::Scheduled->value]); // aunque algo forzara el destino a programada
    Carbon::setTestNow($post->scheduled_at->copy()->addMinute());
    expect(DispatchDuePosts::dueTargets()->all())->toBe([]);
    Carbon::setTestNow('2026-11-09 12:00:00');
    $post->targets()->update(['status' => PostStatus::Draft->value]);

    app(SeriesService::class)->update($this->user, $series->fresh(), ['requires_approval' => false]);
    $released = seriesPosts($series);
    expect($released->every(fn (Post $p) => $p->approval_status === null && $p->status === PostStatus::Scheduled && ! $p->series_exception))->toBeTrue();
});

test('con aprobacion, una serie genera un unico aviso por tanda a los aprobadores', function () {
    $approver = User::factory()->create();
    BrandCollaborator::query()->create(['brand_id' => $this->brand->id, 'owner_id' => $this->user->id, 'user_id' => $approver->id, 'role' => 'aprobador']);
    $series = app(SeriesService::class)->create($this->user, $this->brand, approvalSeriesInput(['requires_approval' => true, 'max_occurrences' => null]));

    expect(seriesPosts($series)->count())->toBeGreaterThan(30);
    $notices = $approver->notifications()->where('data->type', 'approval_requested')->get();
    expect($notices)->toHaveCount(1)->and($notices[0]->data['message'])->toContain((string) seriesPosts($series)->count());

    app(SeriesService::class)->update($this->user, $series->fresh(), ['text' => 'Otro texto']); // la edicion tampoco avisa una a una
    expect($approver->notifications()->where('data->type', 'approval_requested')->count())->toBeLessThanOrEqual(2);
});
