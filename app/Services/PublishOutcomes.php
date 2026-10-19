<?php

namespace App\Services;

use App\Connectors\Data\PublishResult;
use App\Enums\PostStatus;
use App\Models\PostTarget;
use App\Models\PublishAttempt;
use App\Models\User;
use App\Notifications\PublishFailed;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Unico punto donde un destino cambia de estado como consecuencia de publicar o sondear. Cada salida
 * deja una fila en `publish_attempts` y pasa por `PostService::transition` (estados validados).
 */
class PublishOutcomes
{
    public function __construct(private readonly PostService $posts) {}

    /** Abre el intento n-esimo (historial). */
    public function startAttempt(PostTarget $target): PublishAttempt
    {
        $attempt = new PublishAttempt([
            'post_target_id' => $target->id,
            'attempt_no' => $target->attempts,
            'outcome' => PublishAttempt::OUTCOME_STARTED,
            'started_at' => now(),
        ]);
        $attempt->user_id = $target->user_id;
        $attempt->save();

        return $attempt;
    }

    public function published(PostTarget $target, PublishResult $result, ?PublishAttempt $attempt = null): PostTarget
    {
        $this->close($attempt, PublishAttempt::OUTCOME_PUBLISHED);

        return $this->posts->transition($target, PostStatus::Published, [
            'external_id' => $result->externalId,
            'external_url' => $result->url,
            'external_reference' => $result->reference ?? $target->external_reference,
        ]);
    }

    /** Publicacion asincrona en la red: se sondea hasta que termine. */
    public function processing(PostTarget $target, PublishResult $result, ?PublishAttempt $attempt = null): PostTarget
    {
        $this->close($attempt, PublishAttempt::OUTCOME_PROCESSING);

        return $this->posts->transition($target, PostStatus::Processing, [
            'external_reference' => $result->reference,
            'next_attempt_at' => now()->addSeconds((int) config('publishing.poll_interval_seconds', 30)),
        ]);
    }

    /** Aun en proceso tras un sondeo: reprograma el siguiente. */
    public function stillProcessing(PostTarget $target): PostTarget
    {
        return $this->posts->transition($target, PostStatus::Processing, [
            'next_attempt_at' => now()->addSeconds((int) config('publishing.poll_interval_seconds', 30)),
        ]);
    }

    /** Error transitorio: vuelve a `programada` con espera creciente, o falla si se agotaron los intentos. */
    public function transient(PostTarget $target, string $message, ?string $code, ?PublishAttempt $attempt = null): PostTarget
    {
        $this->close($attempt, PublishAttempt::OUTCOME_TRANSIENT, $code, $message);

        if ($target->attempts >= (int) config('publishing.max_attempts', 4)) {
            return $this->failed($target, 'reintentos_agotados', 'No se pudo publicar tras '.$target->attempts.' intentos: '.$message);
        }

        $backoff = (array) config('publishing.backoff_seconds', [60]);
        $seconds = (int) ($backoff[min($target->attempts, count($backoff)) - 1] ?? end($backoff));

        return $this->posts->transition($target, PostStatus::Scheduled, [
            'next_attempt_at' => now()->addSeconds(max(1, $seconds)),
            'error_code' => $code ?? 'transitorio',
            'error_message' => mb_substr($message, 0, 500),
        ]);
    }

    /** Cuota agotada: se aplaza hasta `$retryAt` (el intento no cuenta). */
    public function quota(PostTarget $target, string $message, CarbonInterface $retryAt, ?PublishAttempt $attempt = null): PostTarget
    {
        $this->close($attempt, PublishAttempt::OUTCOME_QUOTA, 'cuota', $message);

        return $this->posts->transition($target, PostStatus::Scheduled, [
            'attempts' => max(0, $target->attempts - 1),
            'next_attempt_at' => $retryAt,
            'error_code' => 'cuota',
            'error_message' => mb_substr($message, 0, 500),
        ]);
    }

    /** Credenciales invalidas: la publicacion se conserva, `programada`, hasta reconectar la cuenta. */
    public function awaitingReconnection(PostTarget $target, string $message, ?PublishAttempt $attempt = null): PostTarget
    {
        $this->close($attempt, PublishAttempt::OUTCOME_TRANSIENT, 'reconexion', $message);

        return $this->posts->transition($target, PostStatus::Scheduled, [
            'attempts' => max(0, $target->attempts - 1),
            'next_attempt_at' => null,
            'error_code' => 'reconexion',
            'error_message' => mb_substr($message, 0, 500),
        ]);
    }

    /** Fallo definitivo con motivo legible. */
    public function failed(PostTarget $target, string $code, string $message, ?PublishAttempt $attempt = null): PostTarget
    {
        $this->close($attempt, PublishAttempt::OUTCOME_PERMANENT, $code, $message);

        $failed = $this->posts->transition($target, PostStatus::Failed, [
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 500),
            'next_attempt_at' => null,
        ]);

        $this->notifyFailure($failed, $message);

        return $failed;
    }

    /** Avisa al usuario (in-app + email) UNA vez: solo se llega aqui en la transicion a `fallida`. */
    private function notifyFailure(PostTarget $target, string $message): void
    {
        try {
            User::find($target->user_id)?->notify(new PublishFailed($target, $message));
        } catch (Throwable $e) {
            // El aviso nunca debe impedir que el estado quede registrado.
            Log::error('publish.failure_notification_failed', ['target_id' => $target->id, 'exception' => $e::class]);
        }
    }

    private function close(?PublishAttempt $attempt, string $outcome, ?string $code = null, ?string $message = null): void
    {
        $attempt?->forceFill([
            'outcome' => $outcome,
            'error_code' => $code,
            'message' => $message !== null ? mb_substr($message, 0, 500) : null,
            'finished_at' => now(),
        ])->save();
    }
}
