<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Exceptions\InvalidPostTransition;
use App\Jobs\PollPublishStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

/**
 * Mantenimiento que corre antes de despachar lo vencido, cada minuto y tambien al volver de una caida
 * del VPS: cierra lo que ya no se puede publicar y retoma lo que quedo a medias.
 *
 *  1. Destinos pendientes cuya cuenta se elimino -> `fallida` (cuenta_no_disponible), con motivo.
 *  2. Destinos vencidos hace mas de `max_lateness_hours` -> `fallida` (vencida): no se publica contenido obsoleto.
 *  3. Destinos `publicando` colgados (worker caido) -> se relanzan; el job consulta la red antes de publicar.
 *  4. Destinos `procesando` con sondeo vencido -> se vuelve a sondear.
 *  5. Destinos `procesando` cuya cuenta ya no esta activa: desconectada o borrada -> `fallida` (cuenta_no_disponible);
 *     pendiente de reconexion mas de `reconnect_grace_hours` -> `fallida` con motivo claro (nunca se quedan atascados).
 */
class PublishRecovery
{
    public function __construct(private readonly PublishOutcomes $outcomes) {}

    /** @return array{orphaned: int, expired: int, resumed: int, polled: int, abandoned: int} */
    public function run(): array
    {
        return [
            'orphaned' => $this->failOrphans(),
            'expired' => $this->failExpired(),
            'resumed' => $this->resumeStuck(),
            'polled' => $this->repoll(),
            'abandoned' => $this->failInactiveProcessing(),
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

    /** `procesando` con la cuenta borrada/desconectada, o sin reconectar pasado el plazo: no se sondean nunca, se cierran. */
    private function failInactiveProcessing(): int
    {
        $n = 0;
        $grace = (int) config('publishing.reconnect_grace_hours', 72);
        $limit = now()->subHours($grace);

        PostTarget::withoutUserScope()->where('status', PostStatus::Processing->value)
            ->chunkById(100, function ($targets) use (&$n, $limit, $grace) {
                foreach ($targets as $target) {
                    $account = $target->social_account_id ? SocialAccount::withoutUserScope()->find($target->social_account_id) : null;

                    try {
                        if ($account === null || $account->status === SocialAccount::STATUS_DISCONNECTED) {
                            $this->outcomes->failed($target, 'cuenta_no_disponible', 'La cuenta se elimino o desconecto antes de terminar de publicar. Revisa en la red si el contenido llego a publicarse y reprograma si no.');
                            $n++;
                        } elseif ($account->status === SocialAccount::STATUS_RECONNECT && $account->updated_at?->lt($limit) && $target->updated_at?->lt($limit)) {
                            $this->outcomes->failed($target, 'cuenta_no_disponible', "La cuenta lleva mas de $grace h sin reconectarse y no se pudo confirmar la publicacion. Reconectala, revisa en la red si se publico y reprograma si no.");
                            $n++;
                        }
                    } catch (InvalidPostTransition) {
                        // Carrera: el destino cambio de estado (el usuario lo cancelo, el sondeo lo cerro) entre la lectura y la
                        // transicion. Ya no es `procesando`: se ignora y se sigue con el resto, sin cortar el despacho.
                        Log::notice('publish.recovery_race', ['target_id' => $target->id]);
                    }
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
