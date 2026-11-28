<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Http\Requests\PostRequest;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Studio\DesignExport;
use App\Models\User;
use App\Services\Posts\PostApproval;
use App\Services\Posts\PostMediaProvenance;
use App\Services\Posts\PostPlanner;
use App\Services\Posts\PostScheduling;
use App\Services\Posts\PostSignature;
use App\Services\Posts\PostTransitions;
use App\Support\Ownership;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Capa unica (web, jobs y MCP) para crear, editar, programar y transicionar publicaciones. Exige
 * siempre el usuario explicito: ninguna entrada puede tocar publicaciones de otro usuario.
 *
 * Es la fachada: valida la propiedad, coordina creacion y edicion (plan, aprobacion, guardado) y delega lo demas en
 * `Posts\PostPlanner` (plan y sincronizacion de destinos), `Posts\PostApproval` (aprobacion y retencion de series),
 * `Posts\PostScheduling` (borrador, reprogramar, borrar, reintentar), `Posts\PostTransitions` (estados de los destinos)
 * y `Posts\PostSignature` (huella del contenido).
 *
 * `data` de create/update:
 *  - text: ?string
 *  - scheduled_at: string|CarbonInterface|null. Un string SIN zona se interpreta en la zona horaria de la marca.
 *  - schedule: bool (true = programada, exige fecha futura; false = borrador)
 *  - publish_now: bool (true = programada AHORA y se encola tras el commit: no exige fecha futura, la fecha la pone el servidor)
 *  - media: list<int> ids de MediaAsset (en orden de publicacion)
 *  - targets: list<array{account_id: int, content_type: string, options?: array, text?: ?string}> (`text` null/ausente = vinculado a la plantilla; texto propio = se publica ese)
 *    y por destino `media`: null/ausente = hereda la media comun; lista de {id, alt?, cover_id?} = media propia en ese orden (T-17)
 */
class PostService
{
    public const MEDIA_MISSING = PostScheduling::MEDIA_MISSING;

    public const MEDIA_MISSING_RETRY = PostScheduling::MEDIA_MISSING_RETRY;

    /** @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function normalizeOptions(array $options): array
    {
        return PostPlanner::normalizeOptions($options);
    }

    /** Destinos programados que la publicación inmediata deja en pausa. */
    public static function pausedTargets(Post $post): int
    {
        return PostTransitions::pausedTargets($post);
    }

    public function __construct(
        private readonly PostPlanner $planner,
        private readonly PostApproval $approval,
        private readonly PostScheduling $scheduling,
        private readonly PostTransitions $transitions,
        private readonly PostSignature $signature,
    ) {}

    /**
     * Valida sin guardar (vista previa del compositor). Devuelve los errores por campo, vacio si es valido.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    public function validate(User $user, Brand $brand, array $data): array
    {
        if (! Ownership::matches($brand, $user)) {
            return ['brand' => ['La marca no pertenece al usuario.']];
        }

        try {
            $this->planner->plan($user, $brand, $data);
        } catch (PostValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, Brand $brand, array $data, ?DesignExport $sourceExport = null): Post
    {
        if (! Ownership::matches($brand, $user)) {
            throw new PostValidationException(['brand' => ['La marca no pertenece al usuario.']]);
        }

        if ($sourceExport !== null && (! Ownership::matches($sourceExport, $user) || (int) $sourceExport->brand_id !== $brand->id)) {
            throw new PostValidationException(['export' => ['La exportación no pertenece a la marca.']]);
        }

        // Con aprobacion requerida (T-19) el post nace como borrador pendiente: nunca se programa antes de aprobarse.
        $requiresApproval = (bool) ($data['requires_approval'] ?? false);
        if ($requiresApproval) {
            $data['schedule'] = false;
            $data['publish_now'] = false;
        }
        $plan = $this->planner->plan($user, $brand, $data);

        return DB::transaction(function () use ($user, $brand, $plan, $requiresApproval, $sourceExport) {
            $post = new Post(['text' => $plan['text'], 'scheduled_at' => $plan['scheduled_at']]);
            if ($plan['notes'] !== false) {
                $post->notes = $plan['notes'];
            }
            $post->user_id = $user->id;
            $post->brand_id = $brand->id;
            if ($sourceExport !== null) {
                $post->designExport()->associate($sourceExport);
            }
            if ($plan['campaign_id'] !== false) {
                $post->campaign_id = $plan['campaign_id'];
            }
            $this->saveOrFailCampaign($post);

            $provenance = $sourceExport === null ? null : new PostMediaProvenance($sourceExport, array_map('intval', $sourceExport->media_ids ?? []));
            $this->planner->fill($post, $plan, provenance: $provenance);
            if ($requiresApproval) {
                $this->approval->markPending($post);
            }
            $this->dispatchAfterCommit($post, $plan);

            return $post->fresh(['targets', 'media']);
        });
    }

    /**
     * Actualiza contenido, media y destinos de una publicacion que sigue siendo editable. Los destinos que se
     * conservan (misma cuenta y tipo) se ACTUALIZAN en vez de borrarse y recrearse, asi su historial de intentos
     * sobrevive; solo se borran los destinos pendientes/con error que ya no estan en la lista.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, Post $post, array $data, ?PostMediaProvenance $provenance = null): Post
    {
        $this->assertOwns($user, $post);

        $brand = Brand::withoutUserScope()->findOrFail($post->brand_id);
        if ($provenance !== null && (! Ownership::matches($provenance->export, $user) || (int) $provenance->export->brand_id !== $brand->id)) {
            throw new PostValidationException(['export' => ['La exportación no pertenece a la marca.']]);
        }
        $plan = $this->planner->plan($user, $brand, $data, $post->campaign_id,
            keepScheduledAt: $provenance !== null ? $post->scheduled_at : null,
        );
        $plan = $this->approval->applyRules($post, $plan);
        // Una reexportación solo cambia adjuntos. Un cambio que invalida la aprobación sí vuelve a borrador.
        $keepStatuses = $provenance !== null && ! ($plan['reset_approval'] ?? false);

        return DB::transaction(function () use ($post, $plan, $keepStatuses, $provenance) {
            // Bloquea los destinos ANTES de decidir: el claim de `PublishPostTarget` toma el mismo lock, asi que o
            // gana el claim (aqui se ve `publicando` y se rechaza) o ganamos nosotros (el claim ve el estado nuevo).
            $existing = $this->transitions->lockEditable($post);

            // Contenido ANTES de la edicion: si cambia el texto o la media, el estado residual de un intento anterior
            // (contenedor de Instagram, hijos de un carrusel, sesion de subida...) ya no corresponde a lo que se va a publicar.
            $before = ['text' => $post->text, 'scheduled_at' => $post->scheduled_at, 'media' => $post->media()->pluck('media_assets.id')->map(fn ($id) => (int) $id)->all()];

            $post->forceFill(['text' => $plan['text'], 'scheduled_at' => $plan['scheduled_at']]);
            if ($plan['notes'] !== false) {
                $post->notes = $plan['notes']; // fuera de `$before`/`fill()`: una nota nunca cuenta como «contenido cambiado»
            }
            if ($plan['campaign_id'] !== false) {
                $post->campaign_id = $plan['campaign_id']; // por post entero (D3): no cuenta como «contenido cambiado»
            }
            if ($provenance !== null) {
                $post->designExport()->associate($provenance->export);
            }
            $this->saveOrFailCampaign($post);
            $this->planner->fill($post, $plan, $existing, $before, preserveTargetStatuses: $keepStatuses, provenance: $provenance);
            if ($plan['reset_approval'] ?? false) {
                $this->approval->markPending($post, true);
            }
            if ($post->series_id !== null) {
                $post->forceFill(['series_exception' => true])->save(); // editada a mano: la serie ya no la pisa
            }
            $this->dispatchAfterCommit($post, $plan);

            return $post->fresh(['targets', 'media']);
        });
    }

    /**
     * Notas internas (C-09): se editan en cualquier estado del post (publicado o en vuelo incluidos) porque no se publican.
     * No toca los destinos ni `fill()`, asi que no reinicia ningun intento. No se audita su contenido.
     */
    public function updateNotes(User $user, Post $post, ?string $notes): Post
    {
        $this->assertOwns($user, $post);

        $notes = PostPlanner::normalizeNotes($notes);
        if (mb_strlen((string) $notes) > PostRequest::NOTES_MAX) {
            throw new PostValidationException(['notes' => ['Las notas admiten como maximo '.PostRequest::NOTES_MAX.' caracteres.']]);
        }

        $post->forceFill(['notes' => $notes])->save();

        return $post->fresh(['targets', 'media']);
    }

    /**
     * Campaña (C-03): metadato del post entero, como las notas. Se asigna o se quita en cualquier estado (publicado o en vuelo
     * incluidos) porque no se publica: no toca los destinos ni reinicia ningun intento. Misma marca y usuario; una campaña
     * archivada no se asigna (la que el post ya tiene se puede conservar o quitar).
     */
    public function updateCampaign(User $user, Post $post, ?int $campaignId): Post
    {
        $this->assertOwns($user, $post);

        $brand = Brand::withoutUserScope()->findOrFail($post->brand_id);
        $errors = $this->planner->campaignErrors($user, $brand, $campaignId, $post->campaign_id);
        if ($errors !== []) {
            throw new PostValidationException($errors);
        }

        $post->campaign_id = $campaignId;
        $this->saveOrFailCampaign($post);

        return $post->fresh(['targets', 'media', 'campaign']);
    }

    /**
     * Guarda el post traduciendo la carrera «la campaña se borró entre la validacion y el guardado» (el hook de `Post` lanza
     * `InvalidArgumentException`) a un error de validacion: nunca un 500.
     */
    private function saveOrFailCampaign(Post $post): void
    {
        try {
            $post->save();
        } catch (\InvalidArgumentException $e) {
            throw new PostValidationException(['campaign_id' => ['La campaña ya no existe en esta marca.']]);
        } catch (QueryException $e) {
            // Borrada justo despues del hook y antes del UPDATE: la FK `campaign_id` lo rechaza.
            if ($post->campaign_id !== null && ! Campaign::withoutUserScope()->whereKey($post->campaign_id)->exists()) {
                throw new PostValidationException(['campaign_id' => ['La campaña ya no existe en esta marca.']]);
            }

            throw $e;
        }
    }

    /** Programa un borrador (o una publicacion con error): exige fecha futura y contenido valido. */
    public function schedule(User $user, Post $post, string|CarbonInterface|null $when = null): Post
    {
        $this->assertOwns($user, $post);

        return $this->update($user, $post, $this->planner->dataFromPost($post, ['scheduled_at' => $when ?? $post->scheduled_at, 'schedule' => true]));
    }

    /**
     * «Publicar ahora» (ADR-005, O1): como `schedule()` pero sin fecha futura. La fecha la pone el servidor (`now()`), los
     * destinos quedan `programada` y, TRAS el commit (la cola `database` tiene `after_commit=false`), se encolan los vencidos de
     * ESTE post con la misma `DispatchDuePosts::dueTargets()` que el despachador del minuto. No hay defensa nueva: un segundo
     * dispatch lo frena `ShouldBeUnique` y, si el job ya corre o termino, el `claim()` de `PublishPostTarget` sale sin publicar.
     * Un post en vuelo o publicado se rechaza con el mismo error que `update()`.
     */
    public function publishNow(User $user, Post $post): Post
    {
        $this->assertOwns($user, $post);

        return $this->update($user, $post, $this->planner->dataFromPost($post, ['publish_now' => true]));
    }

    /** Encola los destinos vencidos del post cuando la accion es «publicar ahora», pero solo si la transaccion llega a confirmarse. */
    private function dispatchAfterCommit(Post $post, array $plan): void
    {
        if (! ($plan['publish_now'] ?? false)) {
            return;
        }

        DB::afterCommit(function () use ($post) {
            DispatchDuePosts::dueTargets($post)->each(fn (int $id) => PublishPostTarget::dispatch($id));
        });
    }

    /** Vuelve a borrador (deja de publicarse) una publicacion pendiente. */
    public function unschedule(User $user, Post $post): Post
    {
        $this->assertOwns($user, $post);

        return $this->scheduling->unschedule($post);
    }

    /** Cambia la fecha de una publicacion pendiente (CA-08); una ya publicada no se reprograma. */
    public function reschedule(User $user, Post $post, string|CarbonInterface $when): Post
    {
        $this->assertOwns($user, $post);

        return $this->scheduling->reschedule($post, $when);
    }

    /** Borra una publicacion que aun no se ha publicado ni esta en vuelo. */
    public function delete(User $user, Post $post): void
    {
        $this->assertOwns($user, $post);

        $this->scheduling->delete($post);
    }

    /**
     * «Reintentar» manual (T-19): los destinos `fallida` vuelven a `programada` como un intento NUEVO y se encolan.
     *
     * @return array{post: Post, retried: int, skipped: int}
     */
    public function retryFailed(User $user, Post $post): array
    {
        $this->assertOwns($user, $post);

        return $this->scheduling->retryFailed($post);
    }

    /**
     * Transicion validada y persistida de un target. Relee la fila con lock; lanza si no es valida.
     *
     * @param  array<string, mixed>  $attributes  Campos a fijar a la vez (external_id, error_message...).
     */
    public function transition(PostTarget $target, PostStatus $to, array $attributes = []): PostTarget
    {
        return $this->transitions->transition($target, $to, $attributes);
    }

    /**
     * Ejecuta `$run` sin avisar a los aprobadores post a post y devuelve los ids que han pedido aprobacion, para que el llamador emita UN
     * aviso agrupado. No se anida: dentro de otra tanda solo ejecuta.
     *
     * @return list<int>
     */
    public function collectApprovalRequests(callable $run): array
    {
        return $this->approval->collect($run);
    }

    /** Una ocurrencia de serie ya aprobada pasa a `programada` en su fecha SIN editarla. Devuelve si programo algo. */
    public function scheduleApprovedOccurrence(Post $post): bool
    {
        return $this->approval->scheduleApproved($post);
    }

    /** Activar `requires_approval` en una serie retiene sus ocurrencias futuras aun no publicadas. Devuelve si cambio algo. */
    public function holdForApproval(Post $post): bool
    {
        return $this->approval->hold($post);
    }

    /** Desactivar `requires_approval`: las ocurrencias pendientes (no las rechazadas) dejan de necesitarla y se programan. */
    public function releaseApproval(Post $post): bool
    {
        return $this->approval->release($post);
    }

    /** Huella publica del contenido actual del post (la que el aprobador debe haber visto). */
    public function contentSignature(Post $post): string
    {
        return $this->signature->ofPost($post);
    }

    private function assertOwns(User $user, Post $post): void
    {
        if (! Ownership::matches($post, $user)) {
            throw new PostValidationException(['post' => ['La publicacion no pertenece al usuario.']]);
        }
    }
}
