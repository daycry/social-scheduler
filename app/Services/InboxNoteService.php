<?php

namespace App\Services;

use App\Models\InboxComment;
use App\Models\InboxCommentNote;
use App\Models\User;

/**
 * Notas internas de los comentarios del inbox (C-04d): texto plano, solo del dueño, nunca se envían a la red.
 * La propiedad la fija el UserScope (comentario o nota ajenos = 404); el cuerpo no se registra en ningún log.
 */
class InboxNoteService
{
    public function add(User $user, InboxComment $comment, string $body): InboxCommentNote
    {
        $note = new InboxCommentNote(['body' => trim($body)]);
        $note->user_id = $user->id;
        $note->inbox_comment_id = $comment->id;
        $note->save();

        return $note;
    }

    public function delete(InboxCommentNote $note): void
    {
        $note->delete();
    }

    /** @return array{id: int, body: string, at: string|null} */
    public function toArray(InboxCommentNote $note): array
    {
        return ['id' => $note->id, 'body' => $note->body, 'at' => $note->created_at?->toIso8601String()];
    }
}
