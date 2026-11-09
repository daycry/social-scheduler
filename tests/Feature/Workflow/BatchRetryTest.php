<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\IdempotencyKey;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\PublishOutcomes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// T-19 lotes (resultado por elemento, permisos por elemento, idempotencia) y reintento manual idempotente.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-09 12:00:00');
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    BrandCollaborator::query()->create(['brand_id' => $this->brand->id, 'owner_id' => $this->user->id, 'user_id' => $this->approver->id, 'role' => 'aprobador']);
});

function batchPost(array $over = [], ?User $as = null, ?Brand $brand = null, ?SocialAccount $acc = null): Post
{
    $t = test();

    return app(PostService::class)->create($as ?? $t->user, $brand ?? $t->brand, array_merge([
        'text' => 'Lote', 'schedule' => true, 'scheduled_at' => '2026-11-12T10:00', 'media' => [],
        'targets' => [['account_id' => ($acc ?? $t->fb)->id, 'content_type' => 'facebook_text']],
    ], $over));
}

function foreignPost(): Post
{
    $brand = Brand::factory()->for(test()->other)->create(['timezone' => 'UTC']);
    $acc = SocialAccount::factory()->for(test()->other)->create(['brand_id' => $brand->id, 'network' => Network::Facebook]);

    return batchPost([], test()->other, $brand, $acc);
}

test('reprogramar desplazando dias y horas: resultado por elemento, parcial y sin parar el lote', function () {
    $a = batchPost();
    $b = batchPost(['scheduled_at' => '2026-11-13T10:00']);
    $published = batchPost();
    $published->targets()->update(['status' => 'publicada']);
    $foreign = foreignPost();

    $res = $this->actingAs($this->user)->postJson(route('workflow.batch'), ['action' => 'reschedule', 'post_ids' => [$a->id, $published->id, $foreign->id, 999999, $b->id], 'shift_days' => 2, 'shift_hours' => 3])
        ->assertOk()->assertJsonPath('total', 5)->assertJsonPath('ok', 2)->assertJsonPath('failed', 3);

    $by = collect($res->json('results'))->keyBy('post_id');
    expect($by[$a->id]['ok'])->toBeTrue()->and($by[$b->id]['ok'])->toBeTrue()
        ->and($by[$published->id]['ok'])->toBeFalse()->and($by[$published->id]['error'])->not->toBe('')
        // El ajeno y el inexistente son indistinguibles.
        ->and($by[$foreign->id])->toBe(['post_id' => $foreign->id, 'ok' => false, 'error' => 'Publicacion no encontrada.'])
        ->and($by[999999]['error'])->toBe('Publicacion no encontrada.');
    expect($a->fresh()->scheduled_at->format('Y-m-d H:i'))->toBe('2026-11-14 13:00')->and($b->fresh()->scheduled_at->format('Y-m-d H:i'))->toBe('2026-11-15 13:00')
        ->and($foreign->fresh()->scheduled_at->format('Y-m-d H:i'))->toBe('2026-11-12 10:00');
});

test('cancelar, programar y borrar en lote; un borrador sin fecha futura da error aislado', function () {
    $a = batchPost();
    $b = batchPost();
    $draft = batchPost(['schedule' => false]);
    $past = batchPost(['schedule' => false, 'scheduled_at' => '2026-11-01T10:00']);

    $this->actingAs($this->user)->postJson(route('workflow.batch'), ['action' => 'cancel', 'post_ids' => [$a->id, $b->id]])->assertOk()->assertJsonPath('ok', 2);
    expect($a->fresh()->status)->toBe(PostStatus::Draft);

    $res = $this->actingAs($this->user)->postJson(route('workflow.batch'), ['action' => 'schedule', 'post_ids' => [$a->id, $draft->id, $past->id]])->assertOk()->assertJsonPath('ok', 2)->assertJsonPath('failed', 1);
    expect(collect($res->json('results'))->firstWhere('post_id', $past->id)['ok'])->toBeFalse()->and($a->fresh()->status)->toBe(PostStatus::Scheduled);

    $this->actingAs($this->user)->postJson(route('workflow.batch'), ['action' => 'delete', 'post_ids' => [$a->id, $b->id]])->assertOk()->assertJsonPath('ok', 2);
    expect(Post::withoutUserScope()->find($a->id))->toBeNull();
});

test('aprobar y rechazar en lote respetan el permiso de cada elemento', function () {
    $mine = batchPost(['schedule' => false, 'requires_approval' => true]);
    $other = foreignPost();
    Post::withoutUserScope()->whereKey($other->id)->update(['approval_status' => 'pendiente']);

    $res = $this->actingAs($this->approver)->postJson(route('workflow.batch'), ['action' => 'approve', 'post_ids' => [$mine->id, $other->id]])->assertOk();
    expect($res->json('ok'))->toBe(1)->and($mine->fresh()->approval_status)->toBe('aprobado')->and($other->fresh()->approval_status)->toBe('pendiente');

    // El aprobador no puede cancelar ni borrar: no es el propietario.
    $this->actingAs($this->approver)->postJson(route('workflow.batch'), ['action' => 'delete', 'post_ids' => [$mine->id]])->assertOk()->assertJsonPath('ok', 0);
    expect(Post::withoutUserScope()->find($mine->id))->not->toBeNull();

    $this->actingAs($this->approver)->postJson(route('workflow.batch'), ['action' => 'reject', 'post_ids' => [$mine->id]])->assertStatus(422);
    $p2 = batchPost(['schedule' => false, 'requires_approval' => true]);
    $this->actingAs($this->user)->postJson(route('workflow.batch'), ['action' => 'reject', 'post_ids' => [$p2->id], 'comment' => 'No'])->assertOk()->assertJsonPath('ok', 1);
    expect($p2->fresh()->approval_status)->toBe('rechazado');
});

test('validacion del lote: accion, tope de 100, desplazamiento obligatorio', function () {
    $this->actingAs($this->user)->postJson(route('workflow.batch'), ['action' => 'explotar', 'post_ids' => [1]])->assertStatus(422);
    $this->actingAs($this->user)->postJson(route('workflow.batch'), ['action' => 'cancel', 'post_ids' => range(1, 101)])->assertStatus(422);
    $this->actingAs($this->user)->postJson(route('workflow.batch'), ['action' => 'cancel', 'post_ids' => []])->assertStatus(422);
    $this->actingAs($this->user)->postJson(route('workflow.batch'), ['action' => 'reschedule', 'post_ids' => [1]])->assertStatus(422);
});

test('Idempotency-Key: la misma peticion repite la respuesta sin ejecutar otra vez; otro cuerpo con la misma clave es 422', function () {
    $a = batchPost();
    $body = ['action' => 'reschedule', 'post_ids' => [$a->id], 'shift_days' => 1];
    $headers = ['Idempotency-Key' => 'lote-001'];

    $first = $this->actingAs($this->user)->postJson(route('workflow.batch'), $body, $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'false');
    $second = $this->actingAs($this->user)->postJson(route('workflow.batch'), $body, $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    expect($a->fresh()->scheduled_at->format('Y-m-d'))->toBe('2026-11-13') // solo un desplazamiento
        ->and($second->json('results'))->toBe($first->json('results'))->and($second->json('replayed'))->toBeTrue();

    $this->actingAs($this->user)->postJson(route('workflow.batch'), ['shift_days' => 5] + $body, $headers)->assertStatus(422);
    // La misma clave de otro usuario es independiente.
    $this->actingAs($this->other)->postJson(route('workflow.batch'), ['action' => 'cancel', 'post_ids' => [$a->id]], $headers)->assertOk()->assertJsonPath('ok', 0);
    $this->actingAs($this->user)->postJson(route('workflow.batch'), $body, ['Idempotency-Key' => 'mala clave!'])->assertStatus(422);
    // Una ejecucion que falla libera la clave.
    $this->actingAs($this->user)->postJson(route('workflow.batch'), ['action' => 'nope', 'post_ids' => [1]], ['Idempotency-Key' => 'k2'])->assertStatus(422);
    expect(IdempotencyKey::query()->where('key', 'k2')->exists())->toBeFalse();
});

test('una peticion con la clave aun en curso devuelve 409', function () {
    IdempotencyKey::query()->create(['user_id' => $this->user->id, 'scope' => 'post-batch', 'key' => 'en-curso', 'request_hash' => hash('sha256', json_encode(['action' => 'cancel', 'post_ids' => [1]]))]);

    $this->actingAs($this->user)->postJson(route('workflow.batch'), ['action' => 'cancel', 'post_ids' => [1]], ['Idempotency-Key' => 'en-curso'])->assertStatus(409);
});

test('el lote tiene rate limit por usuario', function () {
    $last = null;
    for ($i = 0; $i < 12; $i++) {
        $last = $this->actingAs($this->user)->postJson(route('workflow.batch'), ['action' => 'cancel', 'post_ids' => [999999]]);
    }
    expect($last->status())->toBe(429);
});

// ---------------------------------------------------------------- reintento

function failedPost(int $attempts = 4): Post
{
    $post = batchPost(['scheduled_at' => '2026-11-09T13:00']);
    Carbon::setTestNow('2026-11-09 14:00:00');
    $post->targets()->update(['status' => 'fallida', 'attempts' => $attempts, 'error_code' => 'reintentos_agotados', 'error_message' => 'No se pudo']);
    $post->syncStatus();

    return $post->fresh();
}

test('Reintentar vuelve a programar el destino fallido, lo encola una sola vez y repetirlo no hace nada', function () {
    $post = failedPost();
    $target = $post->targets()->first();
    expect($post->status)->toBe(PostStatus::Failed);

    $this->actingAs($this->user)->postJson(route('workflow.retry', $post->id))->assertOk()->assertJsonPath('retried', 1);
    Queue::assertPushed(PublishPostTarget::class, 1);
    expect($target->fresh()->status)->toBe(PostStatus::Scheduled)->and($target->fresh()->attempts)->toBe(0)->and($target->fresh()->error_code)->toBeNull();

    // Segundo pulso (doble clic): ya no hay fallidos, no se encola otra vez.
    $this->actingAs($this->user)->postJson(route('workflow.retry', $post->id))->assertOk()->assertJsonPath('retried', 0)->assertJsonPath('skipped', 1);
    Queue::assertPushed(PublishPostTarget::class, 1);
});

test('Reintentar no toca lo que esta publicado o en curso y un ajeno recibe 404', function () {
    $post = batchPost();
    $target = $post->targets()->first();
    foreach (['publicada', 'publicando', 'procesando'] as $status) {
        $target->forceFill(['status' => $status])->save();
        $this->actingAs($this->user)->postJson(route('workflow.retry', $post->id))->assertOk()->assertJsonPath('retried', 0);
        expect($target->fresh()->status->value)->toBe($status);
    }
    Queue::assertNothingPushed();
    $this->actingAs($this->other)->postJson(route('workflow.retry', $post->id))->assertNotFound();
});

test('Reintentar un post pendiente de aprobacion se rechaza', function () {
    $post = failedPost();
    Post::withoutUserScope()->whereKey($post->id)->update(['approval_status' => 'pendiente']);

    $this->actingAs($this->user)->postJson(route('workflow.retry', $post->id))->assertStatus(422);
    Queue::assertNothingPushed();
});

test('los fallos transitorios reintentan con backoff creciente y acotado; agotados fallan', function () {
    config(['publishing.max_attempts' => 3, 'publishing.backoff_seconds' => [60, 300, 900]]);
    $post = batchPost(['scheduled_at' => '2026-11-10T11:00']);
    Post::withoutUserScope()->whereKey($post->id)->update(['scheduled_at' => now()->subMinute()]);
    $target = $post->targets()->first();
    $outcomes = app(PublishOutcomes::class);

    $waits = [];
    foreach ([1, 2] as $attempt) {
        PostTarget::withoutUserScope()->whereKey($target->id)->update(['status' => 'publicando', 'attempts' => $attempt]);
        $outcomes->transient($target->fresh(), 'timeout', 'timeout');
        $t = $target->fresh();
        expect($t->status)->toBe(PostStatus::Scheduled);
        $waits[] = (int) now()->diffInSeconds($t->next_attempt_at);
    }
    expect($waits)->toBe([60, 300]);

    PostTarget::withoutUserScope()->whereKey($target->id)->update(['status' => 'publicando', 'attempts' => 3]);
    $outcomes->transient($target->fresh(), 'timeout', 'timeout');
    expect($target->fresh()->status)->toBe(PostStatus::Failed)->and($target->fresh()->error_code)->toBe('reintentos_agotados');
});
