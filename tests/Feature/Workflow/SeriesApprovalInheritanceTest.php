<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
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
