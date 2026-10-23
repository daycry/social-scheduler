<?php

namespace App\Services;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\Comments;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\InboxComment;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Buzon de comentarios de Instagram/Facebook (PA-10): sincroniza los comentarios de las publicaciones propias ya
 * publicadas, y permite responder y ocultar/mostrar. Cada operacion exige el usuario explicito (aislamiento).
 */
class InboxService
{
    public function __construct(private readonly Comments $comments, private readonly SocialAccountService $accounts) {}

    /** Cuentas del usuario que el buzon sabe leer (IG/FB). */
    public function accountsFor(User $user): Collection
    {
        return SocialAccount::withoutUserScope()->where('user_id', $user->id)->whereIn('network', [Network::Instagram->value, Network::Facebook->value])
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->orderBy('network')->orderBy('name')->get();
    }

    /** ¿Se puede sincronizar esta cuenta? Activa, IG/FB y con el permiso de lectura concedido. */
    public function syncable(SocialAccount $account): bool
    {
        return $account->isActive()
            && in_array($account->network, [Network::Instagram, Network::Facebook], true)
            && Comments::hasScopes($account);
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

        $max = (int) config('social.inbox.max_posts', 25);
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
                $rows = $this->comments->fetch($account, (string) $target->external_id, $budgetSeconds >= PHP_FLOAT_MAX / 2 ? null : $started + $budgetSeconds);
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
     * @param  array{network?: ?string, unread?: bool, brand_id?: ?int, hidden?: ?bool}  $filters
     */
    public function query(User $user, array $filters = []): Builder
    {
        return InboxComment::withoutUserScope()->where('user_id', $user->id)->where('is_own', false)
            ->when(! empty($filters['network']), fn (Builder $q) => $q->where('network', $filters['network']))
            ->when(! empty($filters['brand_id']), fn (Builder $q) => $q->where('brand_id', $filters['brand_id']))
            ->when(! empty($filters['unread']), fn (Builder $q) => $q->whereNull('read_at'))
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

        $id = $this->call($account, fn () => $this->comments->reply($account, $comment->external_id, $text));

        $comment->forceFill(['read_at' => $comment->read_at ?? now()])->save();

        // Meta ya publico la respuesta: nada de lo que sigue puede fallar hacia el usuario (ni 500 ni duplicados).
        $reply = new InboxComment([
            'network' => $comment->network, 'external_id' => $id,
            'parent_external_id' => $comment->external_id, 'post_external_id' => $comment->post_external_id,
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
        if (! Comments::hasScopes($account, write: true)) {
            throw new PostValidationException(['account' => ['Falta el permiso para moderar comentarios: actívalo en los casos de uso de la app de Meta y reconecta la cuenta.']]);
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

    private function assertOwns(User $user, InboxComment $comment): void
    {
        if ($comment->user_id !== $user->id) {
            throw new PostValidationException(['comment' => ['El comentario no pertenece al usuario.']]);
        }
    }
}
