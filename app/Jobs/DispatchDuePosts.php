<?php

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\PublishRecovery;

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
        $limit = (int) config('publishing.dispatch_batch', 200);

        PostTarget::withoutUserScope()
            ->where('status', PostStatus::Scheduled->value)
            ->whereIn('post_id', Post::withoutUserScope()->where('scheduled_at', '<=', now())->select('id'))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->whereIn('social_account_id', SocialAccount::withoutUserScope()->where('status', SocialAccount::STATUS_ACTIVE)->select('id'))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id'])
            ->each(function (PostTarget $target) use (&$dispatched) {
                PublishPostTarget::dispatch($target->id);
                $dispatched++;
            });

        return $dispatched;
    }
}
