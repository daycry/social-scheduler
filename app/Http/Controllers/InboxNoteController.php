<?php

namespace App\Http\Controllers;

use App\Http\Requests\InboxNoteRequest;
use App\Models\InboxComment;
use App\Models\InboxCommentNote;
use App\Services\InboxNoteService;
use Illuminate\Http\RedirectResponse;

/** Notas internas de los comentarios del inbox (C-04d). El binding usa el UserScope: lo ajeno responde 404. */
class InboxNoteController extends Controller
{
    public function __construct(private readonly InboxNoteService $notes) {}

    public function store(InboxNoteRequest $request, InboxComment $comment): RedirectResponse
    {
        $this->notes->add($request->user(), $comment, $request->validated()['body']);

        return back()->with('success', 'Nota añadida.');
    }

    public function destroy(InboxCommentNote $note): RedirectResponse
    {
        $this->notes->delete($note);

        return back()->with('success', 'Nota eliminada.');
    }
}
