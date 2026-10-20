<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Papelera de publicaciones (PA-06). Enviar a la papelera es el `PostService::delete` de siempre (soft delete): el
 * lock de destinos sigue rechazando lo publicado o en vuelo. Una publicacion en la papelera NO se publica: el despachador,
 * la recuperacion y el publicador consultan `Post` y el soft delete la excluye.
 */
class TrashService
{
    public function __construct(private readonly PostService $posts) {}

    public function trash(User $user, Post $post): void
    {
        $this->posts->delete($user, $post);
    }

    /**
     * Saca una publicacion de la papelera. Si estaba programada y su hora ya paso, vuelve a BORRADOR: restaurar
     * nunca puede disparar una publicacion inmediata sin que el usuario la reprograme.
     */
    public function restore(User $user, Post $post): Post
    {
        $this->assertTrashed($user, $post);

        DB::transaction(function () use ($post) {
            $post->restore();
        });

        $post = Post::withoutUserScope()->findOrFail($post->id);

        if ($post->targets()->where('status', 'programada')->exists() && ($post->scheduled_at === null || $post->scheduled_at->lte(now()))) {
            $post = $this->posts->unschedule($user, $post);
        }

        return $post;
    }

    /** Elimina para siempre una publicacion de la papelera. */
    public function forceDelete(User $user, Post $post): void
    {
        $this->assertTrashed($user, $post);
        $this->purgeOne($post);
    }

    /**
     * Purga lo que lleva mas de `$days` dias en la papelera (todos los usuarios; comando programado). Se salta lo que tenga
     * destinos en vuelo (no deberia haberlos).
     *
     * @return int publicaciones eliminadas
     */
    public function purgeOlderThan(int $days = 30): int
    {
        $count = 0;

        Post::withoutUserScope()->onlyTrashed()->where('deleted_at', '<', now()->subDays($days))
            ->chunkById(100, function ($posts) use (&$count) {
                foreach ($posts as $post) {
                    if ($this->purgeOne($post)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function purgeOne(Post $post): bool
    {
        return DB::transaction(function () use ($post) {
            $inFlight = PostTarget::withoutUserScope()->where('post_id', $post->id)->lockForUpdate()->get()
                ->contains(fn (PostTarget $t) => $t->status === PostStatus::Publishing
                    || ($t->status === PostStatus::Processing && $t->social_account_id !== null
                        && SocialAccount::withoutUserScope()->whereKey($t->social_account_id)->where('status', SocialAccount::STATUS_ACTIVE)->exists()));

            if ($inFlight) {
                return false;
            }

            $post->forceDelete();

            return true;
        });
    }

    private function assertTrashed(User $user, Post $post): void
    {
        if ($post->user_id !== $user->id) {
            throw new PostValidationException(['post' => ['La publicacion no pertenece al usuario.']]);
        }

        if (! $post->trashed()) {
            throw new PostValidationException(['post' => ['La publicacion no esta en la papelera.']]);
        }
    }
}
