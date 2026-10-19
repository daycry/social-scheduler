<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Jobs\DispatchDuePosts;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\PublishFailed;
use App\Services\PostService;
use App\Services\PublishRecovery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGraph;

// Revision Fase 3 intento 2: N1 (editar un destino programado con estado residual), N2 (cuentas no activas con destinos procesando).

const I2_IG = '17841400000000011';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Notification::fake();
    config(['social.meta.graph_version' => 'v23.0', 'filesystems.media' => 'local']);
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => I2_IG, 'access_token' => 'page-token-xyz',
    ]);
    $this->graph = new FakeGraph;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Instagram, new InstagramPublisher));
    $this->graph->on('GET', '#/content_publishing_limit$#', ['data' => [['quota_usage' => 1, 'config' => ['quota_total' => 25]]]])
        ->on('GET', '#^1\d+$#', ['permalink' => 'https://www.instagram.com/p/ABC/']);
    $this->service = app(PostService::class);
});

function i2Media(): MediaAsset
{
    $asset = MediaAsset::factory()->for(test()->user)->create();
    Storage::disk('local')->put($asset->path, 'x');

    return $asset;
}

/** Post de Instagram programado a futuro con un destino que arrastra estado residual de un intento anterior. */
function i2Scheduled(MediaAsset $media, string $text = 'Texto viejo'): array
{
    $post = test()->service->create(test()->user, test()->brand, [
        'text' => $text, 'scheduled_at' => now()->addHour(), 'schedule' => true, 'media' => [$media->id],
        'targets' => [['account_id' => test()->account->id, 'content_type' => 'instagram_image']],
    ]);
    $target = $post->targets->first();
    $target->forceFill([
        'external_reference' => 'C_OLD', 'attempts' => 2, 'progress' => ['container' => 'C_OLD'], 'error_code' => 'transitorio',
        'next_attempt_at' => now()->addMinutes(30),
    ])->save();

    return [$post->fresh(), $target];
}

function i2Edit(Post $post, array $over): Post
{
    $data = array_merge([
        'text' => $post->text, 'scheduled_at' => $post->scheduled_at, 'schedule' => true, 'media' => $post->media->pluck('id')->all(),
        'targets' => [['account_id' => test()->account->id, 'content_type' => 'instagram_image']],
    ], $over);

    return test()->service->update(test()->user, $post, $data);
}

// ------------------------------------------------------------------ N1

test('N1: editar el texto de un destino programado con estado residual lo resetea y se publica un contenedor NUEVO con el texto nuevo', function () {
    [$post, $target] = i2Scheduled($media = i2Media());

    i2Edit($post, ['text' => 'Texto NUEVO']);

    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Scheduled)->and($t->external_reference)->toBeNull()->and($t->progress['container'] ?? null)->toBeNull()
        ->and($t->attempts)->toBe(0)->and($t->next_attempt_at)->toBeNull()->and($t->error_code)->toBeNull();

    $this->graph->on('POST', '#^'.I2_IG.'/media$#', ['id' => 'C_NEW'])->on('GET', '#^C_NEW$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#/media_publish$#', ['id' => '19001']);
    Carbon::setTestNow(now()->addHours(2));
    app(DispatchDuePosts::class)();

    $containers = $this->graph->callsTo('POST', '#^'.I2_IG.'/media$#');
    expect($containers)->toHaveCount(1)->and($containers[0]['params']['caption'])->toBe('Texto NUEVO');
    $publish = $this->graph->callsTo('POST', '#/media_publish$#');
    expect($publish)->toHaveCount(1)->and($publish[0]['params']['creation_id'])->toBe('C_NEW')
        ->and($target->fresh()->status)->toBe(PostStatus::Published);
});

test('N1: cambiar la media o las opciones del destino tambien descarta el estado residual', function (string $what) {
    [$post, $target] = i2Scheduled(i2Media());

    if ($what === 'media') {
        i2Edit($post, ['media' => [i2Media()->id]]);
    } else {
        i2Edit($post, ['targets' => [['account_id' => $this->account->id, 'content_type' => 'instagram_image', 'options' => ['alt_text' => 'otra']]]]);
    }

    $t = $target->fresh();
    expect($t->external_reference)->toBeNull()->and($t->progress['container'] ?? null)->toBeNull()->and($t->attempts)->toBe(0)->and($t->next_attempt_at)->toBeNull();
})->with(['media', 'opciones']);

test('N1: si solo cambia la fecha se conserva el estado residual (idempotencia del intento en curso)', function () {
    [$post, $target] = i2Scheduled(i2Media());

    i2Edit($post, ['scheduled_at' => now()->addDays(2)]);
    $t = $target->fresh();
    expect($t->external_reference)->toBe('C_OLD')->and($t->attempts)->toBe(2)->and($t->progress)->toBe(['container' => 'C_OLD'])
        ->and($post->fresh()->scheduled_at->toDateString())->toBe(now()->addDays(2)->toDateString());

    $this->service->schedule($this->user, $post->fresh(), now()->addDays(3)); // programar de nuevo sin tocar contenido
    expect($target->fresh()->external_reference)->toBe('C_OLD');
});

test('N1: desprogramar (volver a borrador) descarta el estado residual del destino', function () {
    [$post, $target] = i2Scheduled(i2Media());

    $this->service->unschedule($this->user, $post);

    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Draft)->and($t->external_reference)->toBeNull()->and($t->attempts)->toBe(0)->and($t->progress['container'] ?? null)->toBeNull();
});

// ------------------------------------------------------------------ N2

function i2Processing(array $accountAttrs = [], bool $deleteAccount = false): array
{
    $account = SocialAccount::factory()->for(test()->user)->create(['brand_id' => test()->brand->id, 'network' => Network::Instagram] + $accountAttrs);
    $post = Post::factory()->create(['brand_id' => test()->brand->id, 'user_id' => test()->user->id, 'scheduled_at' => now()->subHour(), 'status' => PostStatus::Processing]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => test()->user->id, 'social_account_id' => $account->id, 'content_type' => ContentType::InstagramImage,
        'status' => PostStatus::Processing, 'external_reference' => 'C1', 'started_at' => now()->subHour(), 'next_attempt_at' => now()->subMinute(),
    ]);

    if ($deleteAccount) {
        $account->delete();
        $target->refresh();
    }

    return [$post, $target, $account];
}

test('N2: un destino procesando de una cuenta desconectada o borrada pasa a fallida/cuenta_no_disponible con aviso', function (string $case) {
    [$post, $target] = $case === 'borrada'
        ? i2Processing(deleteAccount: true)
        : i2Processing(['status' => SocialAccount::STATUS_DISCONNECTED]);
    expect($target->fresh()->status)->toBe(PostStatus::Processing);

    $result = app(PublishRecovery::class)->run();

    $t = $target->fresh();
    expect($result['abandoned'])->toBe(1)->and($t->status)->toBe(PostStatus::Failed)->and($t->error_code)->toBe('cuenta_no_disponible')->and($t->error_message)->not->toBeEmpty();
    Notification::assertSentTo($this->user, PublishFailed::class);
})->with(['borrada', 'desconectada']);

test('N2: con la cuenta pendiente de reconectar espera el plazo configurable y despues falla con motivo claro', function () {
    config(['publishing.reconnect_grace_hours' => 72]);
    [$post, $target, $account] = i2Processing(['status' => SocialAccount::STATUS_RECONNECT]);

    app(PublishRecovery::class)->run();
    expect($target->fresh()->status)->toBe(PostStatus::Processing); // dentro del plazo: se espera la reconexion

    Carbon::setTestNow(now()->addHours(73));
    $result = app(PublishRecovery::class)->run();

    $t = $target->fresh();
    expect($result['abandoned'])->toBe(1)->and($t->status)->toBe(PostStatus::Failed)->and($t->error_code)->toBe('cuenta_no_disponible')
        ->and($t->error_message)->toContain('72 h');
    Notification::assertSentTo($this->user, PublishFailed::class);
});

test('N2: una cuenta activa no se toca', function () {
    Queue::fake(); // el sondeo normal de una cuenta activa no es lo que se prueba aqui
    [$post, $target] = i2Processing();

    $r = app(PublishRecovery::class)->run();

    expect([$r['abandoned'], $target->fresh()->error_code, $target->fresh()->status])->toBe([0, null, PostStatus::Processing]);
});

test('N2: el usuario puede borrar y cancelar (a borrador) una publicacion procesando cuya cuenta no esta activa', function (string $op) {
    [$post, $target] = i2Processing(['status' => SocialAccount::STATUS_RECONNECT]);

    if ($op === 'borrar') {
        $this->service->delete($this->user, $post->fresh());
        expect(Post::withoutUserScope()->find($post->id))->toBeNull()->and(PostTarget::withoutUserScope()->find($target->id))->toBeNull();
    } else {
        $this->service->unschedule($this->user, $post->fresh());
        $t = $target->fresh();
        expect($t->status)->toBe(PostStatus::Draft)->and($t->external_reference)->toBeNull();
    }
})->with(['borrar', 'cancelar']);

test('N2: con la cuenta ACTIVA un destino procesando sigue sin poderse borrar ni cancelar', function () {
    [$post, $target] = i2Processing();

    expect(fn () => $this->service->delete($this->user, $post->fresh()))->toThrow(PostValidationException::class);
    expect(fn () => $this->service->unschedule($this->user, $post->fresh()))->toThrow(PostValidationException::class);
    expect($target->fresh()->status)->toBe(PostStatus::Processing);
});
