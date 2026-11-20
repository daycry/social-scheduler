<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Post;
use App\Services\TrashService;
use App\Support\CursorPage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Planificacion · Papelera (PA-06): publicaciones borradas, restaurar o eliminar para siempre. */
class TrashController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly TrashService $trash) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $filters = $request->validate(CursorPage::RULES);
        $user = $request->user();
        [$posts, $pagination] = CursorPage::fetch(
            $this->trash->query($user), $filters['limit'] ?? null, $filters['cursor'] ?? null,
            'trash:u'.$user->id, withTotal: true,
        );

        if ($posts->isEmpty() && filled($filters['cursor'] ?? null)) {
            return to_route('trash.index', ['limit' => $filters['limit'] ?? null]);
        }

        return Inertia::render('Trash/Index', [
            'pagination' => $pagination + $filters,
            'retention_days' => TrashService::RETENTION_DAYS,
            'posts' => $posts->map(fn (Post $p) => $this->trash->present($p) + ['timezone' => $p->brand?->timezone])->values(),
        ]);
    }

    public function restore(Post $post): RedirectResponse
    {
        $this->authorize('restore', $post);

        try {
            $this->trash->restore(request()->user(), $post);
        } catch (PostValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('trash.index')->with('success', 'Publicacion restaurada como borrador si ya habia vencido.');
    }

    public function destroy(Post $post): RedirectResponse
    {
        $this->authorize('forceDelete', $post);

        try {
            $this->trash->forceDelete(request()->user(), $post);
        } catch (PostValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('trash.index')->with('success', 'Publicacion eliminada para siempre.');
    }
}
