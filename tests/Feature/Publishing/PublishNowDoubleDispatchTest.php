<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PublishAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\PublishFailed;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePublisher;

// Riesgo principal del plan (T-15): «Publicar ahora» NUNCA encola ni publica dos veces el mismo destino. Las defensas son las
// de siempre (`ShouldBeUnique` + `claim()` con lockForUpdate de PublishPostTarget); estos tests las fijan y cuentan las
// llamadas reales al publicador (FakePublisher::$publishCalls).

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Notification::fake();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => 'FB']);
    $this->publisher = new FakePublisher;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Facebook, $this->publisher));
    $this->service = app(PostService::class);
});

function ddDraft(): Post
{
    return test()->service->create(test()->user, test()->brand, [
        'text' => 'Una sola vez', 'schedule' => false, 'media' => [],
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ]);
}

/** Ejecuta el job del destino YA (sin cola ni bloqueo de unicidad, tambien con Queue::fake): lo que haria un worker al recogerlo. */
function runJob(int $targetId): void
{
    app()->call([new PublishPostTarget($targetId), 'handle']);
}

test('PublishNowDoubleDispatch: publishNow y el despachador del mismo minuto encolan UNA vez y publican UNA vez', function () {
    Queue::fake();
    $post = ddDraft();
    $id = $post->targets->first()->id;

    $this->service->publishNow($this->user, $post);
    $dispatched = app(DispatchDuePosts::class)(); // el despachador del minuto ve el destino vencido

    // ShouldBeUnique descarta el segundo dispatch mientras el primero sigue en la cola.
    Queue::assertPushed(PublishPostTarget::class, 1);
    expect($dispatched)->toBe(1);

    // Aunque los dos jobs llegaran a ejecutarse (p. ej. el bloqueo de unicidad caducado), el claim deja pasar solo al primero.
    runJob($id);
    runJob($id);

    expect($this->publisher->publishCalls)->toBe(1)
        ->and(count($this->publisher->externals))->toBe(1)
        ->and($post->targets->first()->fresh()->status)->toBe(PostStatus::Published)
        ->and(PublishAttempt::withoutUserScope()->where('post_target_id', $id)->count())->toBe(1);
});

test('PublishNowDoubleDispatch: con la cola sincrona real, publishNow publica y el despachador del minuto ya no encuentra nada', function () {
    $post = ddDraft();

    $this->service->publishNow($this->user, $post); // la cola sync ejecuta el job tras el commit
    $dispatched = app(DispatchDuePosts::class)();

    expect($this->publisher->publishCalls)->toBe(1)
        ->and($dispatched)->toBe(0)
        ->and($post->targets->first()->fresh()->status)->toBe(PostStatus::Published);
});

test('PublishNowDoubleDispatch: dos peticiones publish-now seguidas encolan una vez y publican una vez', function () {
    Queue::fake();
    $post = ddDraft();
    $id = $post->targets->first()->id;

    $this->actingAs($this->user)->post(route('posts.publish-now', $post))->assertRedirect();
    $this->actingAs($this->user)->post(route('posts.publish-now', $post))->assertRedirect(); // el destino sigue `programada`: se acepta, pero no se vuelve a encolar

    Queue::assertPushed(PublishPostTarget::class, 1);

    runJob($id);
    runJob($id);
    expect($this->publisher->publishCalls)->toBe(1);

    // Con el destino ya publicado, una tercera peticion se rechaza y no encola ni publica nada mas.
    $this->actingAs($this->user)->post(route('posts.publish-now', $post))->assertSessionHas('error');
    Queue::assertPushed(PublishPostTarget::class, 1);
    expect($this->publisher->publishCalls)->toBe(1);
});

test('PublishNowDoubleDispatch: publish-now sobre un destino `publicando` se rechaza sin encolar ni publicar', function () {
    Queue::fake();
    $post = ddDraft();
    $target = $post->targets->first();
    $target->forceFill(['status' => PostStatus::Publishing, 'started_at' => now(), 'attempts' => 1])->save();
    $post->syncStatus();

    $this->actingAs($this->user)->post(route('posts.publish-now', $post))->assertSessionHas('error', fn ($m) => str_contains($m, 'no se puede modificar'));
    expect(fn () => $this->service->publishNow($this->user, $post->fresh()))->toThrow(PostValidationException::class);

    Queue::assertNothingPushed();
    // Y si un job llegara al destino en vuelo, el claim ve `publicando` reciente y sale.
    runJob($target->id);
    expect($this->publisher->publishCalls)->toBe(0)->and($target->fresh()->status)->toBe(PostStatus::Publishing);
});

test('PublishNowDoubleDispatch: publish-now desde el endpoint y el despachador del minuto a la vez publican una vez', function () {
    Queue::fake();
    $post = ddDraft();
    $id = $post->targets->first()->id;

    // Orden inverso al de la prueba 1: primero el despachador (el post aun es borrador: no hace nada), luego la peticion, y otra pasada.
    expect(app(DispatchDuePosts::class)())->toBe(0);
    $this->actingAs($this->user)->post(route('posts.publish-now', $post))->assertRedirect();
    expect(app(DispatchDuePosts::class)())->toBe(1);
    Carbon::setTestNow(now()->addMinute());
    expect(app(DispatchDuePosts::class)())->toBe(1); // el job sigue sin ejecutarse: el destino sigue `programada` y vencido

    Queue::assertPushed(PublishPostTarget::class, 1); // el bloqueo de unicidad descarta los otros dos
    runJob($id);
    runJob($id);
    runJob($id);

    expect($this->publisher->publishCalls)->toBe(1)->and($post->targets->first()->fresh()->status)->toBe(PostStatus::Published);
});

test('PublishNowDoubleDispatch: un job que corre antes del commit no publica con el estado viejo y el destino queda programada y vencido', function () {
    Queue::fake();
    $post = ddDraft();
    $target = $post->targets->first();

    // El job «adelantado» (la cola database tiene after_commit=false) ve todavia el borrador: el claim sale sin publicar.
    runJob($target->id);
    expect($this->publisher->publishCalls)->toBe(0)->and($target->fresh()->status)->toBe(PostStatus::Draft);

    $this->service->publishNow($this->user, $post);

    // Tras el commit el destino esta `programada` y vencido (lo encontraria el despachador) y se encola exactamente una vez.
    expect($target->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and(DispatchDuePosts::dueTargets($post)->all())->toBe([$target->id]);
    Queue::assertPushed(PublishPostTarget::class, 1);

    runJob($target->id);
    expect($this->publisher->publishCalls)->toBe(1);
});

test('PublishNowDoubleDispatch: tras un timeout ambiguo, publicar ahora otra vez consulta la red y no duplica', function () {
    $this->publisher->script = ['sent-then-fail']; // la red publica y la respuesta se pierde
    $post = ddDraft();
    $id = $post->targets->first()->id;

    $this->service->publishNow($this->user, $post); // sync: el job publica, la respuesta se pierde y el destino vuelve a `programada` con backoff
    expect($this->publisher->publishCalls)->toBe(1)->and($post->targets->first()->fresh()->status)->toBe(PostStatus::Scheduled);

    Carbon::setTestNow(now()->addMinutes(2));
    app(DispatchDuePosts::class)(); // el reintento consulta `findPublished` antes de publicar

    expect($this->publisher->publishCalls)->toBe(1)
        ->and($this->publisher->findCalls)->toBeGreaterThan(0)
        ->and(count($this->publisher->externals))->toBe(1)
        ->and(PostTarget::withoutUserScope()->find($id)->status)->toBe(PostStatus::Published);
    Notification::assertNotSentTo($this->user, PublishFailed::class);
});

test('PublishNowDoubleDispatch: tras un intento ambiguo, publishNow no duplica (conserva la ventana de busqueda anterior)', function () {
    Queue::fake();
    $post = ddDraft();
    $this->service->schedule($this->user, $post, '2026-11-01T13:00:00Z');
    $id = $post->targets->first()->id;

    Carbon::setTestNow('2026-11-01 13:00:30');
    $this->publisher->script = ['sent-then-fail']; // la red publica y la respuesta se pierde
    runJob($id);
    expect($this->publisher->publishCalls)->toBe(1)
        ->and($post->targets->first()->fresh()->attempts)->toBe(1);

    // Horas despues, el usuario pulsa «Publicar ahora»: scheduled_at pasa a now(), pero lo publicado a las 13:00 sigue a la vista.
    Carbon::setTestNow('2026-11-01 15:00:00');
    $this->service->publishNow($this->user, $post->fresh());
    runJob($id);

    expect($this->publisher->publishCalls)->toBe(1)
        ->and(count($this->publisher->externals))->toBe(1)
        ->and($post->targets->first()->fresh()->status)->toBe(PostStatus::Published);
});

test('PublishNowDoubleDispatch: cambiar solo la fecha de un post con intento ambiguo tampoco duplica', function () {
    Queue::fake();
    $post = ddDraft();
    $this->service->schedule($this->user, $post, '2026-11-01T13:00:00Z');
    $id = $post->targets->first()->id;

    Carbon::setTestNow('2026-11-01 13:00:30');
    $this->publisher->script = ['sent-then-fail'];
    runJob($id);

    Carbon::setTestNow('2026-11-01 15:00:00');
    $this->service->reschedule($this->user, $post->fresh(), '2026-11-01T16:00:00Z');
    $edited = $this->service->update($this->user, $post->fresh(), ['text' => 'Una sola vez', 'schedule' => true, 'scheduled_at' => '2026-11-01T17:00:00Z', 'media' => [],
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']]]);
    $target = $edited->targets->first()->fresh();
    $target->forceFill(['next_attempt_at' => null])->save();

    Carbon::setTestNow('2026-11-01 17:01:00');
    runJob($id);

    expect($this->publisher->publishCalls)->toBe(1)
        ->and($target->fresh()->status)->toBe(PostStatus::Published);
});
