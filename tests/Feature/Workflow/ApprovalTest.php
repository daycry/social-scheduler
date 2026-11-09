<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PublishPostTarget;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\PublishOutcomes;
use App\Services\PublishRequestFactory;
use App\Services\SocialAccountService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// T-19 aprobaciones: pendiente -> aprobado -> programada, o rechazado con comentario. Un post sin aprobar no se publica por ninguna via.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-09 12:00:00');
    $this->owner = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->stranger = User::factory()->create();
    $this->brand = Brand::factory()->for($this->owner)->create(['timezone' => 'UTC']);
    $this->fb = SocialAccount::factory()->for($this->owner)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    BrandCollaborator::query()->create(['brand_id' => $this->brand->id, 'owner_id' => $this->owner->id, 'user_id' => $this->approver->id, 'role' => 'aprobador']);
});

function approvalPost(array $over = []): Post
{
    return app(PostService::class)->create(test()->owner, test()->brand, array_merge([
        'text' => 'Hola', 'schedule' => false, 'scheduled_at' => '2026-11-10T10:00', 'media' => [],
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ], $over));
}

test('un post con requires_approval nace como borrador pendiente y no se puede programar', function () {
    $post = approvalPost(['requires_approval' => true, 'schedule' => true]);

    expect($post->status)->toBe(PostStatus::Draft)->and($post->approval_status)->toBe('pendiente');
    expect(fn () => app(PostService::class)->schedule(test()->owner, $post))->toThrow(PostValidationException::class);
    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

test('flujo completo: pedir, aprobar por el aprobador invitado, programar', function () {
    $post = approvalPost();
    $this->actingAs($this->owner)->postJson(route('workflow.approval.request', $post->id))->assertOk()->assertJsonPath('post.approval_status', 'pendiente');
    expect($this->approver->fresh()->notifications()->where('data->type', 'approval_requested')->count())->toBe(1);

    $this->actingAs($this->approver)->postJson(route('workflow.approval.approve', $post->id))->assertOk()->assertJsonPath('post.approval_status', 'aprobado');
    expect($this->owner->fresh()->notifications()->where('data->type', 'approval_approved')->count())->toBe(1);

    $scheduled = app(PostService::class)->schedule($this->owner, $post->fresh());
    expect($scheduled->status)->toBe(PostStatus::Scheduled);
});

test('rechazar exige comentario, deja el post en borrador y avisa al propietario', function () {
    $post = approvalPost(['requires_approval' => true]);

    $this->actingAs($this->approver)->postJson(route('workflow.approval.reject', $post->id), ['comment' => '  '])->assertStatus(422);
    $this->actingAs($this->approver)->postJson(route('workflow.approval.reject', $post->id), ['comment' => 'Falta el enlace'])
        ->assertOk()->assertJsonPath('post.approval_status', 'rechazado')->assertJsonPath('post.approval_comment', 'Falta el enlace');

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
    expect($this->owner->notifications()->where('data->type', 'approval_rejected')->first()->data['message'])->toContain('Falta el enlace');
    // Un rechazado sigue sin poder programarse hasta pedir de nuevo.
    expect(fn () => app(PostService::class)->schedule($this->owner, $post->fresh()))->toThrow(PostValidationException::class);
    $this->actingAs($this->owner)->postJson(route('workflow.approval.request', $post->id))->assertOk()->assertJsonPath('post.approval_status', 'pendiente');
});

test('aprobar y rechazar son idempotentes y no se pisan', function () {
    $post = approvalPost(['requires_approval' => true]);
    $this->actingAs($this->approver)->postJson(route('workflow.approval.approve', $post->id))->assertOk();
    $this->actingAs($this->approver)->postJson(route('workflow.approval.approve', $post->id))->assertOk();
    expect($this->owner->notifications()->where('data->type', 'approval_approved')->count())->toBe(1);

    $this->actingAs($this->approver)->postJson(route('workflow.approval.reject', $post->id), ['comment' => 'tarde'])->assertStatus(422);
    expect($post->fresh()->approval_status)->toBe('aprobado');
});

test('un extrano o un colaborador revocado no aprueba y recibe un 404 uniforme (sin IDOR)', function () {
    $post = approvalPost(['requires_approval' => true]);

    $this->actingAs($this->stranger)->postJson(route('workflow.approval.approve', $post->id))->assertNotFound();
    $this->actingAs($this->stranger)->postJson(route('workflow.approval.approve', 999999))->assertNotFound();
    $this->actingAs($this->stranger)->postJson(route('workflow.approval.request', $post->id))->assertNotFound();

    $this->actingAs($this->owner)->deleteJson(route('workflow.collaborators.destroy', [$this->brand->id, BrandCollaborator::query()->first()->id]))->assertOk();
    $this->actingAs($this->approver)->postJson(route('workflow.approval.approve', $post->id))->assertNotFound();
    expect($post->fresh()->approval_status)->toBe('pendiente');
});

test('un aprobador no puede pedir la aprobacion ni gestionar ajenas, pero si ve las pendientes', function () {
    $post = approvalPost(['requires_approval' => true]);

    $this->actingAs($this->approver)->postJson(route('workflow.approval.request', $post->id))->assertNotFound();
    $this->actingAs($this->approver)->getJson(route('workflow.approvals.pending'))->assertOk()->assertJsonPath('pending.0.id', $post->id);
    $this->actingAs($this->stranger)->getJson(route('workflow.approvals.pending'))->assertOk()->assertJsonCount(0, 'pending');
});

test('editar el contenido de un post aprobado lo devuelve a pendiente y a borrador; guardar sin cambios no', function () {
    $post = approvalPost(['requires_approval' => true, 'scheduled_at' => '2026-11-10T10:00']);
    $this->actingAs($this->approver)->postJson(route('workflow.approval.approve', $post->id))->assertOk();
    $service = app(PostService::class);
    $service->schedule($this->owner, $post->fresh());
    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);

    // Mismo contenido (solo la fecha): sigue aprobado y programado.
    $service->reschedule($this->owner, $post->fresh(), '2026-11-11T10:00');
    expect($post->fresh()->approval_status)->toBe('aprobado')->and($post->fresh()->status)->toBe(PostStatus::Scheduled);

    // Texto distinto: vuelve a pendiente, a borrador y avisa a los aprobadores.
    $service->update($this->owner, $post->fresh(), ['text' => 'Otro', 'schedule' => true, 'scheduled_at' => '2026-11-11T10:00', 'media' => [], 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']]]);
    $fresh = $post->fresh();
    expect($fresh->approval_status)->toBe('pendiente')->and($fresh->status)->toBe(PostStatus::Draft)
        ->and($this->approver->notifications()->where('data->type', 'approval_requested')->count())->toBe(2);
});

test('pedir la aprobacion de un post programado lo devuelve a borrador', function () {
    $post = approvalPost(['schedule' => true]);
    expect($post->status)->toBe(PostStatus::Scheduled);

    $this->actingAs($this->owner)->postJson(route('workflow.approval.request', $post->id))->assertOk();

    expect($post->fresh()->status)->toBe(PostStatus::Draft)->and($post->fresh()->targets()->first()->status)->toBe(PostStatus::Draft);
});

test('un post sin aprobar nunca se publica: ni el despachador ni el job ni publicar ahora', function () {
    $post = approvalPost(['schedule' => true]);
    // Forzamos el caso peligroso: programado en BD pero con aprobacion pendiente (p. ej. cambio directo o carrera).
    Post::withoutUserScope()->whereKey($post->id)->update(['approval_status' => 'pendiente', 'scheduled_at' => now()->subMinute()]);
    $target = $post->targets()->first();

    expect(DispatchDuePosts::dueTargets()->all())->toBe([]);

    (new PublishPostTarget($target->id))->handle(app(ConnectorRegistry::class), app(PublishOutcomes::class), app(PublishRequestFactory::class), app(SocialAccountService::class));
    expect($target->fresh()->status)->toBe(PostStatus::Scheduled)->and($target->fresh()->attempts)->toBe(0);

    expect(fn () => app(PostService::class)->publishNow($this->owner, $post->fresh()))->toThrow(PostValidationException::class);

    // Aprobado: ya sale.
    Post::withoutUserScope()->whereKey($post->id)->update(['approval_status' => 'aprobado']);
    expect(DispatchDuePosts::dueTargets()->all())->toBe([$target->id]);
});

test('la API REST aprueba y rechaza con la misma respuesta que la web y audita', function () {
    $post = approvalPost(['requires_approval' => true]);
    $key = $this->approver->createToken('k', ['posts:write'])->plainTextToken;

    $this->withToken($key)->postJson('/api/v1/posts/'.$post->id.'/approval/approve')->assertOk()->assertJsonPath('post.approval_status', 'aprobado');
    expect(AuditLog::query()->where('action', 'approve_post')->where('channel', 'api')->where('outcome', 'ok')->exists())->toBeTrue();

    $readOnly = $this->approver->createToken('r', ['posts:read'])->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($readOnly)->postJson('/api/v1/posts/'.$post->id.'/approval/reject', ['comment' => 'x'])->assertForbidden();
});

test('invitar es uniforme: la respuesta no distingue un email registrado de otro que no lo esta, y limita el ritmo', function () {
    $known = $this->actingAs($this->owner)->postJson(route('workflow.collaborators.store', $this->brand->id), ['email' => $this->stranger->email, 'role' => 'aprobador']);
    $unknown = $this->actingAs($this->owner)->postJson(route('workflow.collaborators.store', $this->brand->id), ['email' => 'nadie@ejemplo.test', 'role' => 'aprobador']);

    expect($known->status())->toBe($unknown->status())->and($known->json())->toBe($unknown->json());
    expect(BrandCollaborator::query()->where('user_id', $this->stranger->id)->exists())->toBeTrue()
        ->and(BrandCollaborator::query()->count())->toBe(2)
        ->and($this->stranger->notifications()->where('data->type', 'collaborator_invited')->count())->toBe(1);

    // Solo el propietario invita.
    $this->actingAs($this->approver)->postJson(route('workflow.collaborators.store', $this->brand->id), ['email' => 'x@y.test'])->assertNotFound();

    for ($i = 0; $i < 12; $i++) {
        $last = $this->actingAs($this->owner)->postJson(route('workflow.collaborators.store', $this->brand->id), ['email' => "n$i@ejemplo.test"]);
    }
    expect($last->status())->toBe(429);
});
