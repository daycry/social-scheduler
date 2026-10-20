<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Post;
use App\Services\TrashService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Planificacion · Papelera (PA-06): publicaciones borradas, restaurar o eliminar para siempre. */
class TrashController extends Controller
{
    use AuthorizesRequests;

    public const RETENTION_DAYS = 30;

    public function __construct(private readonly TrashService $trash) {}

    public function index(): Response
    {
        $posts = Post::onlyTrashed()->with(['brand:id,name', 'targets'])->orderByDesc('deleted_at')->limit(200)->get();

        return Inertia::render('Trash/Index', [
            'retention_days' => self::RETENTION_DAYS,
            'posts' => $posts->map(fn (Post $p) => [
                'id' => $p->id,
                'brand' => $p->brand?->name,
                'text' => $p->text,
                'networks' => $p->targets->pluck('network')->map(fn ($n) => $n->value)->unique()->values(),
                'deleted_at' => $p->deleted_at?->toIso8601String(),
                'days_left' => max(0, self::RETENTION_DAYS - (int) $p->deleted_at?->diffInDays(now())),
            ])->values(),
            'flash' => ['success' => session('success'), 'error' => session('error')],
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
