<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PollPublishStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PublishAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\ReconnectRequired;
use App\Services\PublishOutcomes;
use App\Services\PublishRequestFactory;
use App\Services\SocialAccountService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePublisher;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Notification::fake();
    config(['publishing.backoff_seconds' => [60, 300, 900], 'publishing.max_attempts' => 4]);

    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->publisher = new FakePublisher;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Instagram, $this->publisher));
});

/** Destino `programada` vencido (por defecto hace 1 minuto). */
function dueTarget(array $postAttrs = [], array $targetAttrs = []): PostTarget
{
    $post = Post::factory()->create(array_merge(['brand_id' => test()->brand->id, 'user_id' => test()->user->id, 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled], $postAttrs));

    return PostTarget::factory()->create(array_merge(['post_id' => $post->id, 'user_id' => test()->user->id, 'social_account_id' => test()->account->id], $targetAttrs));
}

/** Una pasada del scheduler (cola sincrona de los tests: el job se ejecuta en el acto). */
function tick(int $seconds = 0): int
{
    Carbon::setTestNow(now()->addSeconds($seconds));

    return app(DispatchDuePosts::class)();
}

test('Publishing: una publicacion vencida se publica y queda publicada con ID y enlace (CA-06)', function () {
    $target = dueTarget();

    expect(tick())->toBe(1);

    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Published)
        ->and($t->external_id)->toStartWith('ext-')
        ->and($t->external_url)->toStartWith('https://fake.test/')
        ->and($t->published_at)->not->toBeNull()
        ->and($t->attempts)->toBe(1)
        ->and($t->post->fresh()->status)->toBe(PostStatus::Published)
        ->and($this->publisher->publishCalls)->toBe(1);
    expect(PublishAttempt::withoutUserScope()->where('post_target_id', $t->id)->pluck('outcome')->all())->toBe(['publicado']);
});

test('Publishing: lo que aun no vence no se publica y lo repetido tampoco', function () {
    $future = dueTarget(['scheduled_at' => now()->addMinutes(5)]);
    $done = dueTarget();

    expect(tick())->toBe(1);
    expect($future->fresh()->status)->toBe(PostStatus::Scheduled);

    expect(tick())->toBe(0); // la ya publicada no se vuelve a despachar
    expect($this->publisher->publishCalls)->toBe(1);

    tick(6 * 60);
    expect($future->fresh()->status)->toBe(PostStatus::Published);
});

test('Publishing: un error transitorio se reintenta con backoff y termina publicada sin duplicados (CA-07)', function () {
    $this->publisher->script = [new TransientPublishException('Meta 503', 'http_503'), new TransientPublishException('Meta 503', 'http_503'), 'ok'];
    $target = dueTarget();

    tick();
    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Scheduled)->and($t->attempts)->toBe(1)
        ->and($t->error_code)->toBe('http_503')
        ->and($t->next_attempt_at->toDateTimeString())->toBe(now()->addSeconds(60)->toDateTimeString());

    expect(tick(30))->toBe(0); // aun en backoff
    tick(31);
    expect($target->fresh()->attempts)->toBe(2)->and($target->fresh()->status)->toBe(PostStatus::Scheduled);

    tick(301); // segundo backoff = 300 s
    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Published)->and($t->attempts)->toBe(3)
        ->and($this->publisher->publishCalls)->toBe(3)->and(count($this->publisher->externals))->toBe(1);
    expect(PublishAttempt::withoutUserScope()->where('post_target_id', $t->id)->orderBy('attempt_no')->pluck('outcome')->all())->toBe(['transitorio', 'transitorio', 'publicado']);
});

test('Publishing: si la red publico pero la respuesta se perdio, el reintento consulta y NO duplica (CA-07)', function () {
    $this->publisher->script = ['sent-then-fail']; // la red publica y la respuesta expira
    $target = dueTarget();

    tick();
    expect($target->fresh()->status)->toBe(PostStatus::Scheduled)->and(count($this->publisher->externals))->toBe(1);

    tick(61);
    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Published)
        ->and($t->external_id)->toBe(array_values($this->publisher->externals)[0])
        ->and($this->publisher->publishCalls)->toBe(1) // el segundo intento solo consulto
        ->and($this->publisher->findCalls)->toBe(1)
        ->and(count($this->publisher->externals))->toBe(1);
});

test('Publishing: agotados los intentos acaba fallida con motivo legible', function () {
    config(['publishing.max_attempts' => 3]);
    $this->publisher->script = array_fill(0, 5, new TransientPublishException('Meta no responde', 'timeout'));
    $target = dueTarget();

    tick();
    tick(61);
    tick(301);

    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Failed)
        ->and($t->error_code)->toBe('reintentos_agotados')
        ->and($t->error_message)->toContain('3 intentos')->toContain('Meta no responde')
        ->and($this->publisher->publishCalls)->toBe(3);
    expect(tick(3600))->toBe(0); // no se reintenta mas
});

test('Publishing: un error permanente falla al primer intento y no se reintenta', function () {
    $this->publisher->script = [new PermanentPublishException('Formato de video no admitido', 'media_rechazada')];
    $target = dueTarget();

    tick();
    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Failed)->and($t->error_code)->toBe('media_rechazada')->and($t->error_message)->toBe('Formato de video no admitido');

    tick(3600);
    expect($this->publisher->publishCalls)->toBe(1)->and($t->post->fresh()->status)->toBe(PostStatus::Failed);
});

test('Publishing: la cuota agotada aplaza el destino sin gastar un intento', function () {
    $this->publisher->script = [new QuotaExceededException('Limite de publicaciones de Instagram alcanzado', now()->addHours(2))];
    $target = dueTarget();

    tick();
    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Scheduled)->and($t->attempts)->toBe(0)->and($t->error_code)->toBe('cuota')
        ->and($t->next_attempt_at->toDateTimeString())->toBe(now()->addHours(2)->toDateTimeString());

    expect(tick(3600))->toBe(0);
    tick(3601);
    expect($target->fresh()->status)->toBe(PostStatus::Published);
});

test('Publishing: credenciales invalidas conservan la publicacion, marcan la cuenta y avisan una vez; al reconectar sale', function () {
    $this->publisher->script = [new ReconnectRequiredException('Token revocado')];
    $target = dueTarget();

    tick();
    expect($target->fresh()->status)->toBe(PostStatus::Scheduled)->and($target->fresh()->error_code)->toBe('reconexion')->and($target->fresh()->attempts)->toBe(0);
    expect($this->account->fresh()->status)->toBe(SocialAccount::STATUS_RECONNECT);
    Notification::assertSentToTimes($this->user, ReconnectRequired::class, 1);

    expect(tick(120))->toBe(0); // cuenta inactiva: no se despacha

    $this->account->fresh()->update(['status' => SocialAccount::STATUS_ACTIVE]);
    tick(60);
    expect($target->fresh()->status)->toBe(PostStatus::Published);
});

test('Publishing: tras una caida del VPS, al arrancar se publican las vencidas dentro del margen y las muy tardias fallan', function () {
    $recent = dueTarget(['scheduled_at' => now()->subHours(3)]);
    $ancient = dueTarget(['scheduled_at' => now()->subHours(30)]);

    tick();

    expect($recent->fresh()->status)->toBe(PostStatus::Published);
    $old = $ancient->fresh();
    expect($old->status)->toBe(PostStatus::Failed)->and($old->error_code)->toBe('vencida')->and($old->error_message)->toContain('24 h');
    expect($this->publisher->publishCalls)->toBe(1);
});

test('Publishing: un destino colgado en publicando se recupera consultando antes a la red (sin duplicar)', function () {
    $target = dueTarget(targetAttrs: ['status' => PostStatus::Publishing, 'attempts' => 1, 'started_at' => now()->subMinutes(30)]);
    $this->publisher->externals[$target->id] = 'ext-ya-publicado'; // el worker cayo despues de publicar

    tick();

    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Published)->and($t->external_id)->toBe('ext-ya-publicado')
        ->and($this->publisher->publishCalls)->toBe(0)->and($this->publisher->findCalls)->toBe(1)
        ->and($t->attempts)->toBe(2);
});

test('Publishing: un destino en publicando reciente (otro worker en vuelo) no se toca', function () {
    $target = dueTarget(targetAttrs: ['status' => PostStatus::Publishing, 'attempts' => 1, 'started_at' => now()->subMinute()]);

    (new PublishPostTarget($target->id))->handle(app(ConnectorRegistry::class), app(PublishOutcomes::class), app(PublishRequestFactory::class), app(SocialAccountService::class));

    expect($this->publisher->publishCalls)->toBe(0)->and($target->fresh()->status)->toBe(PostStatus::Publishing);
});

test('Publishing: dos ejecuciones simultaneas del mismo destino publican una sola vez', function () {
    $target = dueTarget();
    $run = fn () => (new PublishPostTarget($target->id))->handle(app(ConnectorRegistry::class), app(PublishOutcomes::class), app(PublishRequestFactory::class), app(SocialAccountService::class));

    $run();
    $run();

    expect($this->publisher->publishCalls)->toBe(1)->and($target->fresh()->status)->toBe(PostStatus::Published);
});

test('Publishing: si se reprograma a futuro mientras estaba en cola, el job no publica', function () {
    $target = dueTarget();
    $target->post->update(['scheduled_at' => now()->addDay()]);

    (new PublishPostTarget($target->id))->handle(app(ConnectorRegistry::class), app(PublishOutcomes::class), app(PublishRequestFactory::class), app(SocialAccountService::class));

    expect($this->publisher->publishCalls)->toBe(0)->and($target->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('Publishing: si la cuenta se elimino la publicacion falla con motivo (no se pierde en silencio)', function () {
    $target = dueTarget();
    SocialAccount::withoutUserScope()->whereKey($this->account->id)->delete();

    tick();

    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Failed)->and($t->error_code)->toBe('cuenta_no_disponible')->and($this->publisher->publishCalls)->toBe(0);
});

test('Publishing: sin publicador para la red falla con motivo', function () {
    $this->app->instance(ConnectorRegistry::class, new ConnectorRegistry);
    $target = dueTarget();

    tick();

    expect($target->fresh()->status)->toBe(PostStatus::Failed)->and($target->fresh()->error_code)->toBe('red_no_soportada');
});

test('Publishing: una publicacion asincrona pasa a procesando y se sondea hasta publicarse', function () {
    $this->publisher->script = ['processing'];
    $this->publisher->pollScript = ['wait', 'done'];
    $target = dueTarget();

    tick();
    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Processing)->and($t->external_reference)->toBe('ref-'.$target->id);

    // Cola sync: el sondeo con delay corre al despachar (1.o: sigue procesando); luego lo retoma la recuperacion.
    expect($this->publisher->pollCalls)->toBe(1);

    tick(31);
    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Published)->and($t->external_id)->not->toBeNull()
        ->and($this->publisher->publishCalls)->toBe(1);
});

test('Publishing: el sondeo que no termina en poll_max_minutes falla; un fallo de la red tambien con motivo', function () {
    config(['publishing.poll_max_minutes' => 10]);
    $this->publisher->script = ['processing'];
    $slow = dueTarget();
    tick();
    expect($slow->fresh()->status)->toBe(PostStatus::Processing);

    tick(11 * 60);
    expect($slow->fresh()->status)->toBe(PostStatus::Failed)->and($slow->fresh()->error_code)->toBe('timeout_procesado');

    $this->publisher->script = ['processing'];
    $this->publisher->pollScript = ['fail'];
    $bad = dueTarget();
    tick();
    tick(31);
    expect($bad->fresh()->status)->toBe(PostStatus::Failed)->and($bad->fresh()->error_message)->toBe('Instagram no pudo procesar el video.');
});

test('Publishing: un error pasajero al sondear no falla el destino', function () {
    $this->publisher->script = ['processing'];
    $this->publisher->pollScript = [new TransientPublishException('503'), 'done'];
    $target = dueTarget();

    tick(); // 1.o sondeo (inmediato en cola sync): error pasajero
    expect($target->fresh()->status)->toBe(PostStatus::Processing);
    tick(31);
    expect($target->fresh()->status)->toBe(PostStatus::Published);
});

test('Publishing: los jobs usan las colas publish y poll y son unicos por destino', function () {
    $publish = new PublishPostTarget(7);
    $poll = new PollPublishStatus(7);

    expect($publish->queue)->toBe('publish')->and($poll->queue)->toBe('poll')
        ->and($publish->uniqueId())->toBe('7')->and($poll->uniqueId())->toBe('7')
        ->and($publish)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($publish->timeout)->toBeLessThan(120)->and($publish->tries)->toBeGreaterThan(0);

    Queue::fake();
    dueTarget();
    app(DispatchDuePosts::class)();
    Queue::assertPushedOn('publish', PublishPostTarget::class);
});

test('Publishing: el despachador esta registrado en el scheduler cada minuto', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => ($e->description ?? null) === 'dispatch-due-posts');

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');
});
