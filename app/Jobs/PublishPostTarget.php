<?php

namespace App\Jobs;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\Publisher;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PublishAttempt;
use App\Models\SocialAccount;
use App\Services\PostService;
use App\Services\PublishOutcomes;
use App\Services\PublishRequestFactory;
use App\Services\QuotaPolicy;
use App\Services\SocialAccountService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publica UN destino. Garantias (CA-06/CA-07):
 *  - Unico por destino (`ShouldBeUnique`) y, ademas, reclama la fila con `lockForUpdate`: pasa a `publicando`
 *    y se PERSISTE antes de llamar a la red; dos workers no publican a la vez.
 *  - Antes de cualquier reintento (o si ya hay referencia externa) consulta el estado externo
 *    (`Publisher::findPublished`) y solo publica si la red no tiene constancia: sin duplicados tras un timeout.
 *  - Los errores se clasifican: transitorio (vuelve a `programada` con backoff; el despachador lo relanza),
 *    permanente (`fallida` con motivo), cuota (se aplaza) y credenciales (conserva la publicacion).
 *  - Un fallo inesperado deja el destino `publicando`; `PublishRecovery` lo retoma pasado `stuck_after_minutes`.
 *
 * Presupuesto de tiempo: $timeout (110 s) < `--timeout` del worker (120 s) < `retry_after` de la cola (180 s).
 */
class PublishPostTarget implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 110;

    /** Solo cubre cortes de infraestructura; los reintentos de negocio los gestiona el estado del destino. */
    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30];

    public int $uniqueFor = 600;

    /** Nota del destino cuando lo que se iba a publicar tras una edicion ya habia salido en el intento anterior. */
    public const EDIT_NOT_APPLIED = 'La edicion no se aplico: la publicacion anterior ya habia salido en la red.';

    public function __construct(public readonly int $targetId)
    {
        $this->onQueue((string) config('publishing.queue', 'publish'));
    }

    public function uniqueId(): string
    {
        return (string) $this->targetId;
    }

    public function handle(
        ConnectorRegistry $connectors,
        PublishOutcomes $outcomes,
        PublishRequestFactory $requests,
        SocialAccountService $accounts,
    ): void {
        $claim = $this->claim($outcomes);

        if ($claim === null) {
            return; // ya publicada, en vuelo en otro worker, aplazada o replanificada
        }

        [$target, $attempt, $exhausted] = $claim;

        $account = $target->social_account_id ? SocialAccount::withoutUserScope()->find($target->social_account_id) : null;

        if (! $account || $account->status === SocialAccount::STATUS_DISCONNECTED) {
            $outcomes->failed($target, 'cuenta_no_disponible', 'La cuenta de destino se desconecto o se elimino antes de publicar. Vuelve a conectarla y reprograma la publicacion.', $attempt);

            return;
        }

        if (! $account->isActive()) {
            $outcomes->awaitingReconnection($target, 'La cuenta necesita reconectarse; la publicacion se conserva y se enviara al reconectar.', $attempt);

            return;
        }

        $publisher = $connectors->publisherFor($target->network);

        if (! $publisher) {
            $outcomes->failed($target, 'red_no_soportada', "Aun no se puede publicar en {$target->network->label()}.", $attempt);

            return;
        }

        try {
            $request = $requests->make($target);
            $result = null;

            // Edicion tras un intento ambiguo: antes de publicar lo nuevo se comprueba si lo VIEJO ya salio (sin duplicar).
            $reconcile = $target->progress['reconcile'] ?? null;
            if (is_array($reconcile)) {
                if ($this->reconcile($publisher, $account, $requests, $outcomes, $target, $attempt, $request, $reconcile)) {
                    return;
                }

                $target->forceFill(['progress' => null]);
                PostTarget::withoutUserScope()->whereKey($target->id)->update(['progress' => null]);
            }

            // Idempotencia: si ya hubo un intento (o una referencia externa), la red manda.
            if ($exhausted || $target->attempts > 1 || $target->external_reference !== null) {
                $result = $publisher->findPublished($account, $request);
            }

            if ($exhausted && $result === null) {
                // Colgado en el ultimo intento y la red no tiene constancia: ahora si, fallo definitivo.
                $outcomes->failed($target, 'reintentos_agotados', 'La publicacion se quedo colgada varias veces y se dio por fallida.', $attempt);

                return;
            }

            $result ??= $publisher->publish($account, $request);

            $this->apply($outcomes, $target, $attempt, $result);
        } catch (ReconnectRequiredException $e) {
            $this->requireReconnection($accounts, $outcomes, $account, $target, $attempt, $e->getMessage());
        } catch (QuotaExceededException $e) {
            app(QuotaPolicy::class)->apply($target, $e, $attempt);
        } catch (PermanentPublishException $e) {
            $outcomes->failed($target, $e->errorCode ?? 'rechazada', $e->getMessage(), $attempt);
        } catch (TransientPublishException|ConnectorException $e) {
            $outcomes->transient($target, $e->getMessage(), $e instanceof TransientPublishException ? $e->errorCode : null, $attempt);
        } catch (Throwable $e) {
            // Inesperado: no se reintenta en caliente (podria duplicar). Queda `publicando` y lo recupera
            // PublishRecovery, que consulta la red antes de volver a publicar.
            Log::error('publish.unexpected_error', ['target_id' => $target->id, 'exception' => $e::class]);
        }
    }

    /**
     * Reconcilia el intento previo a una edicion. Devuelve `true` si la red ya tenia el contenido viejo (el destino queda
     * `publicada`/`procesando` con la nota «la edicion no se aplico») y `false` si no hay constancia (se limpia el marcador y se publica).
     *
     * @param  array<string, mixed>  $reconcile
     */
    private function reconcile(Publisher $publisher, SocialAccount $account, PublishRequestFactory $requests, PublishOutcomes $outcomes, PostTarget $target, PublishAttempt $attempt, PublishRequest $request, array $reconcile): bool
    {
        try {
            $found = $publisher->findPublished($account, $requests->makeReconcile($request, $reconcile));
        } catch (PermanentPublishException $e) {
            Log::notice('publish.reconcile_inconclusive', ['target_id' => $target->id, 'code' => $e->errorCode]);

            return false;
        }

        $sent = $found !== null && ($found->isPublished() || ($found->isProcessing() && ! ($found->raw['not_published'] ?? false)));

        if (! $sent) {
            return false;
        }

        $note = self::EDIT_NOT_APPLIED.($found->note !== null ? ' '.$found->note : '');
        Log::notice('publish.reconciled_before_edit', ['target_id' => $target->id, 'status' => $found->status]);

        if ($found->isPublished()) {
            $outcomes->published($target, PublishResult::published((string) $found->externalId, $found->url, $found->raw, $note, ($found->progress ?? []) + ['reconciled' => true]), $attempt);
        } else {
            $this->startPolling($outcomes, $target, PublishResult::processing((string) $found->reference, $found->raw, $found->progress ?? ['reconciled' => true]), $attempt);
            PostTarget::withoutUserScope()->whereKey($target->id)->update(['status_note' => mb_substr($note, 0, 300)]);
        }

        return true;
    }

    /**
     * Reclama el destino de forma atomica. Devuelve [destino, intento] o null si no hay nada que hacer.
     *
     * El tercer elemento es `true` cuando el destino se quedo colgado en el ultimo intento permitido: no se publica
     * otra vez, solo se consulta la red (`findPublished`) antes de darlo por fallido.
     *
     * @return array{0: PostTarget, 1: PublishAttempt, 2: bool}|null
     */
    private function claim(PublishOutcomes $outcomes): ?array
    {
        return DB::transaction(function () use ($outcomes): ?array {
            $exhausted = false;
            $target = PostTarget::withoutUserScope()->lockForUpdate()->find($this->targetId);
            $post = $target ? Post::withoutUserScope()->find($target->post_id) : null;

            if (! $target || ! $post) {
                return null;
            }

            if ($target->status === PostStatus::Scheduled) {
                if ($post->scheduled_at === null || $post->scheduled_at->isFuture() || ($target->next_attempt_at && $target->next_attempt_at->isFuture())) {
                    return null;
                }

                $target = app(PostService::class)->transition($target, PostStatus::Publishing, ['attempts' => $target->attempts + 1]);
            } elseif ($target->status === PostStatus::Publishing) {
                // En vuelo en otro worker: no tocar. Colgado: se retoma como un reintento mas.
                $stuckAfter = (int) config('publishing.stuck_after_minutes', 10);
                if ($target->started_at && $target->started_at->gt(now()->subMinutes($stuckAfter))) {
                    return null;
                }

                $exhausted = $target->attempts >= (int) config('publishing.max_attempts', 4);
                $target->forceFill($exhausted ? ['started_at' => now()] : ['attempts' => $target->attempts + 1, 'started_at' => now()])->save();
            } else {
                return null;
            }

            return [$target, $outcomes->startAttempt($target), $exhausted];
        });
    }

    private function apply(PublishOutcomes $outcomes, PostTarget $target, PublishAttempt $attempt, PublishResult $result): void
    {
        match (true) {
            $result->isPublished() => $outcomes->published($target, $result, $attempt),
            $result->isProcessing() => $this->startPolling($outcomes, $target, $result, $attempt),
            default => $outcomes->failed($target, 'rechazada', $result->message ?? 'La red rechazo la publicacion.', $attempt),
        };
    }

    private function startPolling(PublishOutcomes $outcomes, PostTarget $target, PublishResult $result, PublishAttempt $attempt): void
    {
        $outcomes->processing($target, $result, $attempt);

        PollPublishStatus::dispatch($target->id)->delay(now()->addSeconds((int) config('publishing.poll_interval_seconds', 30)));
    }

    private function requireReconnection(SocialAccountService $accounts, PublishOutcomes $outcomes, SocialAccount $account, PostTarget $target, PublishAttempt $attempt, string $reason): void
    {
        $accounts->flagReconnection($account, $reason);
        $outcomes->awaitingReconnection($target, $reason, $attempt);
    }
}
