<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Jobs\DispatchDuePosts;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PublishAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\ReconnectRequired;
use App\Services\MediaValidator;
use App\Services\PostService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakePublisher;

// Revision Fase 3 intento 1: #3 (reschedule), #4 (TOCTOU), #6 (specs), #10, #12, #13, #14, #16, #18.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Notification::fake();
    config(['publishing.backoff_seconds' => [60, 300, 900], 'publishing.max_attempts' => 4]);

    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->publisher = new FakePublisher;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Instagram, $this->publisher));
    $this->service = app(PostService::class);
});

function rvPost(array $postAttrs = [], array $targetAttrs = []): PostTarget
{
    $post = Post::factory()->create(array_merge(['brand_id' => test()->brand->id, 'user_id' => test()->user->id, 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled], $postAttrs));

    return PostTarget::factory()->create(array_merge(['post_id' => $post->id, 'user_id' => test()->user->id, 'social_account_id' => test()->account->id, 'content_type' => ContentType::InstagramImage], $targetAttrs));
}

function rvTick(int $seconds = 0): int
{
    Carbon::setTestNow(now()->addSeconds($seconds));

    return app(DispatchDuePosts::class)();
}

// ------------------------------------------------------------------ #3 reschedule

test('Revision #3: reprogramar un destino fallido limpia referencia, intentos y sondeo, conserva el historial y vuelve a publicar de cero', function () {
    $target = rvPost(['status' => PostStatus::Failed], [
        'status' => PostStatus::Failed, 'attempts' => 4, 'external_reference' => 'V1', 'external_id' => 'viejo', 'progress' => ['parent' => 'X'],
        'error_code' => 'reintentos_agotados', 'error_message' => 'x', 'started_at' => now()->subHour(), 'next_attempt_at' => now()->subMinutes(30),
    ]);
    $attempt = new PublishAttempt(['post_target_id' => $target->id, 'attempt_no' => 4, 'outcome' => PublishAttempt::OUTCOME_PERMANENT]);
    $attempt->user_id = $this->user->id;
    $attempt->save();

    $this->service->reschedule($this->user, $target->post->fresh(), now()->addMinutes(2));

    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Scheduled)->and($t->attempts)->toBe(0)->and($t->external_reference)->toBeNull()->and($t->external_id)->toBeNull()
        ->and($t->progress)->toBeNull()->and($t->error_code)->toBeNull()->and($t->started_at)->toBeNull()->and($t->next_attempt_at)->toBeNull();
    expect(PublishAttempt::withoutGlobalScopes()->where('post_target_id', $target->id)->count())->toBe(1); // historial intacto

    rvTick(180);
    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Published)->and($t->attempts)->toBe(1)
        ->and($this->publisher->publishCalls)->toBe(1)->and($this->publisher->findCalls)->toBe(0); // intento nuevo, sin re-sondear el viejo
});

test('Revision #3: tambien se olvida el publish_id de TikTok y la referencia de un video de Facebook al reprogramar', function (Network $network, ContentType $type, string $reference) {
    $account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => $network]);
    $target = rvPost(['status' => PostStatus::Failed], [
        'social_account_id' => $account->id, 'network' => $network, 'content_type' => $type, 'status' => PostStatus::Failed, 'attempts' => 2, 'external_reference' => $reference,
    ]);

    $this->service->reschedule($this->user, $target->post->fresh(), now()->addMinutes(5));

    expect($target->fresh())->external_reference->toBeNull()->attempts->toBe(0)->status->toBe(PostStatus::Scheduled);
})->with([
    'tiktok' => [Network::TikTok, ContentType::TikTokVideo, 'v_pub_url~v2.123'],
    'facebook video' => [Network::Facebook, ContentType::FacebookVideo, '987654321'],
]);

// ------------------------------------------------------------------ #4 TOCTOU / #18 historial

test('Revision #4: editar, borrar, reprogramar y desprogramar con un destino ya reclamado (publicando) se rechazan sin tocarlo', function (string $op) {
    $target = rvPost(targetAttrs: ['status' => PostStatus::Publishing, 'attempts' => 1, 'started_at' => now()]);
    $post = $target->post->fresh(); // el modelo en memoria es de ANTES del claim: no se puede fiar de su estado
    $attempt = new PublishAttempt(['post_target_id' => $target->id, 'attempt_no' => 1, 'outcome' => PublishAttempt::OUTCOME_STARTED]);
    $attempt->user_id = $this->user->id;
    $attempt->save();

    $act = match ($op) {
        'update' => fn () => $this->service->update($this->user, $post, ['text' => 'Nuevo', 'schedule' => true, 'scheduled_at' => now()->addDay(), 'media' => [], 'targets' => []]),
        'delete' => fn () => $this->service->delete($this->user, $post),
        'reschedule' => fn () => $this->service->reschedule($this->user, $post, now()->addDay()),
        'unschedule' => fn () => $this->service->unschedule($this->user, $post),
    };

    expect($act)->toThrow(PostValidationException::class);
    expect($target->fresh())->status->toBe(PostStatus::Publishing)->attempts->toBe(1);
    expect(Post::withoutUserScope()->find($post->id))->not->toBeNull()
        ->and(PublishAttempt::withoutGlobalScopes()->where('post_target_id', $target->id)->count())->toBe(1);
})->with(['update', 'delete', 'reschedule', 'unschedule']);

test('Revision #4: el estado de los destinos se relee DENTRO de la transaccion (donde toma lockForUpdate), nunca antes', function () {
    $target = rvPost();
    $levels = [];
    DB::listen(function ($query) use (&$levels) {
        if (str_contains($query->sql, 'from "post_targets"') && str_starts_with(strtolower($query->sql), 'select')) {
            $levels[] = DB::transactionLevel();
        }
    });

    $this->service->delete($this->user, $target->post->fresh());

    expect($levels)->not->toBeEmpty()->and(min($levels))->toBeGreaterThanOrEqual(1);
});

test('Revision #4/#18: editar conserva el destino (mismo id e historial de intentos), borra solo los que sobran y resetea un destino con error', function () {
    $media = MediaAsset::factory()->for($this->user)->create();
    $fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $post = $this->service->create($this->user, $this->brand, [
        'text' => 'Hola', 'scheduled_at' => now()->addDay(), 'schedule' => true, 'media' => [$media->id],
        'targets' => [['account_id' => $this->account->id, 'content_type' => 'instagram_image'], ['account_id' => $fb->id, 'content_type' => 'facebook_photo']],
    ]);
    $ig = $post->targets->firstWhere('network', Network::Instagram);
    $facebook = $post->targets->firstWhere('network', Network::Facebook);
    $ig->forceFill(['status' => PostStatus::Failed, 'attempts' => 3, 'external_reference' => 'C9', 'error_code' => 'rechazada'])->save();
    $attempt = new PublishAttempt(['post_target_id' => $ig->id, 'attempt_no' => 3, 'outcome' => PublishAttempt::OUTCOME_PERMANENT]);
    $attempt->user_id = $this->user->id;
    $attempt->save();

    $updated = $this->service->update($this->user, $post->fresh(), [
        'text' => 'Hola 2', 'scheduled_at' => now()->addDays(2), 'schedule' => true, 'media' => [$media->id],
        'targets' => [['account_id' => $this->account->id, 'content_type' => 'instagram_image']],
    ]);

    expect($updated->targets)->toHaveCount(1)->and($updated->targets[0]->id)->toBe($ig->id); // el destino se actualiza, no se recrea
    $fresh = $ig->fresh();
    expect($fresh->status)->toBe(PostStatus::Scheduled)->and($fresh->attempts)->toBe(0)->and($fresh->external_reference)->toBeNull()->and($fresh->error_code)->toBeNull();
    expect(PublishAttempt::withoutGlobalScopes()->where('post_target_id', $ig->id)->count())->toBe(1)  // historial conservado
        ->and(PostTarget::withoutUserScope()->find($facebook->id))->toBeNull();                       // el que sobra, borrado
});

test('Revision #4: un destino publicado nunca se borra por debajo (la edicion se rechaza entera)', function () {
    $published = rvPost(targetAttrs: ['status' => PostStatus::Published, 'external_id' => 'X1']);
    $media = MediaAsset::factory()->for($this->user)->create();

    expect(fn () => $this->service->update($this->user, $published->post->fresh(), [
        'text' => 'x', 'scheduled_at' => now()->addDay(), 'schedule' => true, 'media' => [$media->id],
        'targets' => [['account_id' => $this->account->id, 'content_type' => 'instagram_image']],
    ]))->toThrow(PostValidationException::class);

    expect($published->fresh())->status->toBe(PostStatus::Published)->external_id->toBe('X1');
    expect(PostStatus::Published->canBeDeleted())->toBeFalse()->and(PostStatus::Publishing->canBeDeleted())->toBeFalse()
        ->and(PostStatus::Processing->canBeDeleted())->toBeFalse()->and(PostStatus::Scheduled->canBeDeleted())->toBeTrue();
});

// ------------------------------------------------------------------ #6 specs

test('Revision #6: una foto de movil 4032x3024 JPEG es valida en Instagram (imagen y carrusel); el minimo duro de 320 px sigue', function () {
    $validator = app(MediaValidator::class);
    $photo = MediaAsset::factory()->for($this->user)->create(['mime' => 'image/jpeg', 'width' => 4032, 'height' => 3024, 'size' => 3 * 1024 * 1024]);
    $tiny = MediaAsset::factory()->for($this->user)->create(['mime' => 'image/jpeg', 'width' => 200, 'height' => 200, 'size' => 10_000]);

    expect($validator->validate(ContentType::InstagramImage, [$photo], 'x'))->toBe([])
        ->and($validator->validate(ContentType::InstagramCarousel, [$photo, $photo], 'x'))->toBe([])
        ->and($validator->validate(ContentType::InstagramStory, [$photo]))->toBe([])
        ->and(implode(' ', $validator->validate(ContentType::InstagramImage, [$tiny], 'x')))->toContain('al menos 320 px');
});

test('Revision #6: el minimo «recomendado» de 540 px de un reel es un aviso, no un error', function () {
    $validator = app(MediaValidator::class);
    $video = MediaAsset::factory()->video(duration: 20)->for($this->user)->create(['width' => 360, 'height' => 640, 'fps' => 30, 'mime' => 'video/mp4', 'codec' => 'h264', 'audio_codec' => 'aac']);

    expect($validator->validate(ContentType::InstagramReel, [$video]))->toBe([])
        ->and($validator->warnings(ContentType::InstagramReel, [$video]))->toHaveCount(2)
        ->and($validator->warnings(ContentType::InstagramReel, [$video])[0])->toContain('recomiendan');
});

// ------------------------------------------------------------------ #10 / #16

test('Revision #10/#16: max_lateness_hours queda marcado como decision pendiente y posts tiene indice por scheduled_at', function () {
    expect(file_get_contents(config_path('publishing.php')))->toContain('DECISION PENDIENTE DE CONFIRMAR');
    expect(Schema::hasIndex('posts', ['scheduled_at']))->toBeTrue()->and(Schema::hasColumn('post_targets', 'progress'))->toBeTrue();
});

test('Revision #9: app:media-prune esta programado cada hora en el scheduler', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.'|'.$e->expression);

    expect($events->first(fn ($e) => str_contains($e, 'app:media-prune')))->toEndWith('|0 * * * *');
});

// ------------------------------------------------------------------ #12 / #13 / #14

test('Revision #13: un destino colgado en el ultimo intento consulta la red antes de darlo por fallido', function () {
    $published = rvPost(targetAttrs: ['status' => PostStatus::Publishing, 'attempts' => 4, 'started_at' => now()->subMinutes(30)]);
    $this->publisher->externals[$published->id] = 'ext-ya-publicado';

    rvTick();

    expect($published->fresh())->status->toBe(PostStatus::Published)->external_id->toBe('ext-ya-publicado');
    expect($this->publisher->publishCalls)->toBe(0)->and($this->publisher->findCalls)->toBe(1);

    $lost = rvPost(targetAttrs: ['status' => PostStatus::Publishing, 'attempts' => 4, 'started_at' => now()->subMinutes(30)]);
    rvTick();

    expect($lost->fresh())->status->toBe(PostStatus::Failed)->error_code->toBe('reintentos_agotados');
    expect($this->publisher->publishCalls)->toBe(0); // nunca se publica otra vez
});

test('Revision #14: ReconnectRequired durante el sondeo marca la cuenta y deja el destino procesando (sin timeout); al reconectar se retoma', function () {
    $this->publisher->script = ['processing'];
    $this->publisher->pollScript = [new ReconnectRequiredException('Token revocado'), 'done'];
    config(['publishing.poll_max_minutes' => 10]);
    $target = rvPost();

    rvTick(); // publica -> procesando; sondeo inmediato (cola sync): credenciales rechazadas
    $t = $target->fresh();
    expect($t->status)->toBe(PostStatus::Processing)->and($t->error_code)->toBe('reconexion')->and($this->account->fresh()->status)->toBe(SocialAccount::STATUS_RECONNECT);
    Notification::assertSentToTimes($this->user, ReconnectRequired::class, 1);

    // Mucho mas de poll_max_minutes con la cuenta sin reconectar: no vence ni se sondea.
    expect(rvTick(3600))->toBe(0);
    expect($target->fresh()->status)->toBe(PostStatus::Processing)->and($this->publisher->pollCalls)->toBe(1);

    $this->account->fresh()->update(['status' => SocialAccount::STATUS_ACTIVE]);
    rvTick(60);
    expect([$target->fresh()->status->value, $target->fresh()->error_code])->toBe(['publicada', null]);
});

test('Revision #12: cuota agotada al publicar durante el sondeo aplica la politica: aplaza sin recrear el contenedor, o falla', function (string $policy) {
    config(['publishing.quota_policy' => $policy]);
    $this->publisher->script = ['processing'];
    $this->publisher->pollScript = [new QuotaExceededException('Limite de publicaciones alcanzado', now()->addHours(2)), 'done'];
    $target = rvPost();

    rvTick(); // publica -> procesando; sondeo inmediato: cuota agotada
    $t = $target->fresh();

    if ($policy === 'fail') {
        expect($t->status)->toBe(PostStatus::Failed)->and($t->error_code)->toBe('cuota');

        return;
    }

    expect($t->status)->toBe(PostStatus::Processing)->and($t->error_code)->toBe('cuota')->and($t->external_reference)->toBe('ref-'.$target->id)
        ->and($t->next_attempt_at->toDateTimeString())->toBe(now()->addHours(2)->toDateTimeString());
    expect(rvTick(3600))->toBe(0)->and($this->publisher->pollCalls)->toBe(1); // aun no toca

    rvTick(3601);
    expect($target->fresh()->status)->toBe(PostStatus::Published)->and($this->publisher->publishCalls)->toBe(1); // un solo publish: el contenedor no se recrea
})->with(['reschedule', 'fail']);
