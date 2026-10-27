<?php

namespace App\Services;

use App\Models\InboxComment;
use App\Models\InboxCommentNote;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Notas internas de los comentarios del inbox (C-04d): texto plano, solo del dueño, nunca se envían a la red.
 * La propiedad la fija el UserScope (comentario o nota ajenos = 404); el cuerpo no se registra en ningún log.
 */
class InboxNoteService
{
    public function add(User $user, InboxComment $comment, string $body): InboxCommentNote
    {
        // Las respuestas propias no se muestran en ningún sitio del inbox: una nota sobre ellas quedaría huérfana.
        if ($comment->is_own) {
            throw ValidationException::withMessages(['body' => 'No se pueden poner notas a tus propias respuestas.']);
        }

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
