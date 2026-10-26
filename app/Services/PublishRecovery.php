<?php

namespace App\Services;

use App\Enums\FirstCommentStatus;
use App\Enums\PostStatus;
use App\Exceptions\InvalidPostTransition;
use App\Jobs\PollPublishStatus;
use App\Jobs\PostFirstComment;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\FirstCommentFailed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 *  6. Primer comentario (C-01b): `comentando` colgado o `pendiente` sin job vivo mas de `stuck_after_minutes` -> se relanza
 *     (`PostFirstComment` es unico y busca el comentario propio antes de crear otro).
 */
class PublishRecovery
{
    public function __construct(private readonly PublishOutcomes $outcomes) {}

    /** @return array{orphaned: int, expired: int, resumed: int, polled: int, abandoned: int, comments: int} */
    public function run(): array
    {
        return [
            'orphaned' => $this->failOrphans(),
            'expired' => $this->failExpired(),
            'resumed' => $this->resumeStuck(),
            'polled' => $this->repoll(),
            'abandoned' => $this->failInactiveProcessing(),
            'comments' => $this->resumeComments(),
        ];
    }

    private function failOrphans(): int
    {
        $n = 0;

        PostTarget::withoutUserScope()->where('status', PostStatus::Scheduled->value)->whereNull('social_account_id')
            ->whereIn('post_id', $this->livePosts())
            ->chunkById(100, function ($targets) use (&$n) {
                foreach ($targets as $target) {
                    $this->guard($target, function () use ($target, &$n) {
                        $this->outcomes->failed($target, 'cuenta_no_disponible', 'La cuenta de destino se elimino antes de publicar. Conectala de nuevo y reprograma la publicacion.');
                        $n++;
                    });
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
            ->whereIn('post_id', $this->livePosts())
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
                    } catch (Throwable $e) {
                        // Error real (BD, notificacion...): no es una carrera. Se reporta y se registra con clase y mensaje, y se sigue.
                        report($e);
                        Log::error('publish.recovery_failed', ['target_id' => $target->id, 'exception' => $e::class, 'message' => $e->getMessage()]);
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
                    $this->guard($target, function () use ($target, $hours, &$n) {
                        $this->outcomes->failed($target, 'vencida', "No se publico a su hora y ya han pasado mas de $hours h (cuenta sin reconectar o servidor detenido). Reprogramala para publicarla.");
                        $n++;
                    });
                }
            });

        return $n;
    }

    private function resumeStuck(): int
    {
        $limit = now()->subMinutes((int) config('publishing.stuck_after_minutes', 10));
        $n = 0;

        PostTarget::withoutUserScope()->where('status', PostStatus::Publishing->value)->where('started_at', '<=', $limit)
            ->whereIn('post_id', $this->livePosts())
            ->chunkById(100, function ($targets) use (&$n) {
                foreach ($targets as $target) {
                    PublishPostTarget::dispatch($target->id);
                    $n++;
                }
            });

        return $n;
    }

    /** Paso 6: relanza los primeros comentarios colgados (`comentando`) o pendientes sin job vivo. El job es unico por destino. */
    private function resumeComments(): int
    {
        $limit = now()->subMinutes((int) config('publishing.stuck_after_minutes', 10));
        $n = 0;

        // Sin id de publicacion el job no puede reclamar el destino (`claim()` devuelve null siempre): se cierra con motivo en vez
        // de relanzarlo en cada pasada. `fallido` deja Reintentar/Editar al usuario.
        PostTarget::withoutUserScope()->where('status', PostStatus::Published->value)
            ->whereNull('first_comment_external_id')
            ->whereNull('external_id')
            ->whereIn('first_comment_status', [FirstCommentStatus::Pending->value, FirstCommentStatus::Commenting->value])
            ->whereIn('post_id', $this->livePosts())
            ->chunkById(100, function ($targets) {
                foreach ($targets as $target) {
                    $this->guard($target, function () use ($target) {
                        $reason = 'La red no devolvio el identificador de la publicacion, asi que no se puede comentar en ella. Comentalo a mano en la red.';
                        $target->forceFill(['first_comment_status' => FirstCommentStatus::Failed, 'first_comment_error' => $reason])->save();
                        User::find($target->user_id)?->notify(new FirstCommentFailed($target, $reason)); // como el resto de cierres en `fallido`
                    });
                }
            });

        PostTarget::withoutUserScope()->where('status', PostStatus::Published->value)
            ->whereNull('first_comment_external_id')
            ->whereNotNull('external_id')
            ->whereIn('post_id', $this->livePosts())
            ->where(fn ($q) => $q
                ->where(fn ($c) => $c->where('first_comment_status', FirstCommentStatus::Commenting->value)->where('first_comment_at', '<=', $limit))
                ->orWhere(fn ($p) => $p->where('first_comment_status', FirstCommentStatus::Pending->value)->where('updated_at', '<=', $limit)))
            ->chunkById(100, function ($targets) use (&$n) {
                foreach ($targets as $target) {
                    PostFirstComment::dispatch($target->id);
                    $n++;
                }
            });

        return $n;
    }

    private function repoll(): int
    {
        $n = 0;

        PostTarget::withoutUserScope()->where('status', PostStatus::Processing->value)
            ->whereIn('post_id', $this->livePosts())
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

    /** Posts vivos (la papelera queda fuera): ninguna consulta de recuperacion toca destinos de una publicacion borrada. */
    private function livePosts(): Builder
    {
        return Post::withoutUserScope()->select('id');
    }

    /** Aisla un destino: un fallo en uno nunca corta la pasada (ni el despacho de todos los demas). */
    private function guard(PostTarget $target, \Closure $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning('publish.recovery_target_failed', ['target_id' => $target->id, 'exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }
}
