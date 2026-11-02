<?php

use App\Models\InboxComment;
use App\Models\InboxCommentNote;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

// C-04d · CA-17: las notas internas del inbox viven en tabla aparte (el inbox hace upsert de los comentarios).

it('cada usuario solo ve sus notas (UserScope)', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $commentA = InboxComment::factory()->create(['user_id' => $a->id]);
    $commentB = InboxComment::factory()->create(['user_id' => $b->id]);
    InboxCommentNote::factory()->create(['user_id' => $a->id, 'inbox_comment_id' => $commentA->id, 'body' => 'de A']);
    InboxCommentNote::factory()->create(['user_id' => $b->id, 'inbox_comment_id' => $commentB->id, 'body' => 'de B']);

    Auth::login($a);

    expect(InboxCommentNote::pluck('body')->all())->toBe(['de A'])
        ->and(InboxCommentNote::withoutUserScope()->count())->toBe(2);
});

it('la nota cuelga del comentario y se lista desde él', function () {
    $user = User::factory()->create();
    $comment = InboxComment::factory()->create(['user_id' => $user->id]);
    $note = InboxCommentNote::factory()->create(['user_id' => $user->id, 'inbox_comment_id' => $comment->id]);

    expect($note->comment->is($comment))->toBeTrue()
        ->and($comment->notes()->withoutGlobalScopes()->pluck('id')->all())->toBe([$note->id]);
});

it('un upsert del comentario (mismo external_id) conserva la fila y sus notas', function () {
    $user = User::factory()->create();
    $comment = InboxComment::factory()->create(['user_id' => $user->id, 'external_id' => 'c-1', 'text' => 'antes']);
    InboxCommentNote::factory()->create(['user_id' => $user->id, 'inbox_comment_id' => $comment->id]);

    // Lo que hace InboxService::upsert: actualizar la fila existente.
    InboxComment::withoutUserScope()->where('external_id', 'c-1')->first()->forceFill(['text' => 'después'])->save();

    expect(InboxComment::withoutUserScope()->count())->toBe(1)
        ->and(InboxCommentNote::withoutUserScope()->where('inbox_comment_id', $comment->id)->count())->toBe(1);
});

it('borrar el comentario borra sus notas (cascade) sin tocar otras', function () {
    $user = User::factory()->create();
    $c1 = InboxComment::factory()->create(['user_id' => $user->id]);
    $c2 = InboxComment::factory()->create(['user_id' => $user->id]);
    InboxCommentNote::factory()->create(['user_id' => $user->id, 'inbox_comment_id' => $c1->id]);
    InboxCommentNote::factory()->create(['user_id' => $user->id, 'inbox_comment_id' => $c2->id]);

    $c1->delete();

    expect(InboxCommentNote::withoutUserScope()->count())->toBe(1);
});
