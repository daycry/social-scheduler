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
use Illuminate\Support\Collection;

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
     * Sincroniza los comentarios de las publicaciones recientes de UNA cuenta. Devuelve cuantos comentarios nuevos entraron.
     * Los errores de una publicacion concreta (borrada en la red...) se saltan; credenciales y transitorios suben al job.
     */
    public function sync(SocialAccount $account): int
    {
        if (! $this->syncable($account)) {
            return 0;
        }

        $targets = PostTarget::withoutUserScope()->where('user_id', $account->user_id)->where('social_account_id', $account->id)
            ->where('status', PostStatus::Published->value)->whereNotNull('external_id')
            ->where('published_at', '>=', now()->subDays((int) config('social.inbox.post_days', 30)))
            ->orderByDesc('published_at')->limit((int) config('social.inbox.max_posts', 25))->get();

        $new = 0;
        foreach ($targets as $target) {
            try {
                $rows = $this->comments->fetch($account, (string) $target->external_id);
            } catch (ReconnectRequiredException|TransientPublishException $e) {
                throw $e;
            } catch (ConnectorException) {
                continue; // publicacion borrada o sin permiso sobre ella: se salta
            }

            foreach ($rows as $row) {
                $new += $this->upsert($account, $target, $row) ? 1 : 0;
            }
        }

        return $new;
    }

    /** @param array<string, mixed> $row */
    private function upsert(SocialAccount $account, PostTarget $target, array $row): bool
    {
        $existing = InboxComment::withoutUserScope()->where('social_account_id', $account->id)->where('external_id', $row['external_id'])->first();

        if ($existing) {
            $existing->forceFill(['text' => $row['text'], 'hidden' => $row['hidden'], 'author_name' => $row['author_name']])->save();

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
        $comment->save();

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

        $reply = new InboxComment([
            'network' => $comment->network, 'external_id' => $id !== '' ? $id : 'own-'.$comment->id.'-'.now()->timestamp,
            'parent_external_id' => $comment->external_id, 'post_external_id' => $comment->post_external_id,
            'author_name' => $account->username ?? $account->name, 'text' => $text, 'is_own' => true, 'hidden' => false,
            'commented_at' => now(), 'read_at' => now(),
        ]);
        $reply->user_id = $comment->user_id;
        $reply->brand_id = $comment->brand_id;
        $reply->social_account_id = $account->id;
        $reply->post_target_id = $comment->post_target_id;
        $reply->save();

        $comment->forceFill(['read_at' => $comment->read_at ?? now()])->save();

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
