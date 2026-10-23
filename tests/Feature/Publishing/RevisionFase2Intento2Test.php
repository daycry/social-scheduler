<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Meta\Facebook\FacebookPublisher;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeGraph;

// Revision de la Fase 2, intento 2 (fix2): N1 (ventana de busqueda de la reconciliacion tras reprogramar y editar), N2 (limites de
// ritmo de la red) y la pausa por cuota que una edicion no debe borrar.

const R2_PAGE = '1065269806670555';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.meta.graph_version' => 'v23.0']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->account = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Facebook, 'external_id' => R2_PAGE, 'access_token' => 'page-token-xyz',
    ]);
    $this->service = app(PostService::class);
});

function r2Data(string $text, string $when): array
{
    return ['text' => $text, 'scheduled_at' => $when, 'schedule' => true,
        'targets' => [['account_id' => test()->account->id, 'content_type' => 'facebook_text']]];
}

test('N1: programado a las 13:00, intento ambiguo a las 13:00:30, reprogramado a las 16:00 y texto editado a las 15:00: se reconoce lo publicado (una sola publicacion)', function () {
    $graph = new FakeGraph;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Facebook, new FacebookPublisher));
    $graph->on('POST', '#^'.R2_PAGE.'/feed$#', new ConnectionException('cURL error 28: timeout'))
        // La red SI publico el texto viejo a las 13:00:40 aunque la respuesta se perdio.
        ->on('GET', '#^'.R2_PAGE.'/posts$#', ['data' => [['id' => R2_PAGE.'_701', 'message' => 'Texto viejo', 'permalink_url' => 'https://www.facebook.com/'.R2_PAGE.'/posts/701', 'created_time' => '2026-11-01T13:00:40+0000']]]);

    $post = $this->service->create($this->user, $this->brand, r2Data('Texto viejo', '2026-11-01 13:00:00'));
    $target = $post->targets->first();

    Carbon::setTestNow('2026-11-01 13:00:30');
    app(DispatchDuePosts::class)();
    expect($target->fresh()->attempts)->toBe(1)->and($graph->callsTo('POST', '#/feed$#'))->toHaveCount(1);

    Carbon::setTestNow('2026-11-01 13:00:45');
    $this->service->reschedule($this->user, Post::withoutUserScope()->find($post->id), '2026-11-01 16:00:00');

    Carbon::setTestNow('2026-11-01 15:00:00');
    $this->service->update($this->user, Post::withoutUserScope()->find($post->id), r2Data('Texto NUEVO', '2026-11-01 16:00:00'));
    // La ventana guardada cubre el primer intento (12:55:30), no solo la fecha reprogramada (15:55).
    expect(Carbon::parse($target->fresh()->progress['reconcile']['since'])->toIso8601String())->toBe('2026-11-01T12:55:30+00:00');

    Carbon::setTestNow('2026-11-01 16:05:00');
    app(DispatchDuePosts::class)();

    $t = $target->fresh();
    expect($graph->callsTo('POST', '#/feed$#'))->toHaveCount(1)
        ->and($t->status)->toBe(PostStatus::Published)->and($t->external_id)->toBe(R2_PAGE.'_701');
});

test('N2: «Publicar ahora» respeta los limites de ritmo de la red igual que la cuota y no gasta un intento por clic', function (string $code) {
    Queue::fake();
    $post = $this->service->create($this->user, $this->brand, r2Data('Hola', '2026-11-02 10:00:00'));
    $target = $post->targets->first();
    $retry = now()->addMinutes(20);
    $target->forceFill(['next_attempt_at' => $retry, 'error_code' => $code, 'attempts' => 1])->save();

    $result = $this->service->publishNow($this->user, Post::withoutUserScope()->find($post->id));

    expect($target->fresh()->next_attempt_at->equalTo($retry))->toBeTrue()->and($target->fresh()->error_code)->toBe($code)
        ->and(PostService::pausedTargets($result))->toBe(1);
    Queue::assertNotPushed(PublishPostTarget::class);
})->with(['cuota', 'rate_limit', 'youtube_limite_ritmo', 'rate_limit_exceeded']);

test('N2: un reintento transitorio normal (red) SI se salta con «Publicar ahora»', function () {
    Queue::fake();
    $post = $this->service->create($this->user, $this->brand, r2Data('Hola', '2026-11-02 10:00:00'));
    $target = $post->targets->first();
    $target->forceFill(['next_attempt_at' => now()->addMinutes(20), 'error_code' => 'red', 'attempts' => 1])->save();

    $this->service->publishNow($this->user, Post::withoutUserScope()->find($post->id));

    expect($target->fresh()->next_attempt_at)->toBeNull();
    Queue::assertPushed(PublishPostTarget::class, 1);
});

test('Pausa por la red: editar el texto de un destino en pausa conserva la pausa y «Publicar ahora» no la salta', function (string $code) {
    Queue::fake();
    $post = $this->service->create($this->user, $this->brand, r2Data('Texto viejo', '2026-11-02 10:00:00'));
    $target = $post->targets->first();
    $retry = now()->addMinutes(40);
    $target->forceFill(['next_attempt_at' => $retry, 'error_code' => $code, 'attempts' => 1])->save();

    $this->service->update($this->user, Post::withoutUserScope()->find($post->id), r2Data('Texto NUEVO', '2026-11-02 10:00:00'));
    expect($target->fresh()->attempts)->toBe(0)->and($target->fresh()->next_attempt_at->equalTo($retry))->toBeTrue()
        ->and($target->fresh()->error_code)->toBe($code);

    $this->service->publishNow($this->user, Post::withoutUserScope()->find($post->id));

    expect($target->fresh()->next_attempt_at->equalTo($retry))->toBeTrue();
    Queue::assertNotPushed(PublishPostTarget::class);
})->with(['cuota', 'rate_limit']);

test('Pausa por la red: una pausa caducada o un error normal NO se conservan al editar (destino nuevo de verdad)', function () {
    Queue::fake();
    $post = $this->service->create($this->user, $this->brand, r2Data('Texto viejo', '2026-11-02 10:00:00'));
    $target = $post->targets->first();
    $target->forceFill(['next_attempt_at' => now()->subMinute(), 'error_code' => 'cuota', 'attempts' => 1])->save();

    $this->service->update($this->user, Post::withoutUserScope()->find($post->id), r2Data('Texto NUEVO', '2026-11-02 10:00:00'));

    expect($target->fresh()->next_attempt_at)->toBeNull()->and($target->fresh()->error_code)->toBeNull();
});
