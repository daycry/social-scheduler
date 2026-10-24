<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\FirstCommentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Editar y reintentar el primer comentario `fallido` de un destino ya publicado (C-01b). Solo la web; el MCP no lo expone. */
class FirstCommentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly FirstCommentService $comments) {}

    public function update(Request $request, Post $post, PostTarget $target): RedirectResponse
    {
        $this->guard($post, $target);
        $data = $request->validate(['first_comment' => ['required', 'string', 'max:10000']]);

        try {
            $this->comments->update($target, $data['first_comment']);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Primer comentario actualizado.');
    }

    public function retry(Request $request, Post $post, PostTarget $target): RedirectResponse
    {
        $this->guard($post, $target);

        $force = $request->validate(['force' => ['sometimes', 'boolean']])['force'] ?? false;

        try {
            $this->comments->retry($target, (bool) $force);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', $force ? 'Publicando el primer comentario igualmente.' : 'Reintentando el primer comentario.');
    }

    public function markPublished(Request $request, Post $post, PostTarget $target): RedirectResponse
    {
        $this->guard($post, $target);

        try {
            $this->comments->markPublished($target);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Primer comentario marcado como publicado.');
    }

    /** Post ajeno = 404/403 por UserScope y policy; un destino de otro post tambien es 404. */
    private function guard(Post $post, PostTarget $target): void
    {
        $this->authorize('update', $post);
        abort_unless($target->post_id === $post->id, 404);
    }
}
