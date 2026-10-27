<?php

use App\Models\AuditLog;
use App\Models\InboxComment;
use App\Models\InboxCommentNote;
use App\Models\User;

// C-04d · CA-17: alta y borrado de notas internas de un comentario del inbox; comentario o nota ajenos = 404.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->comment = InboxComment::factory()->create(['user_id' => $this->user->id]);
});

it('el dueño añade una nota y queda guardada con su usuario', function () {
    $this->actingAs($this->user)->post(route('inbox.notes.store', $this->comment->id), ['body' => 'Llamar al cliente'])
        ->assertRedirect()->assertSessionHas('success');

    $note = InboxCommentNote::withoutUserScope()->first();
    expect($note->body)->toBe('Llamar al cliente')->and($note->user_id)->toBe($this->user->id)->and($note->inbox_comment_id)->toBe($this->comment->id);
});

it('el dueño borra su nota', function () {
    $note = InboxCommentNote::factory()->create(['user_id' => $this->user->id, 'inbox_comment_id' => $this->comment->id]);

    $this->actingAs($this->user)->delete(route('inbox.notes.destroy', $note->id))->assertRedirect()->assertSessionHas('success');

    expect(InboxCommentNote::withoutUserScope()->count())->toBe(0);
});

it('un comentario ajeno responde 404 y no se crea nada', function () {
    $other = User::factory()->create();

    $this->actingAs($other)->post(route('inbox.notes.store', $this->comment->id), ['body' => 'intruso'])->assertNotFound();

    expect(InboxCommentNote::withoutUserScope()->count())->toBe(0);
});

it('una nota ajena responde 404 y se conserva', function () {
    $note = InboxCommentNote::factory()->create(['user_id' => $this->user->id, 'inbox_comment_id' => $this->comment->id]);

    $this->actingAs(User::factory()->create())->delete(route('inbox.notes.destroy', $note->id))->assertNotFound();

    expect(InboxCommentNote::withoutUserScope()->count())->toBe(1);
});

it('valida: obligatoria, no vacía y con tope de 2.000 caracteres', function () {
    $this->actingAs($this->user)->post(route('inbox.notes.store', $this->comment->id), ['body' => ''])->assertSessionHasErrors('body');
    $this->actingAs($this->user)->post(route('inbox.notes.store', $this->comment->id), ['body' => '   '])->assertSessionHasErrors('body');
    $this->actingAs($this->user)->post(route('inbox.notes.store', $this->comment->id), ['body' => str_repeat('a', 2001)])->assertSessionHasErrors('body');
    $this->actingAs($this->user)->post(route('inbox.notes.store', $this->comment->id), ['body' => str_repeat('a', 2000)])->assertSessionDoesntHaveErrors();

    expect(InboxCommentNote::withoutUserScope()->count())->toBe(1);
});

it('exige sesión', function () {
    $this->post(route('inbox.notes.store', $this->comment->id), ['body' => 'x'])->assertRedirect(route('login'));
});

it('la auditoría y el log no guardan el cuerpo de la nota', function () {
    $this->actingAs($this->user)->post(route('inbox.notes.store', $this->comment->id), ['body' => 'CUERPO-SECRETO'])->assertRedirect();

    expect(AuditLog::query()->get()->toJson())->not->toContain('CUERPO-SECRETO');
});

it('no admite notas en respuestas propias (is_own)', function () {
    $own = InboxComment::factory()->create(['user_id' => $this->user->id, 'is_own' => true]);

    $this->actingAs($this->user)->post(route('inbox.notes.store', $own->id), ['body' => 'nota'])->assertSessionHasErrors('body');

    expect(InboxCommentNote::withoutUserScope()->count())->toBe(0);
});

it('borrar notas lleva throttle', function () {
    expect(app('router')->getRoutes()->getByName('inbox.notes.destroy')->gatherMiddleware())->toContain('throttle:60,1');
});
