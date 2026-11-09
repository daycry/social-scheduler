<?php

namespace App\Services;

use App\Connectors\Bluesky\BlueskyComments;
use App\Connectors\Contracts\CommentSource;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Google\YouTubeComments;
use App\Connectors\Meta\Comments;
use App\Connectors\Network;
use App\Connectors\Threads\ThreadsComments;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\BrandCollaborator;
use App\Models\InboxComment;
use App\Models\InboxCommentLabel;
use App\Models\InboxSavedReply;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Buzon de comentarios de Instagram/Facebook (PA-10) y, desde T-20, de Threads, Bluesky y YouTube: sincroniza los comentarios de las publicaciones propias ya
 * publicadas, y permite responder y ocultar/mostrar. Cada operacion exige el usuario explicito (aislamiento).
 */
class InboxService
{
    /** Redes con API oficial de comentarios y conector. TikTok no la tiene para terceros; los mensajes directos de Meta exigen revision de la app. */
    public const NETWORKS = [Network::Instagram, Network::Facebook, Network::Threads, Network::Bluesky, Network::YouTube];

    public function __construct(private readonly Comments $comments, private readonly SocialAccountService $accounts) {}

    /** Conector de comentarios de las redes no-Meta (null = Instagram/Facebook, que usan `Comments`). */
    private function source(Network $network): ?CommentSource
    {
        return match ($network) {
            Network::Threads => app(ThreadsComments::class),
            Network::Bluesky => app(BlueskyComments::class),
            Network::YouTube => app(YouTubeComments::class),
            default => null,
        };
    }

    /** ¿Tiene la cuenta el permiso de leer (o moderar/responder con `$write`)? */
    public function hasScopes(SocialAccount $account, bool $write = false): bool
    {
        return $this->source($account->network)?->hasScopes($account, $write) ?? Comments::hasScopes($account, $write);
    }

    /**
     * Cobertura del inbox por red (T-20): lo que se lee y responde y lo que queda fuera, con su motivo.
     *
     * @return list<array{network: string, label: string, read: bool, reply: bool, hide: bool, note: string}>
     */
    public static function coverage(): array
    {
        return [
            ['network' => 'instagram', 'label' => 'Instagram', 'read' => true, 'reply' => true, 'hide' => true, 'note' => 'Comentarios de publicaciones propias. Los mensajes directos requieren la revisión de la app de Meta.'],
            ['network' => 'facebook', 'label' => 'Facebook', 'read' => true, 'reply' => true, 'hide' => true, 'note' => 'Comentarios de la Página. Los mensajes directos requieren la revisión de la app de Meta.'],
            ['network' => 'threads', 'label' => 'Threads', 'read' => true, 'reply' => true, 'hide' => false, 'note' => 'Respuestas a tus hilos (permisos threads_read_replies y threads_manage_replies).'],
            ['network' => 'bluesky', 'label' => 'Bluesky', 'read' => true, 'reply' => true, 'hide' => false, 'note' => 'Respuestas a tus publicaciones; lectura pública y respuesta con la contraseña de aplicación.'],
            ['network' => 'youtube', 'label' => 'YouTube', 'read' => true, 'reply' => true, 'hide' => false, 'note' => 'Comentarios de tus videos (permiso youtube.force-ssl); cada respuesta gasta 50 unidades del cupo diario.'],
            ['network' => 'tiktok', 'label' => 'TikTok', 'read' => false, 'reply' => false, 'hide' => false, 'note' => 'Fuera: TikTok no ofrece una API pública de comentarios para terceros.'],
        ];
    }

    /** Cuentas del usuario que el buzon sabe leer (IG/FB). */
    public function accountsFor(User $user): Collection
    {
        return SocialAccount::withoutUserScope()->where('user_id', $user->id)->whereIn('network', array_map(fn (Network $n) => $n->value, self::NETWORKS))
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->orderBy('network')->orderBy('name')->get();
    }

    /** ¿Se puede sincronizar esta cuenta? Activa, IG/FB y con el permiso de lectura concedido. */
    public function syncable(SocialAccount $account): bool
    {
        return $account->isActive()
            && in_array($account->network, self::NETWORKS, true)
            && $this->hasScopes($account);
    }

    /**
     * Sincroniza los comentarios de las publicaciones recientes de UNA cuenta (sin limite de tiempo: uso fuera de cola).
     * Devuelve cuantos comentarios nuevos entraron.
     */
    public function sync(SocialAccount $account): int
    {
        $new = 0;
        $offset = 0;

        do {
            $r = $this->syncBatch($account, $offset, PHP_FLOAT_MAX);
            $new += $r['new'];
            $offset = $r['next'];
        } while ($offset !== null);

        return $new;
    }

    /**
     * Un bloque de publicaciones con presupuesto de tiempo (`$budgetSeconds`): lee desde `$offset` mientras quepa otra de la
     * duracion de la mas lenta; si no caben todas devuelve el desplazamiento por donde continuar (`next`), que el job reencola.
     * Los errores de una publicacion concreta (borrada en la red...) se saltan; credenciales y transitorios suben al job.
     *
     * @return array{new: int, next: int|null}
     */
    public function syncBatch(SocialAccount $account, int $offset, float $budgetSeconds): array
    {
        if (! $this->syncable($account)) {
            return ['new' => 0, 'next' => null];
        }

        $max = $account->network === Network::YouTube ? (int) config('social.inbox.youtube_max_posts', 10) : (int) config('social.inbox.max_posts', 25);
        $targets = PostTarget::withoutUserScope()->where('user_id', $account->user_id)->where('social_account_id', $account->id)
            ->where('status', PostStatus::Published->value)->whereNotNull('external_id')
            ->where('published_at', '>=', now()->subDays((int) config('social.inbox.post_days', 30)))
            ->orderByDesc('published_at')->orderByDesc('id')->offset($offset)->limit(max(0, $max - $offset))->get();

        $new = 0;
        $done = 0;
        $started = microtime(true);
        $slowest = 0.0;

        foreach ($targets as $target) {
            // Siempre se lee al menos una por pasada; despues solo si cabe otra de la duracion de la mas lenta.
            if ($done > 0 && (microtime(true) - $started) + $slowest > $budgetSeconds) {
                return ['new' => $new, 'next' => $offset + $done];
            }

            $t0 = microtime(true);
            $done++;

            try {
                $deadline = $budgetSeconds >= PHP_FLOAT_MAX / 2 ? null : $started + $budgetSeconds;
                $rows = ($this->source($account->network) ?? $this->comments)->fetch($account, (string) $target->external_id, $deadline);
            } catch (ReconnectRequiredException|TransientPublishException $e) {
                throw $e;
            } catch (ConnectorException) {
                $slowest = max($slowest, microtime(true) - $t0);

                continue; // publicacion borrada o sin permiso sobre ella: se salta
            }

            // `fetch` devuelve lo mas reciente primero; se guarda del mas antiguo al mas nuevo para que los ids crezcan con el tiempo (cursor del MCP).
            foreach (array_reverse($rows) as $row) {
                $new += $this->upsert($account, $target, $row) ? 1 : 0;
            }
            $slowest = max($slowest, microtime(true) - $t0);
        }

        return ['new' => $new, 'next' => null];
    }

    /** @param array<string, mixed> $row */
    private function upsert(SocialAccount $account, PostTarget $target, array $row): bool
    {
        $existing = InboxComment::withoutUserScope()->where('social_account_id', $account->id)->where('external_id', $row['external_id'])->first();

        if ($existing) {
            // Si la fila la creo `reply()` antes que la sincronizacion, esta la reconcilia (autor/propio) sin duplicar.
            $existing->forceFill(['text' => $row['text'], 'hidden' => $row['hidden'], 'author_name' => $row['author_name'], 'is_own' => $row['is_own']])->save();

            return false;
        }

        $comment = new InboxComment([
            'network' => $account->network, 'external_id' => $row['external_id'], 'parent_external_id' => $row['parent_external_id'],
            'post_external_id' => $target->external_id, 'author_name' => $row['author_name'], 'text' => $row['text'],
            'is_own' => $row['is_own'], 'hidden' => $row['hidden'], 'commented_at' => $row['commented_at'],
            // Lo escrito por nosotros nace leido.
            'read_at' => $row['is_own'] ? now() : null,
        ]);
        $comment->user_id = $account->user_id;
        $comment->brand_id = $account->brand_id;
        $comment->social_account_id = $account->id;
        $comment->post_target_id = $target->id;

        try {
            $comment->save();
        } catch (UniqueConstraintViolationException) {
            // Carrera con `reply()` u otra sincronizacion: ya existe, se actualiza (idempotente).
            InboxComment::withoutUserScope()->where('social_account_id', $account->id)->where('external_id', $row['external_id'])
                ->update(['text' => $row['text'], 'hidden' => $row['hidden'], 'author_name' => $row['author_name'], 'is_own' => $row['is_own']]);

            return false;
        }

        return ! $row['is_own'];
    }

    /**
     * Bandeja de comentarios de terceros del usuario, mas recientes primero.
     *
     * @param  array{network?: ?string, unread?: bool, brand_id?: ?int, hidden?: ?bool, has_notes?: bool, status?: ?string, assigned?: int|string|null, label?: ?string}  $filters
     */
    public function query(User $user, array $filters = []): Builder
    {
        return InboxComment::withoutUserScope()->where('user_id', $user->id)->where('is_own', false)
            ->when(! empty($filters['network']), fn (Builder $q) => $q->where('network', $filters['network']))
            ->when(! empty($filters['brand_id']), fn (Builder $q) => $q->where('brand_id', $filters['brand_id']))
            ->when(! empty($filters['unread']), fn (Builder $q) => $q->whereNull('read_at'))
            ->when(! empty($filters['has_notes']), fn (Builder $q) => $q->whereHas('notes'))
            ->when(($filters['status'] ?? null) === 'open', fn (Builder $q) => $q->whereNull('closed_at'))
            ->when(($filters['status'] ?? null) === 'closed', fn (Builder $q) => $q->whereNotNull('closed_at'))
            ->when(($filters['assigned'] ?? null) === 'none', fn (Builder $q) => $q->whereNull('assigned_user_id'))
            ->when(is_numeric($filters['assigned'] ?? null), fn (Builder $q) => $q->where('assigned_user_id', (int) $filters['assigned']))
            ->when(! empty($filters['label']), fn (Builder $q) => $q->whereHas('labels', fn (Builder $l) => $l->where('label', $filters['label'])))
            ->when(isset($filters['hidden']), fn (Builder $q) => $q->where('hidden', (bool) $filters['hidden']))
            ->orderByDesc('commented_at')->orderByDesc('id');
    }

    public function unreadCount(User $user): int
    {
        return $this->query($user, ['unread' => true])->count();
    }

    public function markRead(User $user, InboxComment $comment, bool $read = true): InboxComment
    {
        $this->assertOwns($user, $comment);
        $comment->forceFill(['read_at' => $read ? ($comment->read_at ?? now()) : null])->save();

        return $comment;
    }

    /** Marca como leido todo lo pendiente del usuario (opcionalmente de una red/marca). */
    public function markAllRead(User $user, array $filters = []): int
    {
        return $this->query($user, ['unread' => true] + $filters)->update(['read_at' => now()]);
    }

    /** Responde a un comentario ajeno desde la cuenta; guarda la respuesta y deja el comentario como leido. */
    public function reply(User $user, InboxComment $comment, string $text): InboxComment
    {
        $this->assertOwns($user, $comment);
        $text = trim($text);
        $max = (int) config('social.inbox.reply_max_chars', 2200);
        if ($text === '' || mb_strlen($text) > $max) {
            throw new PostValidationException(['text' => ["Escribe una respuesta de hasta $max caracteres."]]);
        }
        if ($comment->is_own) {
            throw new PostValidationException(['text' => ['No puedes responder a un comentario propio.']]);
        }

        $account = $this->moderationAccount($comment);
        // YouTube solo admite respuestas a comentarios de primer nivel: responder a una respuesta cuelga del comentario raiz del hilo.
        $parentId = $comment->network === Network::YouTube ? ($comment->parent_external_id ?? $comment->external_id) : $comment->external_id;

        $id = $this->call($account, fn () => ($this->source($account->network) ?? $this->comments)->reply($account, $parentId, $text));

        $comment->forceFill(['read_at' => $comment->read_at ?? now()])->save();

        // Meta ya publico la respuesta: nada de lo que sigue puede fallar hacia el usuario (ni 500 ni duplicados).
        $reply = new InboxComment([
            'network' => $comment->network, 'external_id' => $id,
            'parent_external_id' => $parentId, 'post_external_id' => $comment->post_external_id,
            'author_name' => $account->username ?? $account->name, 'text' => $text, 'is_own' => true, 'hidden' => false,
            'commented_at' => now(), 'read_at' => now(),
        ]);
        $reply->user_id = $comment->user_id;
        $reply->brand_id = $comment->brand_id;
        $reply->social_account_id = $account->id;
        $reply->post_target_id = $comment->post_target_id;

        // Sin id de Meta no se guarda un `own-…` inventado (duplicaria la respuesta cuando la sincronizacion la traiga con su id real):
        // la proxima sincronizacion la incorpora. Con id, se guarda salvo que la sincronizacion ya la haya traido.
        if ($id === '') {
            return $reply;
        }

        try {
            $existing = InboxComment::withoutUserScope()->where('social_account_id', $account->id)->where('external_id', $id)->first();
            if ($existing) {
                return $existing;
            }
            $reply->save();
        } catch (UniqueConstraintViolationException) {
            return InboxComment::withoutUserScope()->where('social_account_id', $account->id)->where('external_id', $id)->first() ?? $reply;
        } catch (Throwable $e) {
            Log::error('inbox.reply_persist_failed', ['comment_id' => $comment->id, 'exception' => $e::class]);
        }

        return $reply;
    }

    public function setHidden(User $user, InboxComment $comment, bool $hidden): InboxComment
    {
        $this->assertOwns($user, $comment);
        if ($this->source($comment->network) !== null) {
            throw new PostValidationException(['account' => ['Ocultar comentarios solo está disponible en Instagram y Facebook.']]);
        }
        $account = $this->moderationAccount($comment);

        $this->call($account, fn () => $this->comments->setHidden($account, $comment->external_id, $hidden));
        $comment->forceFill(['hidden' => $hidden])->save();

        return $comment;
    }

    /** Cuenta del comentario, activa y con el permiso de moderacion; si no, un error legible que dice que activar. */
    private function moderationAccount(InboxComment $comment): SocialAccount
    {
        $account = SocialAccount::withoutUserScope()->where('user_id', $comment->user_id)->find($comment->social_account_id);
        if (! $account || ! $account->isActive()) {
            throw new PostValidationException(['account' => ['La cuenta necesita reconectarse.']]);
        }
        if (! $this->hasScopes($account, write: true)) {
            throw new PostValidationException(['account' => [in_array($account->network, [Network::Instagram, Network::Facebook], true) ? 'Falta el permiso para moderar comentarios: actívalo en los casos de uso de la app de Meta y reconecta la cuenta.' : 'Falta el permiso para responder comentarios: reconecta la cuenta aceptando los permisos de respuestas.']]);
        }

        return $account;
    }

    /** @template T @param \Closure(): T $work @return T */
    private function call(SocialAccount $account, \Closure $work): mixed
    {
        try {
            return $work();
        } catch (ReconnectRequiredException $e) {
            $this->accounts->flagReconnection($account, $e->getMessage());
            throw new PostValidationException(['account' => ['La cuenta necesita reconectarse.']]);
        } catch (TransientPublishException) {
            throw new PostValidationException(['account' => ['La red no responde ahora mismo. Inténtalo de nuevo en unos minutos.']]);
        } catch (ConnectorException $e) {
            throw new PostValidationException(['account' => [$e->getMessage()]]);
        }
    }

    // ------------------------------------------------------------------ triaje (T-20): estado, etiquetas, asignacion y lotes

    /** Cierra (o reabre) un comentario: es estado interno, no toca la red. */
    public function setClosed(User $user, InboxComment $comment, bool $closed = true): InboxComment
    {
        $this->assertOwns($user, $comment);
        $comment->forceFill(['closed_at' => $closed ? ($comment->closed_at ?? now()) : null])->save();

        return $comment;
    }

    /** Etiqueta normalizada (minusculas, espacios colapsados) o error de validacion. */
    public function normalizeLabel(string $label): string
    {
        $label = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label)));
        if ($label === '' || mb_strlen($label) > 40 || preg_match('/[\p{Cc}\p{Cf}<>]/u', $label) === 1) {
            throw new PostValidationException(['label' => ['La etiqueta tiene de 1 a 40 caracteres, sin símbolos < o >.']]);
        }

        return $label;
    }

    public function addLabel(User $user, InboxComment $comment, string $label): InboxComment
    {
        $this->assertOwns($user, $comment);
        $label = $this->normalizeLabel($label);
        $labels = $comment->labels()->get();
        if (! $labels->contains('label', $label)) {
            if ($labels->count() >= (int) config('social.inbox.labels_max', 10)) {
                throw new PostValidationException(['label' => ['Máximo '.(int) config('social.inbox.labels_max', 10).' etiquetas por comentario.']]);
            }
            $row = new InboxCommentLabel(['label' => $label]);
            $row->user_id = $user->id;
            $row->inbox_comment_id = $comment->id;
            try {
                $row->save();
            } catch (UniqueConstraintViolationException) {
                // carrera: ya estaba puesta
            }
        }

        return $comment->unsetRelation('labels');
    }

    public function removeLabel(User $user, InboxComment $comment, string $label): InboxComment
    {
        $this->assertOwns($user, $comment);
        $comment->labels()->where('label', mb_strtolower(trim($label)))->delete();

        return $comment->unsetRelation('labels');
    }

    /**
     * Personas a las que se puede asignar un comentario de la marca: el propietario y sus colaboradores.
     *
     * @return Collection<int, User>
     */
    public function assignees(User $owner, int $brandId): Collection
    {
        $ids = BrandCollaborator::query()->where('brand_id', $brandId)->where('owner_id', $owner->id)->pluck('user_id')->push($owner->id)->unique();

        return User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    public function assign(User $user, InboxComment $comment, ?int $assigneeId): InboxComment
    {
        $this->assertOwns($user, $comment);
        if ($assigneeId !== null && ! $this->assignees($user, $comment->brand_id)->contains('id', $assigneeId)) {
            throw new PostValidationException(['assigned_user_id' => ['Solo puedes asignar a ti o a un colaborador de la marca.']]);
        }
        $comment->forceFill(['assigned_user_id' => $assigneeId])->save();

        return $comment;
    }

    /**
     * Accion en lote sobre comentarios propios: `read`, `unread`, `close` o `reopen`. Un id ajeno o inexistente cuenta como
     * `skipped` sin distinguirlos (no revela su existencia).
     *
     * @param  list<int>  $ids
     * @return array{done: int, skipped: int}
     */
    public function batch(User $user, string $action, array $ids): array
    {
        if (! in_array($action, ['read', 'unread', 'close', 'reopen'], true)) {
            throw new PostValidationException(['action' => ['Acción no válida.']]);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > (int) config('social.inbox.batch_max', 100)) {
            throw new PostValidationException(['ids' => ['Elige entre 1 y '.(int) config('social.inbox.batch_max', 100).' comentarios.']]);
        }

        $mine = InboxComment::withoutUserScope()->where('user_id', $user->id)->whereIn('id', $ids);
        $found = (clone $mine)->count();
        match ($action) {
            'read' => (clone $mine)->whereNull('read_at')->update(['read_at' => now()]),
            'unread' => (clone $mine)->update(['read_at' => null]),
            'close' => (clone $mine)->whereNull('closed_at')->update(['closed_at' => now()]),
            'reopen' => (clone $mine)->update(['closed_at' => null]),
        };

        return ['done' => $found, 'skipped' => count($ids) - $found];
    }

    // ------------------------------------------------------------------ respuestas guardadas

    /** @return Collection<int, InboxSavedReply> */
    public function savedReplies(User $user): Collection
    {
        return InboxSavedReply::withoutUserScope()->where('user_id', $user->id)->orderBy('title')->orderBy('id')->get();
    }

    public function saveReply(User $user, string $title, string $body): InboxSavedReply
    {
        $title = trim($title);
        $body = trim($body);
        $errors = [];
        if ($title === '' || mb_strlen($title) > InboxSavedReply::TITLE_MAX) {
            $errors['title'][] = 'El título tiene de 1 a '.InboxSavedReply::TITLE_MAX.' caracteres.';
        }
        if ($body === '' || mb_strlen($body) > InboxSavedReply::BODY_MAX) {
            $errors['body'][] = 'El texto tiene de 1 a '.InboxSavedReply::BODY_MAX.' caracteres.';
        }
        if (! $errors && InboxSavedReply::withoutUserScope()->where('user_id', $user->id)->count() >= (int) config('social.inbox.saved_replies_max', 30)) {
            $errors['title'][] = 'Máximo '.(int) config('social.inbox.saved_replies_max', 30).' respuestas guardadas.';
        }
        if ($errors) {
            throw new PostValidationException($errors);
        }
        $reply = new InboxSavedReply(['title' => $title, 'body' => $body]);
        $reply->user_id = $user->id;
        $reply->save();

        return $reply;
    }

    public function deleteReply(User $user, int $id): void
    {
        InboxSavedReply::withoutUserScope()->where('user_id', $user->id)->whereKey($id)->delete();
    }

    private function assertOwns(User $user, InboxComment $comment): void
    {
        if ($comment->user_id !== $user->id) {
            throw new PostValidationException(['comment' => ['El comentario no pertenece al usuario.']]);
        }
    }
}
