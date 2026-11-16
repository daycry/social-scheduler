<?php

namespace App\Services\Posts;

use App\Connectors\Google\YouTubeResumableUpload;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de una publicacion ya creada: devolverla a borrador, reprogramarla, borrarla y reintentar sus destinos fallidos. Todas
 * las operaciones bloquean los destinos antes de decidir y rechazan lo que ya esta en vuelo o publicado.
 */
class PostScheduling
{
    /** `error_code` de un destino al que le falta un archivo de su media (migracion T-06): no se reintenta hasta editar su media. */
    public const MEDIA_MISSING = 'media_inexistente';

    public const MEDIA_MISSING_RETRY = 'A un destino le falta un archivo de su media: editalo y vuelve a elegir la media antes de reintentar.';

    public function __construct(private readonly PostPlanner $planner, private readonly PostTransitions $transitions) {}

    /** Vuelve a borrador (deja de publicarse) una publicacion pendiente. */
    public function unschedule(Post $post): Post
    {
        DB::transaction(function () use ($post) {
            foreach ($this->transitions->lockEditable($post, allowOrphans: true) as $target) {
                if ($this->transitions->isOrphanedProcessing($target)) {
                    // Cancelar un destino atascado `procesando` (cuenta no activa): se cierra como fallido y vuelve a borrador.
                    $this->transitions->apply($target, PostStatus::Failed, ['error_code' => 'cuenta_no_disponible', 'error_message' => 'Cancelada por el usuario: la cuenta ya no esta disponible.']);
                }
                if ($target->status !== PostStatus::Draft) {
                    // Al volver a borrador el estado residual del intento anterior se descarta (el contenido puede cambiar).
                    $this->transitions->apply($target, PostStatus::Draft, $this->transitions->freshAttempt($target, $this->transitions->effectiveText($target, $post->text), $post->scheduled_at));
                    YouTubeResumableUpload::forgetSession($target->id);
                }
            }
            $post->syncStatus();
        });

        return $post->fresh();
    }

    /**
     * Cambia la fecha de una publicacion pendiente (CA-08); una ya publicada no se reprograma. Los destinos
     * `fallida` vuelven a `programada` como un destino NUEVO: sin referencia externa, intentos ni estado de
     * sondeo del intento anterior (el historial en `publish_attempts` se conserva).
     */
    public function reschedule(Post $post, string|CarbonInterface $when): Post
    {
        $brand = Brand::withoutUserScope()->findOrFail($post->brand_id);
        $at = $this->planner->parseWhen($when, $brand);

        if ($at->lte(now())) {
            throw new PostValidationException(['scheduled_at' => ['La nueva fecha debe ser futura.']]);
        }

        DB::transaction(function () use ($post, $at) {
            $targets = $this->transitions->lockEditable($post);
            $oldScheduledAt = $post->scheduled_at;

            // Un destino de una red apagada en esta instalacion (p. ej. BLUESKY_ENABLED=false) no se reprograma: volveria a fallar.
            $off = $targets->first(fn (PostTarget $t) => ! $t->content_type->network()->isAvailable());
            if ($off !== null) {
                throw new PostValidationException(['post' => [$off->content_type->network()->label().' no esta disponible en esta instalacion: quita ese destino antes de reprogramar.']]);
            }

            // Mover una ocurrencia a mano la saca de la serie: `reconcile()` no la devuelve a su fecha.
            $post->forceFill(['scheduled_at' => $at] + ($post->series_id !== null ? ['series_exception' => true] : []))->save();
            PostTarget::withoutUserScope()->where('post_id', $post->id)->where('manual', true)->update(['manual_notified_at' => null, 'manual_reminded_at' => null]);
            $targets->filter(fn (PostTarget $t) => $t->status === PostStatus::Failed)
                ->each(function (PostTarget $t) use ($post, $oldScheduledAt) {
                    $this->transitions->transition($t, PostStatus::Scheduled, $this->transitions->freshAttempt($t, $this->transitions->effectiveText($t, $post->text), $oldScheduledAt));
                    YouTubeResumableUpload::forgetSession($t->id); // la sesion de subida del intento anterior no se reutiliza
                });
            $post->syncStatus();
        });

        return $post->fresh();
    }

    /** Borra una publicacion que aun no se ha publicado ni esta en vuelo. */
    public function delete(Post $post): void
    {
        DB::transaction(function () use ($post) {
            $targets = $this->transitions->lockEditable($post, 'No se puede borrar una publicacion que ya se ha publicado o se esta publicando.', allowOrphans: true);

            // Nada pendiente sobrevive en la papelera: un `procesando` huerfano se cierra como fallido y lo programado vuelve a
            // borrador (restaurar nunca reactiva una publicacion; el usuario la reprograma).
            foreach ($targets as $target) {
                if ($this->transitions->isOrphanedProcessing($target)) {
                    $this->transitions->apply($target, PostStatus::Failed, ['error_code' => 'cuenta_no_disponible', 'error_message' => 'Enviada a la papelera: la cuenta ya no estaba disponible.']);
                } elseif ($target->status === PostStatus::Scheduled) {
                    $this->transitions->apply($target, PostStatus::Draft, $this->transitions->freshAttempt($target, $this->transitions->effectiveText($target, $post->text), $post->scheduled_at));
                    YouTubeResumableUpload::forgetSession($target->id);
                }
            }
            $post->syncStatus();
            $post->delete();
        });
    }

    /**
     * «Reintentar» manual (T-19): los destinos `fallida` vuelven a `programada` como un intento NUEVO y se encolan. Idempotente: lo que ya
     * esta en vuelo, programado o publicado no se toca, asi que repetir la accion (o pulsarla con el job en curso) nunca publica dos veces;
     * el `claim()` de `PublishPostTarget` y su `ShouldBeUnique` son la segunda defensa. Exige que el post pueda publicarse (aprobado).
     *
     * @return array{post: Post, retried: int, skipped: int}
     */
    public function retryFailed(Post $post): array
    {
        if (! $post->isPublishable()) {
            throw new PostValidationException(['approval' => ['La publicacion requiere aprobacion antes de publicarse.']]);
        }

        $retried = [];
        $skipped = 0;
        $blocked = 0;
        DB::transaction(function () use ($post, &$retried, &$skipped, &$blocked) {
            $targets = PostTarget::withoutUserScope()->where('post_id', $post->id)->orderBy('id')->lockForUpdate()->get();
            $locked = Post::withoutUserScope()->lockForUpdate()->findOrFail($post->id);
            foreach ($targets as $target) {
                if ($target->status !== PostStatus::Failed || ! $target->content_type->network()->isAvailable()) {
                    $skipped++;

                    continue;
                }
                // Marcado por la migracion de T-06 (falta un archivo de su media): reintentarlo publicaria otra cosa; hay que editar la media.
                if ($target->error_code === self::MEDIA_MISSING) {
                    $blocked++;
                    $skipped++;

                    continue;
                }
                $this->transitions->apply($target, PostStatus::Scheduled, $this->transitions->freshAttempt($target, $this->transitions->effectiveText($target, $locked->text), $locked->scheduled_at) + ['manual_notified_at' => null, 'manual_reminded_at' => null]);
                YouTubeResumableUpload::forgetSession($target->id);
                $retried[] = $target->id;
            }
            if ($retried !== []) {
                if ($locked->scheduled_at === null || $locked->scheduled_at->isPast()) {
                    $locked->forceFill(['scheduled_at' => now()])->save();
                }
                $locked->syncStatus();
                DB::afterCommit(function () use ($post) {
                    DispatchDuePosts::dueTargets($post)->each(fn (int $id) => PublishPostTarget::dispatch($id));
                });
            }
        });

        if ($retried === [] && $blocked > 0) {
            throw new PostValidationException(['media' => [self::MEDIA_MISSING_RETRY]]);
        }

        return ['post' => $post->fresh(['targets', 'media']), 'retried' => count($retried), 'skipped' => $skipped];
    }
}
