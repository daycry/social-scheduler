<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePublisher;

// «Publicar ahora» (ADR-005, O1): programa con la fecha del servidor y encola tras el commit sobre las defensas existentes.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => 'FB']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'IG']);
    $this->publisher = new FakePublisher;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Facebook, $this->publisher)->registerPublisher(Network::Instagram, $this->publisher));
    $this->service = app(PostService::class);
});

function nowData(array $over = []): array
{
    return array_merge([
        'text' => 'Hola mundo', 'schedule' => false, 'media' => [],
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ], $over);
}

function nowDraft(array $over = []): Post
{
    return test()->service->create(test()->user, test()->brand, nowData($over));
}

/** Errores de validacion que lanza la llamada (o null si no lanza). */
function nowErrors(callable $call): ?array
{
    try {
        $call();
    } catch (PostValidationException $e) {
        return $e->errors();
    }

    return null;
}

test('PublishNow: encola el job de cada destino tras el commit y el destino esta programada y vencido', function () {
    Queue::fake();
    $post = nowDraft();
    Queue::assertNothingPushed();

    $post = $this->service->publishNow($this->user, $post);

    Queue::assertPushed(PublishPostTarget::class, 1);
    Queue::assertPushed(PublishPostTarget::class, fn (PublishPostTarget $job) => $job->targetId === $post->targets->first()->id);
    $target = $post->targets->first()->fresh();
    expect($target->status)->toBe(PostStatus::Scheduled)
        ->and($post->fresh()->scheduled_at->lte(now()))->toBeTrue()
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and(DispatchDuePosts::dueTargets($post)->all())->toBe([$target->id]);
});

test('PublishNow: el job NO se encola antes del commit (la cola database tiene after_commit=false)', function () {
    Queue::fake();
    $post = nowDraft();

    DB::transaction(function () use ($post) {
        $this->service->publishNow($this->user, $post);
        Queue::assertNothingPushed(); // dentro de la transaccion todavia no
    });

    Queue::assertPushed(PublishPostTarget::class, 1);
});

test('PublishNow: si la transaccion se revierte, no se encola nada', function () {
    Queue::fake();
    $post = nowDraft();

    try {
        DB::transaction(function () use ($post) {
            $this->service->publishNow($this->user, $post);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNothingPushed();
});

test('PublishNow: un post con errores de contenido se rechaza con los mismos mensajes que al programar', function () {
    $post = nowDraft();
    $post->forceFill(['text' => str_repeat('a', 63207)])->save(); // por encima del maximo de Facebook

    $viaSchedule = nowErrors(fn () => $this->service->schedule($this->user, $post, now()->addDay()));
    $viaNow = nowErrors(fn () => $this->service->publishNow($this->user, $post));

    expect($viaSchedule)->not->toBeNull()->toHaveKey('targets.0')
        ->and($viaNow)->toBe($viaSchedule)
        ->and($post->fresh()->status)->toBe(PostStatus::Draft);
});

test('PublishNow: una cuenta que requiere reconexion da el mismo error de validacion que al programar', function () {
    $post = nowDraft();
    $this->fb->forceFill(['status' => SocialAccount::STATUS_RECONNECT])->save();
    Queue::fake();

    $viaSchedule = nowErrors(fn () => $this->service->schedule($this->user, $post, now()->addDay()));
    $viaNow = nowErrors(fn () => $this->service->publishNow($this->user, $post));

    expect($viaSchedule)->not->toBeNull()->and($viaNow)->toBe($viaSchedule)->and($viaNow)->toHaveKey('targets.0');
    Queue::assertNothingPushed();
});

test('PublishNow: un post sin destinos se rechaza', function () {
    $post = nowDraft(['targets' => []]);

    expect(fn () => $this->service->publishNow($this->user, $post))->toThrow(PostValidationException::class, 'al menos una cuenta');
});

test('PublishNow: un post en vuelo o publicado se rechaza con el mismo error que update y no encola', function (PostStatus $status) {
    Queue::fake();
    $post = nowDraft();
    $post->targets->first()->forceFill(['status' => $status])->save();

    expect(fn () => $this->service->publishNow($this->user, $post->fresh()))->toThrow(PostValidationException::class, 'no se puede modificar');
    Queue::assertNothingPushed();
})->with([PostStatus::Publishing, PostStatus::Processing, PostStatus::Published]);

test('PublishNow: un post de otro usuario se rechaza', function () {
    Queue::fake();
    $post = nowDraft();

    expect(fn () => $this->service->publishNow(User::factory()->create(), $post))->toThrow(PostValidationException::class, 'no pertenece');
    Queue::assertNothingPushed();
});

test('PublishNow: conserva texto y opciones del post', function () {
    Queue::fake();
    $post = nowDraft(['targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text', 'options' => ['link' => 'https://example.com/x']]]]);

    $after = $this->service->publishNow($this->user, $post);

    expect($after->text)->toBe('Hola mundo')->and($after->targets->first()->options)->toBe(['link' => 'https://example.com/x']);
});

test('PublishNow: create con publish_now programa con la fecha del servidor, ignora el reloj de la entrada y encola', function () {
    Queue::fake();

    $post = $this->service->create($this->user, $this->brand, nowData(['publish_now' => true, 'scheduled_at' => '2020-01-01 10:00']));

    expect($post->status)->toBe(PostStatus::Scheduled)->and($post->scheduled_at->toDateTimeString())->toBe(now()->toDateTimeString());
    Queue::assertPushed(PublishPostTarget::class, 1);
});

test('PublishNow: dueTargets solo devuelve los vencidos de ESE post con la cuenta activa', function () {
    Queue::fake();
    $a = nowDraft();
    $b = nowDraft();
    $this->service->publishNow($this->user, $a);
    $this->service->publishNow($this->user, $b);

    expect(DispatchDuePosts::dueTargets($a)->all())->toBe([$a->targets->first()->id])
        ->and(DispatchDuePosts::dueTargets()->count())->toBe(2);

    $this->fb->forceFill(['status' => SocialAccount::STATUS_RECONNECT])->save();
    expect(DispatchDuePosts::dueTargets($a)->all())->toBe([]);
});

test('PublishNow: el despachador del minuto sigue encolando lo mismo que antes', function () {
    Queue::fake();
    nowDraft(); // un borrador no se despacha
    $post = nowDraft();
    $this->service->schedule($this->user, $post, now()->addMinute());
    Carbon::setTestNow(now()->addMinutes(2));

    expect(app(DispatchDuePosts::class)())->toBe(1);
    Queue::assertPushed(PublishPostTarget::class, 1);
});

test('PublishNow: PublishPostTarget conserva ShouldBeUnique y el claim con lockForUpdate (no se toca)', function () {
    expect(new PublishPostTarget(1))->toBeInstanceOf(ShouldBeUnique::class)
        ->and(file_get_contents(app_path('Jobs/PublishPostTarget.php')))->toContain('lockForUpdate()->find($this->targetId)');
});
