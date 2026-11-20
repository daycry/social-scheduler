<?php

namespace App\Services\Posts;

use App\Connectors\Data\PublishResult;
use App\Enums\PostStatus;
use App\Exceptions\InvalidPostTransition;
use App\Exceptions\PostStateConflict;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\PublishRequestFactory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Maquina de estados de los destinos de una publicacion: transiciones validadas, bloqueo de los editables y el estado con el que un
 * destino empieza un intento nuevo. Es la base compartida de `PostPlanner` (que sincroniza los destinos con el plan) y de
 * `PostScheduling` (que los programa, cancela y reintenta).
 */
class PostTransitions
{
    public function __construct(private readonly PublishRequestFactory $publishRequests) {}

    /** Estado de un destino que empieza un intento NUEVO (tras un error o una edicion): nada del intento anterior sobrevive. */
    private const FRESH_ATTEMPT = [
        'external_id' => null, 'external_url' => null, 'external_reference' => null, 'attempts' => 0,
        'progress' => null, 'error_code' => null, 'error_message' => null, 'status_note' => null, 'started_at' => null, 'next_attempt_at' => null,
    ];

    /**
     * Errores que NO dejan duda de que la red no recibio nada (rechazo, medio invalido...): el resto puede haber salido.
     * `red` (timeout o corte de conexion) y `meta_no_disponible` (5xx) son los MAS ambiguos: la red pudo crear la publicacion y
     * perderse la respuesta, asi que tras editar el texto hay que reconciliar lo viejo (T-20, CA-11).
     */
    private const AMBIGUOUS_ERRORS = [null, 'transitorio', 'reintentos_agotados', 'red', 'meta_no_disponible'];

    /**
     * Pausas impuestas por la RED (cuota diaria y limites de ritmo transitorios de Meta, YouTube y TikTok): ni «Publicar ahora» ni
     * una edicion las saltan, o cada clic gastaria un intento contra un limite que sigue activo.
     */
    private const RATE_PAUSES = ['cuota', 'rate_limit', 'youtube_limite_ritmo', 'rate_limit_exceeded'];

    /**
     * Transicion validada y persistida de un target. Relee la fila con lock; lanza si no es valida.
     *
     * @param  array<string, mixed>  $attributes  Campos a fijar a la vez (external_id, error_message...).
     */
    public function transition(PostTarget $target, PostStatus $to, array $attributes = []): PostTarget
    {
        return DB::transaction(function () use ($target, $to, $attributes) {
            $fresh = PostTarget::withoutUserScope()->lockForUpdate()->findOrFail($target->id);
            $this->apply($fresh, $to, $attributes);
            Post::withoutUserScope()->withTrashed()->findOrFail($fresh->post_id)->syncStatus();

            return $fresh;
        });
    }

    /**
     * Transicion validada de un destino que el llamador ya tiene bloqueado dentro de su transaccion.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function apply(PostTarget $target, PostStatus $to, array $attributes = []): void
    {
        if (! $target->status->canTransitionTo($to)) {
            throw new InvalidPostTransition($target->status, $to);
        }

        $extra = match ($to) {
            PostStatus::Publishing => ['started_at' => now()],
            PostStatus::Published => ['published_at' => now(), 'error_code' => null, 'error_message' => null, 'next_attempt_at' => null],
            PostStatus::Draft => ['started_at' => null, 'next_attempt_at' => null],
            default => [],
        };

        $target->forceFill(array_merge($extra, $attributes, ['status' => $to]))->save();
    }

    /**
     * Relee y BLOQUEA (`lockForUpdate`) los destinos del post dentro de la transaccion en curso y exige que ninguno
     * este en vuelo ni publicado. Devuelve los destinos bloqueados, indexados por `cuenta|tipo`.
     *
     * @return Collection<string, PostTarget>
     */
    public function lockEditable(Post $post, string $message = 'La publicacion ya se ha publicado o se esta publicando y no se puede modificar.', bool $allowOrphans = false): Collection
    {
        $targets = PostTarget::withoutUserScope()->where('post_id', $post->id)->orderBy('id')->lockForUpdate()->get();

        // Borrar/cancelar sí se permite sobre un `procesando` atascado cuya cuenta no esta activa (nadie lo va a sondear).
        if ($targets->contains(fn (PostTarget $t) => ($t->status->isInFlight() || $t->status->isFinal()) && ! ($allowOrphans && $this->isOrphanedProcessing($t)))) {
            throw new PostStateConflict($message);
        }

        return $targets->keyBy(fn (PostTarget $t) => $t->social_account_id.'|'.$t->content_type->value);
    }

    /** `procesando` cuya cuenta esta borrada, desconectada o pendiente de reconectar: el sondeo no lo va a retomar. */
    public function isOrphanedProcessing(PostTarget $target): bool
    {
        if ($target->status !== PostStatus::Processing) {
            return false;
        }

        $account = $target->social_account_id ? SocialAccount::withoutUserScope()->find($target->social_account_id) : null;

        return $account === null || ! $account->isActive();
    }

    /**
     * Atributos de un destino que empieza un intento NUEVO. Si el intento anterior pudo haber salido (referencia/contenedor,
     * progreso o intentos con un error ambiguo), la prueba de idempotencia NO se pierde: se guarda en `progress.reconcile`
     * (referencia, progreso, texto y ventana del contenido viejo) y el siguiente claim la consulta en la red ANTES de publicar
     * (revision F3 i3 R1).
     *
     * @return array<string, mixed>
     */
    public function freshAttempt(PostTarget $target, ?string $oldText, ?CarbonInterface $oldScheduledAt): array
    {
        $attributes = self::FRESH_ATTEMPT;
        $carried = $target->progress['reconcile'] ?? null;

        if ($carried === null && $this->mayHaveBeenSent($target)) {
            $progress = array_diff_key((array) ($target->progress ?? []), ['reconcile' => 1]);
            $reference = $target->external_reference;
            // Una subida a medias (`subiendo`/fase uploading) no se puede retomar con el contenido nuevo ni consultar por referencia.
            $uploading = PublishResult::isUploadingState($reference, $progress);

            $carried = array_filter([
                'reference' => $uploading ? null : $reference,
                'progress' => $uploading || $progress === [] ? null : $progress,
                'text' => $oldText,
                // Ventana desde la hora programada Y el primer intento del destino (la fecha pudo moverse despues de un intento ambiguo):
                // la misma que usa `PublishRequestFactory::make()`, no solo la fecha vieja (revision F2 i2, N1).
                'since' => $this->publishRequests->searchWindowStart($target, $oldScheduledAt)?->toIso8601String(),
            ], fn ($v) => $v !== null) + ['pending' => true];
        }

        $attributes['progress'] = $carried !== null ? ['reconcile' => $carried] : null;

        return $attributes;
    }

    /** Texto efectivo de un destino con el texto de plantilla indicado (el propio si lo tiene; `null` = vinculado). */
    public function effectiveText(PostTarget $target, ?string $templateText): ?string
    {
        return $target->text ?? $templateText;
    }

    /**
     * «Publicar ahora» salta la espera de un reintento transitorio, pero NO la pausa por cuota de la red.
     *
     * @return array<string, mixed>
     */
    public function releaseBackoff(PostTarget $target, bool $publishNow): array
    {
        if (! $publishNow || $target->status !== PostStatus::Scheduled || $target->next_attempt_at === null || in_array($target->error_code, self::RATE_PAUSES, true)) {
            return [];
        }

        return ['next_attempt_at' => null];
    }

    /**
     * Un destino en pausa por la red (cuota o limite de ritmo, aun vigente) que empieza un intento NUEVO por una edicion conserva
     * la pausa: `FRESH_ATTEMPT` la borraria y «Publicar ahora» (o el despachador) volveria a tocar la red antes de tiempo.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function keepRatePause(PostTarget $current, array $attributes): array
    {
        if ($current->status === PostStatus::Scheduled && $current->next_attempt_at?->isFuture() && in_array($current->error_code, self::RATE_PAUSES, true)) {
            return array_merge($attributes, ['next_attempt_at' => $current->next_attempt_at, 'error_code' => $current->error_code, 'error_message' => $current->error_message]);
        }

        return $attributes;
    }

    /** Destinos `programada` que «Publicar ahora» no ha podido encolar porque la red los tiene en pausa (cuota). */
    public static function pausedTargets(Post $post): int
    {
        return $post->targets()->where('status', PostStatus::Scheduled->value)->where('next_attempt_at', '>', now())->count();
    }

    private function mayHaveBeenSent(PostTarget $target): bool
    {
        return $target->external_reference !== null
            || (array) ($target->progress ?? []) !== []
            || ($target->attempts > 0 && in_array($target->error_code, self::AMBIGUOUS_ERRORS, true));
    }
}
