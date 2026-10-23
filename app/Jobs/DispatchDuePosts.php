<?php

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\PublishRecovery;
use Illuminate\Support\Collection;

/**
 * Despachador (cada minuto desde el scheduler, en linea: no depende de que la cola este libre): primero recupera lo colgado/vencido y despues encola un
 * `PublishPostTarget` por cada destino `programada` cuya hora ha llegado (o cuyo reintento toca) y cuya
 * cuenta esta activa. Las cuentas que necesitan reconexion se dejan: sus publicaciones se conservan y
 * salen al reconectar (si no han caducado, ver `max_lateness_hours`).
 */
class DispatchDuePosts
{
    public function __construct(private readonly PublishRecovery $recovery) {}

    public function __invoke(): int
    {
        $this->recovery->run();

        $dispatched = 0;

        static::dueTargets(null, (int) config('publishing.dispatch_batch', 200))->each(function (int $id) use (&$dispatched) {
            PublishPostTarget::dispatch($id);
            $dispatched++;
        });

        return $dispatched;
    }

    /**
     * Destinos `programada` listos para publicar: su hora ha llegado (o su reintento toca) y su cuenta esta activa. Es la
     * consulta del despachador del minuto y tambien la de «Publicar ahora» (`PostService::publishNow()`, con `$post`): asi las
     * dos rutas encolan exactamente lo mismo y comparten las defensas de `PublishPostTarget` (`ShouldBeUnique` + `claim()`).
     *
     * @return Collection<int, int> ids de `PostTarget`
     */
    public static function dueTargets(?Post $post = null, ?int $limit = null): Collection
    {
        return PostTarget::withoutUserScope()
            ->where('status', PostStatus::Scheduled->value)
            ->when($post !== null, fn ($q) => $q->where('post_id', $post->id))
            ->whereIn('post_id', Post::withoutUserScope()->where('scheduled_at', '<=', now())->select('id'))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->whereIn('social_account_id', SocialAccount::withoutUserScope()->where('status', SocialAccount::STATUS_ACTIVE)->select('id'))
            ->orderBy('id')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }
}
