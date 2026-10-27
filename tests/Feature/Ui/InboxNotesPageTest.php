<?php

use App\Models\InboxComment;
use App\Models\InboxCommentNote;
use App\Models\SocialAccount;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// Inbox · notas internas (C-04d, CA-17): props `notes` por comentario y filtro `has_notes`.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->first = InboxComment::factory()->create(['user_id' => $this->user->id, 'text' => 'con nota', 'commented_at' => now()->subHours(2)]);
    // Misma marca/cuenta para que el inbox las muestre juntas.
    $this->second = InboxComment::factory()->create([
        'user_id' => $this->user->id, 'brand_id' => $this->first->brand_id, 'social_account_id' => $this->first->social_account_id,
        'text' => 'sin nota', 'commented_at' => now()->subHour(),
    ]);
    InboxCommentNote::factory()->create(['user_id' => $this->user->id, 'inbox_comment_id' => $this->first->id, 'body' => 'primera']);
    InboxCommentNote::factory()->create(['user_id' => $this->user->id, 'inbox_comment_id' => $this->first->id, 'body' => 'segunda']);
    // Nota de otro usuario sobre su propio comentario: no debe filtrarse.
    InboxCommentNote::factory()->create(['body' => 'ajena']);
});

it('incluye las notas de cada comentario en orden cronológico', function () {
    $this->actingAs($this->user)->get(route('inbox.index'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Inbox/Index')
            ->has('comments.data', 2)
            ->where('comments.data.1.text', 'con nota')
            ->has('comments.data.1.notes', 2)
            ->where('comments.data.1.notes.0.body', 'primera')->where('comments.data.1.notes.1.body', 'segunda')
            ->has('comments.data.0.notes', 0)
            ->where('note_max', 2000));
});

it('el filtro has_notes deja solo los comentarios con notas', function () {
    $this->actingAs($this->user)->get(route('inbox.index', ['has_notes' => 1]))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('comments.data', 1)->where('comments.data.0.text', 'con nota')->where('filters.has_notes', true));
});

it('sin el filtro no se aplica y no hay notas ajenas', function () {
    $this->actingAs($this->user)->get(route('inbox.index'))
        ->assertInertia(fn (Assert $p) => $p->where('filters.has_notes', false));
    expect(SocialAccount::withoutUserScope()->count())->toBeGreaterThan(0);
});
