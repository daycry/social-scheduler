<?php

use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\Post;
use App\Models\Studio\DesignShare;
use App\Models\User;
use App\Notifications\StudioDesignActivity;
use App\Services\Studio\StudioDocument;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $other = Brand::factory()->for($owner)->create();
    $this->approver = User::factory()->create();
    $this->document = StudioDocument::blank(1080, 1080);
    $this->visible = app(StudioService::class)->create($owner, $brand, ['name' => 'Diseño de la marca invitada', 'document' => $this->document]);
    $this->hidden = app(StudioService::class)->create($owner, $other, ['name' => 'Diseño de otra marca', 'document' => $this->document]);
    $this->membership = BrandCollaborator::query()->create([
        'owner_id' => $owner->id,
        'brand_id' => $brand->id,
        'user_id' => $this->approver->id,
        'role' => BrandCollaborator::ROLE_APPROVER,
    ]);
});

test('el estudio muestra al aprobador los diseños de su marca invitada', function () {
    $this->actingAs($this->approver)->get(route('studio.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Studio/Index')
            ->has('shared', 1)->where('shared.0.id', $this->visible->id));
});

test('el aprobador puede revisar un diseño y solo editarlo con acceso explícito por diseño', function () {
    $this->actingAs($this->approver)->getJson(route('studio.designs.show', $this->visible))->assertOk()
        ->assertJsonPath('access.can_approve', true)
        ->assertJsonPath('access.can_edit', false);

    $this->patchJson(route('studio.designs.update', $this->visible), [
        'revision' => $this->visible->revision, 'name' => 'Cambio no permitido', 'document' => $this->document,
    ])->assertForbidden();
    $this->getJson(route('studio.designs.show', $this->hidden))->assertNotFound();

    $share = DesignShare::query()->create([
        'owner_id' => $this->visible->user_id,
        'design_id' => $this->visible->id,
        'user_id' => $this->approver->id,
        'role' => 'editor',
    ]);
    $this->getJson(route('studio.designs.show', $this->visible))->assertOk()
        ->assertJsonPath('access.can_edit', true)->assertJsonPath('access.can_approve', true);

    $this->membership->delete();
    $this->getJson(route('studio.designs.show', $this->visible))->assertOk()
        ->assertJsonPath('access.can_edit', true)->assertJsonPath('access.can_approve', false);
    $share->delete();
    $this->getJson(route('studio.designs.show', $this->visible))->assertNotFound();
});

test('un diseño usa la bandeja de aprobaciones y solo se decide en la versión revisada', function () {
    Notification::fake();
    $owner = User::findOrFail($this->visible->user_id);
    $request = "/workflow/designs/{$this->visible->id}/approval/request";
    $decide = "/workflow/designs/{$this->visible->id}/approval/approve";
    $this->actingAs($this->approver)->postJson($request)->assertNotFound();
    $first = $this->actingAs($owner)->postJson($request)->assertOk()->json('content_signature');
    expect($first)->toBeString();
    $this->postJson($request)->assertOk()->assertJsonPath('content_signature', $first);
    Notification::assertSentToTimes($this->approver, StudioDesignActivity::class, 1);
    $this->postJson("/workflow/designs/{$this->hidden->id}/approval/request")->assertOk();
    Notification::assertSentToTimes($this->approver, StudioDesignActivity::class, 1);

    $this->actingAs($this->approver)->get(route('approvals.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('pending', 1)
            ->where('pending.0.kind', 'design')->where('pending.0.id', $this->visible->id));
    $this->postJson($decide, ['signature' => 'incorrecta'])->assertConflict();

    $this->actingAs($owner)->patchJson(route('studio.designs.update', $this->visible), [
        'revision' => $this->visible->revision, 'name' => 'Versión posterior', 'document' => $this->document,
    ])->assertOk();
    $this->actingAs($this->approver)->postJson($decide, ['signature' => $first])->assertConflict();

    $second = $this->actingAs($owner)->postJson($request)->assertOk()->json('content_signature');
    expect($second)->not->toBe($first);
    $reject = "/workflow/designs/{$this->visible->id}/approval/reject";
    $this->actingAs($this->approver)->postJson($reject, ['signature' => $second])->assertUnprocessable();
    $this->postJson($reject, ['signature' => $second, 'comment' => 'Ajusta el título'])->assertOk()
        ->assertJsonPath('approval_status', 'rechazado');
    $this->actingAs($owner)->get(route('approvals.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('mine', 1)
            ->where('mine.0.kind', 'design')->where('mine.0.approval_comment', 'Ajusta el título'));
    $this->patchJson(route('studio.designs.update', $this->visible), [
        'revision' => $this->visible->fresh()->revision, 'name' => 'Corregido', 'document' => $this->document,
    ])->assertOk();
    $this->get(route('approvals.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('mine', 1)
            ->where('mine.0.approval_current', false)->where('mine.0.approval_comment', 'Ajusta el título'));
    $third = $this->postJson($request)->assertOk()->json('content_signature');
    $this->actingAs($this->approver)->postJson($decide, ['signature' => $second])->assertConflict();
    $this->postJson($decide, ['signature' => $third])->assertOk()
        ->assertJsonPath('approval_status', 'aprobado');
    $this->postJson($decide, ['signature' => $third])->assertOk();
    Notification::assertSentToTimes($owner, StudioDesignActivity::class, 2);
    Notification::assertSentTo($owner, StudioDesignActivity::class, fn ($notification) => $notification->kind === 'approval_rejected'
        && $notification->toArray($owner)['url'] === '/approvals');
});

test('una instalación nueva guarda approver cuando la inserción omite el rol', function () {
    $id = DB::table('brand_collaborators')->insertGetId([
        'brand_id' => $this->membership->brand_id,
        'owner_id' => $this->membership->owner_id,
        'user_id' => User::factory()->create()->id,
    ]);

    expect(DB::table('brand_collaborators')->where('id', $id)->value('role'))->toBe(BrandCollaborator::ROLE_APPROVER);
});

test('la migración convierte el rol persistido y restaura el valor histórico al revertir', function () {
    // MySQL DDL commits implicitly, so this rollback simulation uses SQLite.
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('La simulación de ida y vuelta necesita DDL transaccional.');
    }
    $migration = require database_path('migrations/2026_11_17_185000_normalize_brand_collaborator_role.php');
    $migration->down();
    try {
        expect($this->membership->fresh()->role)->toBe('aprobador');
    } finally {
        $migration->up();
    }

    expect($this->membership->fresh()->role)->toBe(BrandCollaborator::ROLE_APPROVER);
});

test('la decisión respeta otra marca, la revocación, la papelera y el acceso del propietario', function () {
    $owner = User::findOrFail($this->visible->user_id);
    $visible = '/workflow/designs/'.$this->visible->id.'/approval/';
    $hidden = '/workflow/designs/'.$this->hidden->id.'/approval/';
    $signature = $this->actingAs($owner)->postJson($visible.'request')->assertOk()->json('content_signature');
    $otherSignature = $this->postJson($hidden.'request')->assertOk()->json('content_signature');

    $this->actingAs($this->approver)->postJson($hidden.'approve', ['signature' => $otherSignature])->assertNotFound();
    $this->membership->delete();
    $this->get(route('approvals.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('pending', 0));
    $this->postJson($visible.'approve', ['signature' => $signature])->assertNotFound();

    $share = DesignShare::query()->create([
        'owner_id' => $owner->id,
        'design_id' => $this->visible->id,
        'user_id' => $this->approver->id,
        'role' => 'commenter',
    ]);
    $this->getJson(route('studio.designs.show', $this->visible))->assertOk()->assertJsonMissingPath('design.approval_signature');
    $this->postJson($visible.'approve', ['signature' => $signature])->assertForbidden();
    $share->delete();

    $this->actingAs($owner)->postJson($visible.'approve', ['signature' => $signature])->assertOk()
        ->assertJsonPath('approval_status', 'aprobado');
    $this->visible->delete();
    $this->postJson($visible.'request')->assertNotFound();
});

test('HTTP publica el rol approver y rechaza la clave histórica', function () {
    $owner = User::findOrFail($this->visible->user_id);
    $guest = User::factory()->create();
    $token = $owner->createToken('brand-role-test', ['posts:approve'])->plainTextToken;
    $url = route('api.collaborators.store', $this->visible->brand_id);

    $this->withToken($token)->postJson($url, ['email' => $guest->email, 'role' => 'aprobador'])
        ->assertUnprocessable()->assertJsonValidationErrors('role');
    $this->postJson($url, ['email' => $guest->email, 'role' => 'approver'])->assertAccepted();
    expect(BrandCollaborator::query()->where('user_id', $guest->id)->sole()->role)->toBe('approver');
});

test('las solicitudes del estudio quedan en orden cronológico junto a las publicaciones', function () {
    $owner = User::findOrFail($this->visible->user_id);
    $this->actingAs($owner)->postJson('/workflow/designs/'.$this->visible->id.'/approval/request')->assertOk();
    $post = Post::factory()->create([
        'user_id' => $owner->id,
        'brand_id' => $this->visible->brand_id,
        'approval_status' => Post::APPROVAL_PENDING,
        'approval_requested_at' => now()->addMinute(),
    ]);

    $this->actingAs($this->approver)->get(route('approvals.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('pending', 2)
            ->where('pending.0.kind', 'design')->where('pending.0.id', $this->visible->id)
            ->where('pending.1.id', $post->id));
});

test('la pertenencia exige el propietario de la marca registrado en el diseño', function () {
    Notification::fake();
    $owner = User::findOrFail($this->visible->user_id);
    $this->membership->forceFill(['owner_id' => User::factory()->create()->id])->save();
    $this->actingAs($owner)->postJson('/workflow/designs/'.$this->visible->id.'/approval/request')->assertOk();
    Notification::assertNotSentTo($this->approver, StudioDesignActivity::class);
    $this->actingAs($this->approver)->get(route('studio.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('shared', 0));
    $this->get(route('approvals.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('pending', 0));
    $this->getJson(route('studio.designs.show', $this->visible))->assertNotFound();
});

test('un aprobador puede comentar y no puede usar las rutas de gestión', function (string $method, string $suffix, int $status, array $body = []) {
    $result = $this->actingAs($this->approver)->json($method, '/studio/designs/'.$this->visible->id.$suffix, $body);
    $result->assertStatus($status);
})->with([
    'listado de comentarios' => ['GET', '/comments', 200],
    'crear comentario' => ['POST', '/comments', 201, ['body' => 'Revisa el título']],
    'historial de revisiones' => ['GET', '/versions', 200],
    'historial de exportaciones' => ['GET', '/exports', 404],
    'consulta de subtítulos' => ['GET', '/captions', 404],
    'accesos de usuarios' => ['GET', '/shares', 403],
    'enlaces públicos' => ['GET', '/links', 403],
    'crear enlace público' => ['POST', '/links', 403],
    'guardar copia colaborativa' => ['POST', '/copies', 403],
    'restaurar una revisión' => ['POST', '/versions/1/restore', 403, ['revision' => 1]],
    'redimensionar el diseño' => ['POST', '/resize', 404, ['width' => 128, 'height' => 128]],
    'archivar el diseño' => ['DELETE', '', 404],
]);

test('el aprobador resuelve y borra sus comentarios con los permisos de la bandeja', function () {
    $base = '/studio/designs/'.$this->visible->id.'/comments';
    $owner = User::findOrFail($this->visible->user_id);
    $other = $this->actingAs($owner)->postJson($base, ['body' => 'Comentario del propietario'])
        ->assertCreated()->json('comment.id');

    $mine = $this->actingAs($this->approver)->postJson($base, ['body' => 'Comentario del aprobador'])
        ->assertCreated()->assertJsonPath('comment.can_resolve', true)->assertJsonPath('comment.can_delete', true)
        ->json('comment.id');
    $this->postJson($base.'/'.$mine.'/resolve')->assertOk()->assertJsonPath('resolved', true);
    $this->postJson($base.'/'.$mine.'/reopen')->assertOk()->assertJsonPath('resolved', false);
    $rows = collect($this->getJson($base)->assertOk()->json('comments'));
    $ownerRow = $rows->firstWhere('id', $other);
    expect($ownerRow['can_resolve'])->toBeFalse()->and($ownerRow['can_delete'])->toBeFalse();
    $reply = $this->postJson($base, ['body' => 'Respuesta del aprobador', 'parent_id' => $other])->assertCreated()
        ->assertJsonPath('comment.can_resolve', false)->assertJsonPath('comment.can_delete', true)->json('comment.id');
    $this->postJson($base.'/'.$reply.'/resolve')->assertForbidden();
    $this->deleteJson($base.'/'.$reply)->assertOk();
    $this->assertDatabaseMissing('studio_design_comments', ['id' => $reply]);
    $this->postJson($base.'/'.$other.'/resolve')->assertForbidden();
    $this->deleteJson($base.'/'.$other)->assertForbidden();

    $ownerReply = $this->actingAs($owner)->postJson($base, ['body' => 'Respuesta del propietario', 'parent_id' => $mine])
        ->assertCreated()->json('comment.id');
    $this->actingAs($this->approver)->deleteJson($base.'/'.$ownerReply)->assertForbidden();
    $this->assertDatabaseHas('studio_design_comments', ['id' => $ownerReply]);
    $this->assertDatabaseHas('studio_design_comments', ['id' => $other]);
    $this->deleteJson($base.'/'.$mine)->assertOk()->assertJsonPath('deleted', true);
    $this->assertDatabaseMissing('studio_design_comments', ['id' => $ownerReply]);

    DesignShare::query()->create([
        'owner_id' => $owner->id,
        'design_id' => $this->visible->id,
        'user_id' => $this->approver->id,
        'role' => 'editor',
    ]);
    $this->postJson($base.'/'.$other.'/resolve')->assertOk();
    $this->deleteJson($base.'/'.$other)->assertForbidden();

    $this->actingAs(User::factory()->create())->getJson($base)->assertNotFound();
    $this->postJson($base.'/'.$other.'/resolve')->assertNotFound();
});
