<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Jobs\SyncInboxComments;
use App\Models\InboxComment;
use App\Models\InboxCommentLabel;
use App\Models\InboxCommentNote;
use App\Models\InboxSavedReply;
use App\Models\SocialAccount;
use App\Services\InboxNoteService;
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

    public function __construct(private readonly InboxService $inbox, private readonly InboxNoteService $notes) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'network' => ['nullable', 'in:instagram,facebook,threads,bluesky,youtube'],
            'status' => ['nullable', 'in:open,closed'],
            'assigned' => ['nullable', 'string', 'max:12', 'regex:/^(none|me|[0-9]+)$/'],
            'label' => ['nullable', 'string', 'max:40'],
            'unread' => ['nullable', 'boolean'],
            'hidden' => ['nullable', 'boolean'],
            'has_notes' => ['nullable', 'boolean'],
        ]);
        $brand = CurrentBrand::resolve($request);
        $assigned = ($filters['assigned'] ?? null) === 'me' ? $user->id : ($filters['assigned'] ?? null);
        $query = $this->inbox->query($user, ['status' => $filters['status'] ?? null, 'assigned' => $assigned, 'label' => $filters['label'] ?? null, 'network' => $filters['network'] ?? null, 'unread' => (bool) ($filters['unread'] ?? false), 'brand_id' => $brand?->id, 'has_notes' => (bool) ($filters['has_notes'] ?? false)]
            + (isset($filters['hidden']) ? ['hidden' => (bool) $filters['hidden']] : []));

        $page = $query->with(['notes', 'labels', 'target' => fn ($q) => $q->select('id', 'post_id', 'external_url', 'text')->with(['post' => fn ($p) => $p->withTrashed()->select('id', 'text')]), 'account:id,name,username,network'])
            ->simplePaginate(30)->withQueryString();

        $own = InboxComment::withoutUserScope()->where('user_id', $user->id)->where('is_own', true)
            ->whereIn('parent_external_id', $page->pluck('external_id'))->orderBy('commented_at')->get()->groupBy('parent_external_id');

        $accounts = $this->inbox->accountsFor($user)->when($brand, fn ($c) => $c->where('brand_id', $brand->id));

        return Inertia::render('Inbox/Index', [
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'filters' => ['status' => $filters['status'] ?? null, 'assigned' => $filters['assigned'] ?? null, 'label' => $filters['label'] ?? null, 'network' => $filters['network'] ?? null, 'unread' => (bool) ($filters['unread'] ?? false), 'hidden' => $filters['hidden'] ?? null, 'has_notes' => (bool) ($filters['has_notes'] ?? false)],
            'note_max' => InboxCommentNote::BODY_MAX,
            'coverage' => InboxService::coverage(),
            'saved_replies' => $this->inbox->savedReplies($user)->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'body' => $r->body])->values(),
            'assignees' => $brand ? $this->inbox->assignees($user, $brand->id)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values() : [],
            'labels' => InboxCommentLabel::withoutUserScope()->where('user_id', $user->id)->select('label')->distinct()->orderBy('label')->limit(50)->pluck('label'),
            'unread_count' => $this->inbox->unreadCount($user),
            'comments' => $page->through(fn (InboxComment $c) => [
                'id' => $c->id,
                'network' => $c->network->value,
                'author' => $c->author_name,
                'text' => $c->text,
                'commented_at' => $c->commented_at?->toIso8601String(),
                'read' => $c->read_at !== null,
                'hidden' => $c->hidden,
                'closed' => $c->closed_at !== null,
                'can_hide' => in_array($c->network->value, ['instagram', 'facebook'], true),
                'assigned_user_id' => $c->assigned_user_id,
                'labels' => $c->labels->pluck('label')->values(),
                'notes' => $c->notes->map(fn (InboxCommentNote $n) => $this->notes->toArray($n))->values(),
                'is_reply' => $c->parent_external_id !== null,
                'account' => $c->account?->name,
                'post_text' => $c->target?->post ? mb_substr((string) ($c->target->text ?? $c->target->post->text), 0, 120) : null, // texto efectivo del destino
                'post_url' => $c->target?->external_url,
                'my_replies' => ($own[$c->external_id] ?? collect())->map(fn (InboxComment $r) => ['id' => $r->id, 'text' => $r->text, 'at' => $r->commented_at?->toIso8601String()])->values(),
            ]),
            // Estado por cuenta: «no disponible» cuando falta el permiso (hay que activarlo en la app de Meta y reconectar).
            'accounts' => $accounts->map(fn (SocialAccount $a) => [
                'id' => $a->id, 'network' => $a->network->value, 'name' => $a->name,
                'can_read' => $this->inbox->hasScopes($a), 'can_moderate' => $this->inbox->hasScopes($a, write: true),
                'can_hide' => in_array($a->network->value, ['instagram', 'facebook'], true),
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
        $data = $request->validate(['network' => ['nullable', 'string', 'in:instagram,facebook,threads,bluesky,youtube']]);
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

    /** Cierra o reabre un comentario (estado interno). */
    public function close(Request $request, InboxComment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);
        $closed = $request->boolean('closed', true);
        $this->inbox->setClosed($request->user(), $comment, $closed);

        return back()->with('success', $closed ? 'Comentario cerrado.' : 'Comentario reabierto.');
    }

    public function label(Request $request, InboxComment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);
        $data = $request->validate(['label' => ['required', 'string', 'max:60'], 'remove' => ['nullable', 'boolean']]);

        try {
            $request->boolean('remove')
                ? $this->inbox->removeLabel($request->user(), $comment, $data['label'])
                : $this->inbox->addLabel($request->user(), $comment, $data['label']);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back();
    }

    public function assign(Request $request, InboxComment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);
        $data = $request->validate(['assigned_user_id' => ['nullable', 'integer']]);

        try {
            $this->inbox->assign($request->user(), $comment, $data['assigned_user_id'] ?? null);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back();
    }

    /** Lote: marcar leido / no leido, cerrar o reabrir varios comentarios. */
    public function batch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:read,unread,close,reopen'],
            'ids' => ['required', 'array', 'min:1', 'max:'.(int) config('social.inbox.batch_max', 100)],
            'ids.*' => ['integer'],
        ]);
        $r = $this->inbox->batch($request->user(), $data['action'], $data['ids']);

        return back()->with('success', "{$r['done']} comentarios actualizados".($r['skipped'] ? ", {$r['skipped']} omitidos." : '.'));
    }

    public function storeSavedReply(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:'.InboxSavedReply::TITLE_MAX], 'body' => ['required', 'string', 'max:'.InboxSavedReply::BODY_MAX]]);

        try {
            $this->inbox->saveReply($request->user(), $data['title'], $data['body']);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Respuesta guardada.');
    }

    public function destroySavedReply(Request $request, int $reply): RedirectResponse
    {
        $this->inbox->deleteReply($request->user(), $reply);

        return back()->with('success', 'Respuesta guardada eliminada.');
    }
}
