<?php

namespace App\Services;

use App\Connectors\Exceptions\QuotaExceededException;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PublishAttempt;

/**
 * Que hacer cuando la red dice que la cuota de publicacion esta agotada (p. ej. `content_publishing_limit` de
 * Instagram). Decision de producto configurable (`PUBLISH_QUOTA_POLICY`):
 *
 *  - `reschedule` (por defecto): se aplaza hasta que la red libere cupo (`retryAt`, o 1 h si no lo dice) sin gastar
 *    un intento; si el aplazamiento sobrepasaria el margen de retraso (`max_lateness_hours` tras la hora
 *    programada) se da por fallida con motivo «cuota» y se avisa.
 *  - `fail`: falla de inmediato con motivo «cuota» y se avisa.
 */
class QuotaPolicy
{
    public const RESCHEDULE = 'reschedule';

    public const FAIL = 'fail';

    public function __construct(private readonly PublishOutcomes $outcomes) {}

    public function policy(): string
    {
        return config('publishing.quota_policy') === self::FAIL ? self::FAIL : self::RESCHEDULE;
    }

    public function apply(PostTarget $target, QuotaExceededException $e, ?PublishAttempt $attempt = null): PostTarget
    {
        $retryAt = $e->retryAt ?? now()->addHour();

        if ($this->policy() === self::FAIL) {
            return $this->outcomes->failed($target, 'cuota', 'Cuota de publicacion agotada: '.$e->getMessage().' Reprograma la publicacion para otro momento.', $attempt);
        }

        $post = Post::withoutUserScope()->find($target->post_id);
        $deadline = $post?->scheduled_at?->copy()->addHours((int) config('publishing.max_lateness_hours', 24));

        if ($deadline !== null && $retryAt->gt($deadline)) {
            return $this->outcomes->failed($target, 'cuota', 'Cuota de publicacion agotada y no se libera a tiempo: '.$e->getMessage().' Reprograma la publicacion para otro momento.', $attempt);
        }

        return $this->outcomes->quota($target, $e->getMessage(), $retryAt, $attempt);
    }
}
