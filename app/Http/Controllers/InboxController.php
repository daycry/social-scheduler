<?php

namespace App\Http\Controllers;

use App\Connectors\Meta\Comments;
use App\Exceptions\PostValidationException;
use App\Jobs\SyncInboxComments;
use App\Models\InboxComment;
use App\Models\SocialAccount;
use App\Services\InboxService;
use App\Support\CurrentBrand;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Inbox de comentarios de Instagram/Facebook (PA-10): leer, marcar leido, responder y ocultar. Sin mensajes directos. */
class InboxController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly InboxService $inbox) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'network' => ['nullable', 'in:instagram,facebook'],
            'unread' => ['nullable', 'boolean'],
            'hidden' => ['nullable', 'boolean'],
        ]);
        $brand = CurrentBrand::resolve($request);
        $query = $this->inbox->query($user, ['network' => $filters['network'] ?? null, 'unread' => (bool) ($filters['unread'] ?? false), 'brand_id' => $brand?->id]
            + (isset($filters['hidden']) ? ['hidden' => (bool) $filters['hidden']] : []));

        $page = $query->with(['target' => fn ($q) => $q->select('id', 'post_id', 'external_url', 'text')->with(['post' => fn ($p) => $p->withTrashed()->select('id', 'text')]), 'account:id,name,username,network'])
            ->simplePaginate(30)->withQueryString();

        $own = InboxComment::withoutUserScope()->where('user_id', $user->id)->where('is_own', true)
            ->whereIn('parent_external_id', $page->pluck('external_id'))->orderBy('commented_at')->get()->groupBy('parent_external_id');

        $accounts = $this->inbox->accountsFor($user)->when($brand, fn ($c) => $c->where('brand_id', $brand->id));

        return Inertia::render('Inbox/Index', [
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'filters' => ['network' => $filters['network'] ?? null, 'unread' => (bool) ($filters['unread'] ?? false), 'hidden' => $filters['hidden'] ?? null],
            'unread_count' => $this->inbox->unreadCount($user),
            'comments' => $page->through(fn (InboxComment $c) => [
                'id' => $c->id,
                'network' => $c->network->value,
                'author' => $c->author_name,
                'text' => $c->text,
                'commented_at' => $c->commented_at?->toIso8601String(),
                'read' => $c->read_at !== null,
                'hidden' => $c->hidden,
                'is_reply' => $c->parent_external_id !== null,
                'account' => $c->account?->name,
                'post_text' => $c->target?->post ? mb_substr((string) ($c->target->text ?? $c->target->post->text), 0, 120) : null, // texto efectivo del destino
                'post_url' => $c->target?->external_url,
                'my_replies' => ($own[$c->external_id] ?? collect())->map(fn (InboxComment $r) => ['id' => $r->id, 'text' => $r->text, 'at' => $r->commented_at?->toIso8601String()])->values(),
            ]),
            // Estado por cuenta: «no disponible» cuando falta el permiso (hay que activarlo en la app de Meta y reconectar).
            'accounts' => $accounts->map(fn (SocialAccount $a) => [
                'id' => $a->id, 'network' => $a->network->value, 'name' => $a->name,
                'can_read' => Comments::hasScopes($a), 'can_moderate' => Comments::hasScopes($a, write: true),
                'needs_reconnect' => ! $a->isActive(),
            ])->values(),
            'flash' => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error')],
        ]);
    }

    /** «Actualizar»: encola la sincronizacion de las cuentas del usuario (la cola `poll` la procesa). */
    public function sync(Request $request): RedirectResponse
    {
        SyncInboxComments::dispatch($request->user()->id);

        return back()->with('success', 'Sincronización en marcha: los comentarios nuevos aparecerán en unos minutos.');
    }

    public function read(Request $request, InboxComment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);
        $this->inbox->markRead($request->user(), $comment, $request->boolean('read', true));

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $data = $request->validate(['network' => ['nullable', 'string', 'in:instagram,facebook']]);
        $brand = CurrentBrand::resolve($request);
        $n = $this->inbox->markAllRead($request->user(), array_filter(['network' => $data['network'] ?? null, 'brand_id' => $brand?->id]));

        return back()->with('success', "$n comentarios marcados como leídos.");
    }

    public function reply(Request $request, InboxComment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);
        $data = $request->validate(['text' => ['required', 'string', 'max:'.(int) config('social.inbox.reply_max_chars', 2200)]]);

        try {
            $this->inbox->reply($request->user(), $comment, $data['text']);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Respuesta enviada.');
    }

    public function hide(Request $request, InboxComment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);
        $hidden = $request->boolean('hidden', true);

        try {
            $this->inbox->setHidden($request->user(), $comment, $hidden);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', $hidden ? 'Comentario ocultado.' : 'Comentario visible de nuevo.');
    }
}
