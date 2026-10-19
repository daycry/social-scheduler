<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Jobs\PollPublishStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;

/**
 * Mantenimiento que corre antes de despachar lo vencido, cada minuto y tambien al volver de una caida
 * del VPS: cierra lo que ya no se puede publicar y retoma lo que quedo a medias.
 *
 *  1. Destinos pendientes cuya cuenta se elimino -> `fallida` (cuenta_no_disponible), con motivo.
 *  2. Destinos vencidos hace mas de `max_lateness_hours` -> `fallida` (vencida): no se publica contenido obsoleto.
 *  3. Destinos `publicando` colgados (worker caido) -> se relanzan; el job consulta la red antes de publicar.
 *  4. Destinos `procesando` con sondeo vencido -> se vuelve a sondear.
 */
class PublishRecovery
{
    public function __construct(private readonly PublishOutcomes $outcomes) {}

    /** @return array{orphaned: int, expired: int, resumed: int, polled: int} */
    public function run(): array
    {
        return [
            'orphaned' => $this->failOrphans(),
            'expired' => $this->failExpired(),
            'resumed' => $this->resumeStuck(),
            'polled' => $this->repoll(),
        ];
    }

    private function failOrphans(): int
    {
        $n = 0;

        PostTarget::withoutUserScope()->where('status', PostStatus::Scheduled->value)->whereNull('social_account_id')
            ->chunkById(100, function ($targets) use (&$n) {
                foreach ($targets as $target) {
                    $this->outcomes->failed($target, 'cuenta_no_disponible', 'La cuenta de destino se elimino antes de publicar. Conectala de nuevo y reprograma la publicacion.');
                    $n++;
                }
            });

        return $n;
    }

    private function failExpired(): int
    {
        $limit = now()->subHours((int) config('publishing.max_lateness_hours', 24));
        $n = 0;

        PostTarget::withoutUserScope()->where('status', PostStatus::Scheduled->value)
            ->whereIn('post_id', Post::withoutUserScope()->where('scheduled_at', '<', $limit)->select('id'))
            ->chunkById(100, function ($targets) use (&$n) {
                foreach ($targets as $target) {
                    $hours = (int) config('publishing.max_lateness_hours', 24);
                    $this->outcomes->failed($target, 'vencida', "No se publico a su hora y ya han pasado mas de $hours h (cuenta sin reconectar o servidor detenido). Reprogramala para publicarla.");
                    $n++;
                }
            });

        return $n;
    }

    private function resumeStuck(): int
    {
        $limit = now()->subMinutes((int) config('publishing.stuck_after_minutes', 10));
        $n = 0;

        PostTarget::withoutUserScope()->where('status', PostStatus::Publishing->value)->where('started_at', '<=', $limit)
            ->chunkById(100, function ($targets) use (&$n) {
                foreach ($targets as $target) {
                    PublishPostTarget::dispatch($target->id);
                    $n++;
                }
            });

        return $n;
    }

    private function repoll(): int
    {
        $n = 0;

        PostTarget::withoutUserScope()->where('status', PostStatus::Processing->value)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            // Con la cuenta pendiente de reconectar no se sondea: se retoma sola al reconectarla (no es un timeout).
            ->whereIn('social_account_id', SocialAccount::withoutUserScope()->where('status', SocialAccount::STATUS_ACTIVE)->select('id'))
            ->chunkById(100, function ($targets) use (&$n) {
                foreach ($targets as $target) {
                    PollPublishStatus::dispatch($target->id);
                    $n++;
                }
            });

        return $n;
    }
}
