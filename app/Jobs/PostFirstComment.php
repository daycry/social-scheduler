<?php

namespace App\Jobs;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\CommentNotAllowedException;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Enums\FirstCommentStatus;
use App\Enums\PostStatus;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\FirstCommentFailed;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deja el primer comentario de UN destino ya publicado (C-01b, opcion A1). Garantias:
 *  - Unico por destino (`ShouldBeUnique`, `fc:{id}`) y, ademas, reclama la fila con `lockForUpdate`: solo pasa de `pendiente`
 *    (o de `comentando` colgado mas de `stuck_after_minutes`) a `comentando`, y se PERSISTE antes de llamar a la red.
 *  - Idempotencia en tres capas: (1) si ya hay `first_comment_external_id` no hace nada; (2) SIEMPRE busca el comentario propio
 *    en la red antes de crear uno; (3) crea y guarda el id en la misma pasada. La unica ventana de duplicado es «la red acepto
 *    y el worker murio antes de guardar el id»: el siguiente intento la cierra con la busqueda.
 *  - Un fallo del comentario NUNCA degrada el destino (`status` sigue `publicada`): solo cambia `first_comment_status`.
 *  - Transitorio -> vuelve a `pendiente` y se reintenta con backoff (`publishing.comment_backoff_seconds`), tope
 *    `publishing.comment_max_attempts`. Permanente o reconexion -> `fallido` con el motivo. Cerrado / privado -> `no_admitido`.
 *  - Un fallo inesperado deja el destino `comentando`; `PublishRecovery` lo retoma pasado `stuck_after_minutes`.
 */
class PostFirstComment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 110;

    /** Solo cubre cortes de infraestructura y las liberaciones con backoff; el tope de negocio es `comment_max_attempts`. */
    public int $tries = 6;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $targetId)
    {
        $this->onQueue((string) config('publishing.comment_queue', 'poll'));
    }

    public function uniqueId(): string
    {
        return 'fc:'.$this->targetId;
    }

    public function handle(ConnectorRegistry $connectors): void
    {
        $claim = $this->claim();

        if ($claim === null) {
            return; // nada que hacer: ya comentado, en vuelo en otro worker, resuelto o sin texto
        }

        [$target, $exhausted] = $claim;

        $account = $target->social_account_id ? SocialAccount::withoutUserScope()->find($target->social_account_id) : null;

        if (! $account || $account->status === SocialAccount::STATUS_DISCONNECTED) {
            $this->settle($target, FirstCommentStatus::Failed, 'La cuenta se desconecto o se elimino antes de comentar. Vuelve a conectarla y pulsa Reintentar.');

            return;
        }

        if (! $account->isActive()) {
            $this->settle($target, FirstCommentStatus::Failed, 'La cuenta necesita reconectarse para comentar. Reconectala y pulsa Reintentar.');

            return;
        }

        $commenter = $connectors->commenterFor($target->network);

        if (! $commenter) {
            $this->settle($target, FirstCommentStatus::NotAllowed, "{$target->network->label()} no admite el primer comentario.");

            return;
        }

        $text = (string) $target->first_comment;

        try {
            // Capas 2 y 3: la red manda. Si el comentario propio ya esta, se adopta; si no, se crea y se guarda el id.
            $id = $commenter->findOwnComment($account, (string) $target->external_id, $text);

            if ($id === null && $exhausted) {
                // Colgado en el ultimo intento y la red no tiene constancia: no se crea otro, se da por fallido.
                $this->settle($target, FirstCommentStatus::Failed, 'El comentario se quedo colgado varias veces y se dio por fallido. Pulsa Reintentar para volver a intentarlo.');

                return;
            }

            $id ??= $commenter->comment($account, (string) $target->external_id, $text);

            $this->settle($target, FirstCommentStatus::Published, null, $id);
        } catch (CommentNotAllowedException $e) {
            $this->settle($target, FirstCommentStatus::NotAllowed, $e->getMessage());
        } catch (ReconnectRequiredException|PermanentPublishException $e) {
            $this->settle($target, FirstCommentStatus::Failed, $e->getMessage());
        } catch (TransientPublishException|QuotaExceededException|ConnectorException $e) {
            $this->retryLater($target, $e->getMessage());
        } catch (Throwable $e) {
            // Inesperado: no se reintenta en caliente (podria duplicar). Queda `comentando` y lo recupera PublishRecovery,
            // que busca el comentario propio antes de volver a crearlo.
            Log::error('first_comment.unexpected_error', ['target_id' => $target->id, 'exception' => $e::class]);
        }
    }

    /**
     * Reclama el destino de forma atomica. Devuelve [destino reclamado (en `comentando`), agotado] o null.
     *
     * `agotado` es `true` cuando el comentario se quedo colgado en el ultimo intento permitido: no cuenta otro intento ni
     * crea otro comentario, solo consulta la red (como `PublishPostTarget`), para que un worker que muere siempre no reintente sin fin.
     *
     * @return array{0: PostTarget, 1: bool}|null
     */
    private function claim(): ?array
    {
        return DB::transaction(function (): ?array {
            $exhausted = false;
            $target = PostTarget::withoutUserScope()->lockForUpdate()->find($this->targetId);

            if (! $target || $target->status !== PostStatus::Published || $target->external_id === null
                || trim((string) $target->first_comment) === '' || $target->first_comment_external_id !== null) {
                return null;
            }

            if ($target->first_comment_status === FirstCommentStatus::Commenting) {
                $stuckAfter = (int) config('publishing.stuck_after_minutes', 10);
                if ($target->first_comment_at === null || $target->first_comment_at->gt(now()->subMinutes($stuckAfter))) {
                    return null; // en vuelo en otro worker
                }
                $exhausted = (int) $target->first_comment_attempts >= (int) config('publishing.comment_max_attempts', 3);
            } elseif ($target->first_comment_status !== FirstCommentStatus::Pending) {
                return null;
            }

            $target->forceFill([
                'first_comment_status' => FirstCommentStatus::Commenting,
                'first_comment_attempts' => $exhausted ? (int) $target->first_comment_attempts : (int) $target->first_comment_attempts + 1,
                'first_comment_at' => now(),
            ])->save();

            return [$target, $exhausted];
        });
    }

    /** Cierra el comentario en un estado final. Nunca toca `status` del destino. */
    private function settle(PostTarget $target, FirstCommentStatus $status, ?string $message = null, ?string $externalId = null): void
    {
        $target->forceFill([
            'first_comment_status' => $status,
            'first_comment_external_id' => $externalId ?? $target->first_comment_external_id,
            'first_comment_error' => $message !== null ? mb_substr($message, 0, 500) : null,
        ])->save();

        if ($status === FirstCommentStatus::Failed) {
            $this->notifyFailure($target, (string) $message);
        }
    }

    /** Aviso in-app UNA vez por fallo definitivo; nunca impide que el estado quede registrado. */
    private function notifyFailure(PostTarget $target, string $message): void
    {
        try {
            User::find($target->user_id)?->notify(new FirstCommentFailed($target, $message));
        } catch (Throwable $e) {
            Log::error('first_comment.failure_notification_failed', ['target_id' => $target->id, 'exception' => $e::class]);
        }
    }

    private function retryLater(PostTarget $target, string $message): void
    {
        $max = (int) config('publishing.comment_max_attempts', 3);

        if ((int) $target->first_comment_attempts >= $max) {
            $this->settle($target, FirstCommentStatus::Failed, 'No se pudo comentar tras '.$target->first_comment_attempts.' intentos: '.$message);

            return;
        }

        $this->settle($target, FirstCommentStatus::Pending, $message);

        $backoff = array_values((array) config('publishing.comment_backoff_seconds', [60, 300]));
        $seconds = (int) ($backoff[min((int) $target->first_comment_attempts, count($backoff)) - 1] ?? end($backoff) ?: 60);

        // `release()` conserva el candado de unicidad: un `dispatch()` desde dentro del job seria descartado.
        $this->job?->release(max(1, $seconds));
    }
}
