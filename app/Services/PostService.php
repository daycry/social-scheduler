<?php

namespace App\Services;

use App\Connectors\Data\PublishResult;
use App\Connectors\Google\YouTubePublisher;
use App\Connectors\Meta\Facebook\FacebookPostOptions;
use App\Connectors\Meta\Instagram\InstagramAudio;
use App\Connectors\Meta\Instagram\InstagramPostOptions;
use App\Connectors\Network;
use App\Connectors\TikTok\TikTokPublisher;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Exceptions\InvalidPostTransition;
use App\Exceptions\PostValidationException;
use App\Http\Requests\PostRequest;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\PostWorkflowActivity;
use App\Support\TargetMedia;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Capa unica (web, jobs y MCP) para crear, editar, programar y transicionar publicaciones. Exige
 * siempre el usuario explicito: ninguna entrada puede tocar publicaciones de otro usuario.
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

    public function __construct(private readonly MediaValidator $validator) {}

    /**
     * Valida sin guardar (vista previa del compositor). Devuelve los errores por campo, vacio si es valido.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    public function validate(User $user, Brand $brand, array $data): array
    {
        if ($brand->user_id !== $user->id) {
            return ['brand' => ['La marca no pertenece al usuario.']];
        }

        try {
            $this->plan($user, $brand, $data);
        } catch (PostValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, Brand $brand, array $data): Post
    {
        if ($brand->user_id !== $user->id) {
            throw new PostValidationException(['brand' => ['La marca no pertenece al usuario.']]);
        }

        // Con aprobacion requerida (T-19) el post nace como borrador pendiente: nunca se programa antes de aprobarse.
        $requiresApproval = (bool) ($data['requires_approval'] ?? false);
        if ($requiresApproval) {
            $data['schedule'] = false;
            $data['publish_now'] = false;
        }
        $plan = $this->plan($user, $brand, $data);

        return DB::transaction(function () use ($user, $brand, $plan, $requiresApproval) {
            $post = new Post(['text' => $plan['text'], 'scheduled_at' => $plan['scheduled_at']]);
            if ($plan['notes'] !== false) {
                $post->notes = $plan['notes'];
            }
            $post->user_id = $user->id;
            $post->brand_id = $brand->id;
            if ($plan['campaign_id'] !== false) {
                $post->campaign_id = $plan['campaign_id'];
            }
            $this->saveOrFailCampaign($post);

            $this->fill($post, $plan);
            if ($requiresApproval) {
                $this->markApprovalPending($post);
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
    public function update(User $user, Post $post, array $data): Post
    {
        $this->assertOwns($user, $post);

        $brand = Brand::withoutUserScope()->findOrFail($post->brand_id);
        $plan = $this->plan($user, $brand, $data, $post->campaign_id);
        $plan = $this->applyApprovalRules($post, $plan);

        return DB::transaction(function () use ($post, $plan) {
            // Bloquea los destinos ANTES de decidir: el claim de `PublishPostTarget` toma el mismo lock, asi que o
            // gana el claim (aqui se ve `publicando` y se rechaza) o ganamos nosotros (el claim ve el estado nuevo).
            $existing = $this->lockEditableTargets($post);

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
            $this->saveOrFailCampaign($post);
            $this->fill($post, $plan, $existing, $before);
            if ($plan['reset_approval'] ?? false) {
                $this->markApprovalPending($post, true);
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

        $notes = self::normalizeNotes($notes);
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
        $errors = $this->campaignErrors($user, $brand, $campaignId, $post->campaign_id);
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

    /** @return array<string, list<string>> */
    private function campaignErrors(User $user, Brand $brand, ?int $campaignId, ?int $currentCampaignId): array
    {
        if ($campaignId === null) {
            return [];
        }

        $campaign = Campaign::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->find($campaignId);
        if ($campaign === null) {
            return ['campaign_id' => ['La campaña no existe en esta marca.']];
        }
        if ($campaign->isArchived() && $campaignId !== $currentCampaignId) {
            return ['campaign_id' => ['La campaña está archivada: restáurala antes de asignarle publicaciones.']];
        }

        return [];
    }

    private static function normalizeNotes(mixed $notes): ?string
    {
        $notes = is_string($notes) ? str_replace(["\r\n", "\r"], "\n", $notes) : null;

        return $notes === null || trim($notes) === '' ? null : $notes;
    }

    /** Programa un borrador (o una publicacion con error): exige fecha futura y contenido valido. */
    public function schedule(User $user, Post $post, string|CarbonInterface|null $when = null): Post
    {
        $this->assertOwns($user, $post);

        return $this->update($user, $post, $this->dataFromPost($post, ['scheduled_at' => $when ?? $post->scheduled_at, 'schedule' => true]));
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

        return $this->update($user, $post, $this->dataFromPost($post, ['publish_now' => true]));
    }

    /**
     * Datos de `create/update` que reconstruyen lo que ya hay en el post (texto, media y destinos con sus opciones).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function dataFromPost(Post $post, array $overrides): array
    {
        return $overrides + [
            'text' => $post->text,
            'media' => $post->media()->pluck('media_assets.id')->all(),
            'targets' => $post->targets()->get()->map(fn (PostTarget $t) => [
                'account_id' => $t->social_account_id, 'content_type' => $t->content_type->value, 'options' => $t->options ?? [], 'text' => $t->text, 'first_comment' => $t->first_comment, 'media' => $t->media, 'manual' => $t->manual,
            ])->all(),
        ];
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

        DB::transaction(function () use ($post) {
            foreach ($this->lockEditableTargets($post, allowOrphans: true) as $target) {
                if ($this->isOrphanedProcessing($target)) {
                    // Cancelar un destino atascado `procesando` (cuenta no activa): se cierra como fallido y vuelve a borrador.
                    $this->applyTransition($target, PostStatus::Failed, ['error_code' => 'cuenta_no_disponible', 'error_message' => 'Cancelada por el usuario: la cuenta ya no esta disponible.']);
                }
                if ($target->status !== PostStatus::Draft) {
                    // Al volver a borrador el estado residual del intento anterior se descarta (el contenido puede cambiar).
                    $this->applyTransition($target, PostStatus::Draft, $this->freshAttempt($target, $this->effectiveTextOf($target, $post->text), $post->scheduled_at));
                    YouTubePublisher::forgetUploadSession($target->id);
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
    public function reschedule(User $user, Post $post, string|CarbonInterface $when): Post
    {
        $this->assertOwns($user, $post);

        $brand = Brand::withoutUserScope()->findOrFail($post->brand_id);
        $at = $this->parseWhen($when, $brand);

        if ($at->lte(now())) {
            throw new PostValidationException(['scheduled_at' => ['La nueva fecha debe ser futura.']]);
        }

        DB::transaction(function () use ($post, $at) {
            $targets = $this->lockEditableTargets($post);
            $oldScheduledAt = $post->scheduled_at;

            // Un destino de una red apagada en esta instalacion (p. ej. BLUESKY_ENABLED=false) no se reprograma: volveria a fallar.
            $off = $targets->first(fn (PostTarget $t) => ! $t->content_type->network()->isAvailable());
            if ($off !== null) {
                throw new PostValidationException(['post' => [$off->content_type->network()->label().' no esta disponible en esta instalacion: quita ese destino antes de reprogramar.']]);
            }

            // Mover una ocurrencia a mano la saca de la serie: `reconcile()` no la devuelve a su fecha.
            $post->forceFill(['scheduled_at' => $at] + ($post->series_id !== null ? ['series_exception' => true] : []))->save();
            PostTarget::withoutUserScope()->where('post_id', $post->id)->where('manual', true)->update(['manual_notified_at' => null]);
            $targets->filter(fn (PostTarget $t) => $t->status === PostStatus::Failed)
                ->each(function (PostTarget $t) use ($post, $oldScheduledAt) {
                    $this->transition($t, PostStatus::Scheduled, $this->freshAttempt($t, $this->effectiveTextOf($t, $post->text), $oldScheduledAt));
                    YouTubePublisher::forgetUploadSession($t->id); // la sesion de subida del intento anterior no se reutiliza
                });
            $post->syncStatus();
        });

        return $post->fresh();
    }

    /** Borra una publicacion que aun no se ha publicado ni esta en vuelo. */
    public function delete(User $user, Post $post): void
    {
        $this->assertOwns($user, $post);

        DB::transaction(function () use ($post) {
            $targets = $this->lockEditableTargets($post, 'No se puede borrar una publicacion que ya se ha publicado o se esta publicando.', allowOrphans: true);

            // Nada pendiente sobrevive en la papelera: un `procesando` huerfano se cierra como fallido y lo programado vuelve a
            // borrador (restaurar nunca reactiva una publicacion; el usuario la reprograma).
            foreach ($targets as $target) {
                if ($this->isOrphanedProcessing($target)) {
                    $this->applyTransition($target, PostStatus::Failed, ['error_code' => 'cuenta_no_disponible', 'error_message' => 'Enviada a la papelera: la cuenta ya no estaba disponible.']);
                } elseif ($target->status === PostStatus::Scheduled) {
                    $this->applyTransition($target, PostStatus::Draft, $this->freshAttempt($target, $this->effectiveTextOf($target, $post->text), $post->scheduled_at));
                    YouTubePublisher::forgetUploadSession($target->id);
                }
            }
            $post->syncStatus();
            $post->delete();
        });
    }

    /**
     * Transicion validada y persistida de un target. Relee la fila con lock; lanza si no es valida.
     *
     * @param  array<string, mixed>  $attributes  Campos a fijar a la vez (external_id, error_message...).
     */
    public function transition(PostTarget $target, PostStatus $to, array $attributes = []): PostTarget
    {
        return DB::transaction(function () use ($target, $to, $attributes) {
            $fresh = PostTarget::withoutUserScope()->lockForUpdate()->findOrFail($target->id);
            $this->applyTransition($fresh, $to, $attributes);
            Post::withoutUserScope()->withTrashed()->findOrFail($fresh->post_id)->syncStatus();

            return $fresh;
        });
    }

    /**
     * «Reintentar» manual (T-19): los destinos `fallida` vuelven a `programada` como un intento NUEVO y se encolan. Idempotente: lo que ya
     * esta en vuelo, programado o publicado no se toca, asi que repetir la accion (o pulsarla con el job en curso) nunca publica dos veces;
     * el `claim()` de `PublishPostTarget` y su `ShouldBeUnique` son la segunda defensa. Exige que el post pueda publicarse (aprobado).
     *
     * @return array{post: Post, retried: int, skipped: int}
     */
    public function retryFailed(User $user, Post $post): array
    {
        $this->assertOwns($user, $post);
        if (! $post->isPublishable()) {
            throw new PostValidationException(['approval' => ['La publicacion requiere aprobacion antes de publicarse.']]);
        }

        $retried = [];
        $skipped = 0;
        DB::transaction(function () use ($post, &$retried, &$skipped) {
            $targets = PostTarget::withoutUserScope()->where('post_id', $post->id)->orderBy('id')->lockForUpdate()->get();
            $locked = Post::withoutUserScope()->lockForUpdate()->findOrFail($post->id);
            foreach ($targets as $target) {
                if ($target->status !== PostStatus::Failed || ! $target->content_type->network()->isAvailable()) {
                    $skipped++;

                    continue;
                }
                $this->applyTransition($target, PostStatus::Scheduled, $this->freshAttempt($target, $this->effectiveTextOf($target, $locked->text), $locked->scheduled_at) + ['manual_notified_at' => null]);
                YouTubePublisher::forgetUploadSession($target->id);
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

        return ['post' => $post->fresh(['targets', 'media']), 'retried' => count($retried), 'skipped' => $skipped];
    }

    // ------------------------------------------------------------------ aprobacion (T-19)

    /**
     * Reglas de aprobacion sobre el plan: un post sin aprobar no se programa; editar el contenido de uno aprobado lo devuelve a pendiente.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function applyApprovalRules(Post $post, array $plan): array
    {
        if ($post->approval_status === null) {
            return $plan;
        }

        // Cambiar el contenido invalida la version que se pidio aprobar (CWE-367), este aprobada o aun pendiente.
        if (in_array($post->approval_status, [Post::APPROVAL_APPROVED, Post::APPROVAL_PENDING], true) && $this->postSignature($post) !== $this->planSignature($plan)) {
            $plan['status'] = PostStatus::Draft;
            $plan['publish_now'] = false;
            $plan['reset_approval'] = true;

            return $plan;
        }

        if ($plan['status'] === PostStatus::Scheduled && ! $post->isPublishable()) {
            throw new PostValidationException(['approval' => ['La publicacion requiere aprobacion antes de programarse.']]);
        }

        return $plan;
    }

    private function markApprovalPending(Post $post, bool $edited = false): void
    {
        $post->forceFill([
            'approval_status' => Post::APPROVAL_PENDING, 'approval_comment' => null, 'approval_requested_at' => now(),
            'approval_decided_at' => null, 'approval_decided_by' => null, 'approval_signature' => $this->postSignature($post),
        ])->save();

        $brandName = (string) Brand::withoutUserScope()->whereKey($post->brand_id)->value('name');
        $message = $edited ? 'Una publicacion aprobada de «'.$brandName.'» se ha editado y vuelve a necesitar tu aprobacion.' : 'Hay una publicacion de «'.$brandName.'» que pide tu aprobacion.';
        foreach (app(BrandCollaboration::class)->approvers((int) $post->brand_id) as $approver) {
            $approver->notify(new PostWorkflowActivity('approval_requested', $post->id, $post->brand_id, $brandName, $message, (string) $post->text));
        }
    }

    /** Huella publica del contenido actual del post (la que el aprobador debe haber visto). */
    public function contentSignature(Post $post): string
    {
        return $this->postSignature($post);
    }

    /** Contenido que fija una aprobacion: texto, media comun y destinos (no la fecha). */
    private function postSignature(Post $post): string
    {
        return self::signature(
            $post->text,
            $post->media()->pluck('media_assets.id')->map(fn ($id) => (int) $id)->all(),
            $post->targets()->get()->map(fn (PostTarget $t) => [
                'a' => (int) $t->social_account_id, 't' => $t->content_type->value, 'o' => $t->options ?? [], 'x' => $t->text, 'm' => TargetMedia::normalize($t->media),
                'f' => $t->first_comment, 'u' => (bool) $t->manual,
            ])->all(),
        );
    }

    /** @param array<string, mixed> $plan */
    private function planSignature(array $plan): string
    {
        return self::signature(
            $plan['text'],
            array_map(fn (MediaAsset $m) => (int) $m->id, $plan['media']),
            array_map(fn (array $t) => [
                'a' => (int) $t['account']->id, 't' => $t['type']->value, 'o' => $t['options'] ?? [], 'x' => $t['text'], 'm' => $t['media'],
                'f' => $t['first_comment'] ?? null, 'u' => (bool) ($t['manual'] ?? false),
            ], $plan['targets']),
        );
    }

    /**
     * @param  list<int>  $media
     * @param  list<array<string, mixed>>  $targets
     */
    private static function signature(?string $text, array $media, array $targets): string
    {
        $norm = function (mixed $v) use (&$norm) {
            if (is_array($v)) {
                $v = array_map($norm, $v);
                if (! array_is_list($v)) {
                    ksort($v);
                }
            }

            return $v;
        };
        $targets = array_map($norm, $targets);
        usort($targets, fn ($a, $b) => [$a['a'], $a['t']] <=> [$b['a'], $b['t']]);

        return hash('sha256', json_encode([(string) $text, $media, $targets], JSON_UNESCAPED_UNICODE));
    }

    private function sameInstant(?CarbonInterface $a, ?CarbonInterface $b): bool
    {
        return ($a === null && $b === null) || ($a !== null && $b !== null && $a->getTimestamp() === $b->getTimestamp());
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string, mixed> $attributes */
    private function applyTransition(PostTarget $target, PostStatus $to, array $attributes = []): void
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

    private function assertOwns(User $user, Post $post): void
    {
        if ($post->user_id !== $user->id) {
            throw new PostValidationException(['post' => ['La publicacion no pertenece al usuario.']]);
        }
    }

    /**
     * Relee y BLOQUEA (`lockForUpdate`) los destinos del post dentro de la transaccion en curso y exige que ninguno
     * este en vuelo ni publicado. Devuelve los destinos bloqueados, indexados por `cuenta|tipo`.
     *
     * @return Collection<string, PostTarget>
     */
    private function lockEditableTargets(Post $post, string $message = 'La publicacion ya se ha publicado o se esta publicando y no se puede modificar.', bool $allowOrphans = false): Collection
    {
        $targets = PostTarget::withoutUserScope()->where('post_id', $post->id)->orderBy('id')->lockForUpdate()->get();

        // Borrar/cancelar sí se permite sobre un `procesando` atascado cuya cuenta no esta activa (nadie lo va a sondear).
        if ($targets->contains(fn (PostTarget $t) => ($t->status->isInFlight() || $t->status->isFinal()) && ! ($allowOrphans && $this->isOrphanedProcessing($t)))) {
            throw new PostValidationException(['post' => [$message]]);
        }

        return $targets->keyBy(fn (PostTarget $t) => $t->social_account_id.'|'.$t->content_type->value);
    }

    /** `procesando` cuya cuenta esta borrada, desconectada o pendiente de reconectar: el sondeo no lo va a retomar. */
    private function isOrphanedProcessing(PostTarget $target): bool
    {
        if ($target->status !== PostStatus::Processing) {
            return false;
        }

        $account = $target->social_account_id ? SocialAccount::withoutUserScope()->find($target->social_account_id) : null;

        return $account === null || ! $account->isActive();
    }

    /** Opciones propias de YouTube (C-04): solo se aceptan en destinos de YouTube. */
    private const YOUTUBE_OPTIONS = ['category_id', 'tags', 'made_for_kids', 'notify_subscribers', 'contains_synthetic_media'];

    /** Opciones de TikTok (C-04) que actuan solo en publicacion directa (la cuenta con `meta.direct_post`). */
    private const TIKTOK_DIRECT_OPTIONS = ['disable_duet', 'disable_stitch', 'brand_organic_toggle', 'brand_content_toggle', 'is_aigc', 'auto_add_music'];

    /** Opciones booleanas: la regla `boolean` de la web y el MCP admite `1`, `0`, `"1"` y `"0"` sin convertirlas. */
    private const BOOLEAN_OPTIONS = [
        'share_to_feed', 'shorts_hashtag', 'made_for_kids', 'notify_subscribers', 'contains_synthetic_media',
        'disable_comment', 'disable_duet', 'disable_stitch', 'brand_organic_toggle', 'brand_content_toggle', 'is_aigc', 'auto_add_music',
    ];

    /**
     * Convierte a `true`/`false` reales las opciones booleanas ANTES de validar y de guardar: asi `optionErrors` no confunde `0` o
     * `"0"` con «pedido» y los publishers (que comparan con `===`) nunca ignoran un valor equivalente (`made_for_kids: 1` es true).
     * Un valor que no es un booleano reconocible se deja tal cual (la validacion de forma ya lo rechaza antes).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function normalizeOptions(array $options): array
    {
        $options = InstagramPostOptions::normalize($options);
        foreach (self::BOOLEAN_OPTIONS as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                $options[$key] = filter_var($options[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $options[$key];
            }
        }

        return $options;
    }

    /**
     * Reglas por cuenta de las opciones de C-04, las mismas para la web y el MCP: una opcion que la red o la cuenta no admite
     * es un error del destino (no se descarta en silencio). Un valor «vacio» (null, false o lista vacia) no cuenta como pedido.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function optionErrors(ContentType $type, SocialAccount $account, array $options): array
    {
        $asked = fn (string $k) => array_key_exists($k, $options) && ! in_array($options[$k], [null, false, [], ''], true);
        $errors = array_merge(InstagramAudio::selectionErrors($options['instagram_audio'] ?? null, $type, $account), InstagramPostOptions::errors($options, $type), FacebookPostOptions::errors($options, $type));

        if ($type->network() !== Network::YouTube) {
            foreach (self::YOUTUBE_OPTIONS as $k) {
                if ($asked($k)) {
                    $errors[] = "La opcion $k solo existe en YouTube.";
                }
            }
        }

        if ($type->network() !== Network::TikTok) {
            foreach (self::TIKTOK_DIRECT_OPTIONS as $k) {
                if ($asked($k)) {
                    $errors[] = "La opcion $k solo existe en TikTok.";
                }
            }

            return $errors;
        }

        $direct = TikTokPublisher::directPost($account);
        if ($asked('auto_add_music') && $type !== ContentType::TikTokPhoto) {
            $errors[] = 'La música recomendada de TikTok solo existe en fotos y carruseles, no en vídeos.';
        }
        foreach (self::TIKTOK_DIRECT_OPTIONS as $k) {
            if ($asked($k) && ! $direct) {
                $errors[] = "La opcion $k solo actua con la publicacion directa de TikTok: esta cuenta envia borradores a la bandeja.";
            }
        }

        if ($type === ContentType::TikTokPhoto) {
            foreach (['disable_duet', 'disable_stitch'] as $k) {
                if ($asked($k)) {
                    $errors[] = "La opcion $k solo existe en los videos de TikTok.";
                }
            }
        }

        // Exigencia de TikTok para el contenido de marca (colaboracion de pago): no puede ser privado.
        if ($asked('brand_content_toggle') && $direct && in_array($options['privacy_level'] ?? 'SELF_ONLY', ['SELF_ONLY', null, ''], true)) {
            $errors[] = 'El contenido de marca (colaboracion de pago) no puede ser privado: elige otra visibilidad en TikTok.';
        }

        return $errors;
    }

    private function targetLabel(SocialAccount $account): string
    {
        return "Media propia de {$account->name} ({$account->network->label()})";
    }

    /**
     * Media efectiva de un destino (T-17) y los errores de sus metadatos. `$own` null = hereda `$common`; con lista, se resuelven sus
     * ids entre la media del usuario (en ese orden) y se validan alt y portada contra lo que admite el tipo de contenido.
     *
     * @param  ?list<array{id: int, alt: ?string, cover_id: ?int}>  $own
     * @param  list<MediaAsset>  $common
     * @return array{0: list<MediaAsset>, 1: list<string>}
     */
    private function effectiveTargetMedia(User $user, SocialAccount $account, ContentType $type, ?array $own, array $common): array
    {
        if ($own === null) {
            return [$common, []];
        }

        $label = $this->targetLabel($account);
        $errors = [];
        $ids = array_values(array_unique(array_filter(array_merge(array_column($own, 'id'), array_column($own, 'cover_id')))));
        $found = MediaAsset::withoutUserScope()->where('user_id', $user->id)->whereIn('id', $ids)->get()->keyBy('id');

        $assets = [];
        $seen = [];
        foreach ($own as $i => $item) {
            $n = $i + 1;
            $asset = $found->get($item['id']);
            if ($asset === null) {
                $errors[] = "$label: el archivo {$item['id']} no existe.";

                continue;
            }
            if (isset($seen[$asset->id])) {
                $errors[] = "$label: el archivo {$asset->id} esta repetido.";

                continue;
            }
            $seen[$asset->id] = true;
            $assets[] = $asset;

            if ($item['alt'] !== null) {
                if (! TargetMedia::supportsAlt($type)) {
                    $errors[] = "$label: {$account->network->label()} no admite texto alternativo en {$type->label()}.";
                } elseif ($asset->isVideo()) {
                    $errors[] = "$label: el texto alternativo solo se aplica a imagenes (archivo $n).";
                } elseif (mb_strlen($item['alt']) > TargetMedia::ALT_MAX) {
                    $errors[] = "$label: el texto alternativo del archivo $n admite como maximo ".TargetMedia::ALT_MAX.' caracteres (tiene '.mb_strlen($item['alt']).').';
                }
            }

            if ($item['cover_id'] !== null) {
                $cover = $found->get($item['cover_id']);
                if (! TargetMedia::supportsCover($type)) {
                    $errors[] = "$label: {$account->network->label()} no admite portada en {$type->label()}.";
                } elseif (! $asset->isVideo()) {
                    $errors[] = "$label: la portada solo se aplica a videos (archivo $n).";
                } elseif ($cover === null) {
                    $errors[] = "$label: la portada {$item['cover_id']} no existe.";
                } elseif ($cover->kind !== MediaAsset::KIND_IMAGE || ! $cover->isReady()) {
                    $errors[] = "$label: la portada del archivo $n debe ser una imagen lista.";
                } elseif ($account->network === Network::YouTube && (int) ($cover->size ?? $cover->declared_size) > TargetMedia::YOUTUBE_COVER_MAX_BYTES) {
                    $errors[] = "$label: la portada del archivo $n pesa mas de 2 MB, el maximo de las miniaturas de YouTube.";
                }
            }
        }

        return [$assets, $errors];
    }

    /**
     * Valida todo y devuelve el plan normalizado (sin tocar la BD).
     *
     * @param  array<string, mixed>  $data
     * @return array{text: ?string, scheduled_at: ?CarbonImmutable, status: PostStatus, publish_now: bool, media: list<MediaAsset>, targets: list<array{account: SocialAccount, type: ContentType, options: array, text: ?string, first_comment: ?string}>}
     */
    private function plan(User $user, Brand $brand, array $data, ?int $currentCampaignId = null): array
    {
        $errors = [];
        $publishNow = (bool) ($data['publish_now'] ?? false);
        $schedule = (bool) ($data['schedule'] ?? false) || $publishNow;
        $text = isset($data['text']) ? (string) $data['text'] : null;

        $at = null;
        if ($publishNow) {
            // La fecha de «publicar ahora» no sale de la entrada: la pone el servidor, y no se exige que sea futura.
            $at = CarbonImmutable::instance(now())->utc();
        } elseif (($data['scheduled_at'] ?? null) !== null && $data['scheduled_at'] !== '') {
            try {
                $at = $this->parseWhen($data['scheduled_at'], $brand);
            } catch (Throwable) {
                $errors['scheduled_at'][] = 'La fecha no es valida.';
            }
        }

        if ($schedule && $at === null && ! isset($errors['scheduled_at'])) {
            $errors['scheduled_at'][] = 'Indica la fecha y hora de publicacion.';
        } elseif ($schedule && ! $publishNow && $at !== null && $at->lte(now())) {
            $errors['scheduled_at'][] = 'La fecha de publicacion debe ser futura.';
        }

        // Media del usuario, lista, en el orden indicado.
        $ids = array_values(array_map('intval', (array) ($data['media'] ?? [])));
        $found = MediaAsset::withoutUserScope()->where('user_id', $user->id)->whereIn('id', $ids)->get()->keyBy('id');
        $media = [];
        foreach ($ids as $id) {
            if (! $found->has($id)) {
                $errors['media'][] = "El archivo $id no existe.";

                continue;
            }
            $media[] = $found[$id];
        }

        $rawTargets = array_values((array) ($data['targets'] ?? []));
        if ($schedule && $rawTargets === []) {
            $errors['targets'][] = 'Elige al menos una cuenta de destino.';
        }

        $targets = [];
        $seen = [];
        foreach ($rawTargets as $i => $raw) {
            $key = "targets.$i";
            $account = SocialAccount::withoutUserScope()
                ->where('user_id', $user->id)->where('brand_id', $brand->id)
                ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)
                ->find((int) ($raw['account_id'] ?? 0));
            $type = ContentType::tryFrom((string) ($raw['content_type'] ?? ''));

            if (! $account) {
                $errors[$key][] = 'La cuenta no existe en esta marca o esta desconectada.';

                continue;
            }
            if (! $type || $type->network() !== $account->network) {
                $errors[$key][] = "El tipo de contenido no corresponde a {$account->network->label()}.";

                continue;
            }
            if (! $type->isOffered()) {
                $errors[$key][] = ! $account->network->isAvailable()
                    ? "{$account->network->label()} no esta disponible en esta instalacion."
                    : "{$type->label()} de {$account->network->label()} no esta disponible: necesita un dominio verificado en TikTok.";

                continue;
            }
            if ($schedule && ! $account->isActive()) {
                $errors[$key][] = "La cuenta {$account->name} necesita reconectarse antes de programar.";
            }
            if (isset($seen[$account->id.'|'.$type->value])) {
                $errors[$key][] = 'Esa cuenta y tipo ya estan en la publicacion.';

                continue;
            }
            $seen[$account->id.'|'.$type->value] = true;

            // Texto propio del destino (null = vinculado a la plantilla): se valida el EFECTIVO, que es el que se publica.
            // Un texto propio vacio o solo con espacios es `null` (vinculado): publicaria vacio y `findPublished` no podria reconocerlo.
            // Igual que la web (`TrimStrings` + `ConvertEmptyStringsToNull`), y vale tambien para el MCP.
            $ownText = isset($raw['text']) && trim((string) $raw['text']) !== '' ? (string) $raw['text'] : null;
            // Media del destino (T-17): la propia o, si hereda, la comun. Es la que se valida y la que se publicara.
            $ownMedia = TargetMedia::normalize($raw['media'] ?? null);
            [$effectiveMedia, $mediaErrors] = $this->effectiveTargetMedia($user, $account, $type, $ownMedia, $media);
            foreach ($mediaErrors as $message) {
                $errors[$key][] = $message;
            }
            foreach ($this->validator->validate($type, $effectiveMedia, $ownText ?? $text) as $message) {
                $errors[$key][] = $ownMedia !== null ? $this->targetLabel($account).': '.$message : $message;
            }

            $options = self::normalizeOptions((array) ($raw['options'] ?? []));
            foreach ($this->optionErrors($type, $account, $options) as $message) {
                $errors[$key][] = $message;
            }
            // Directrices de TikTok: la visibilidad la elige el usuario en cada publicacion, sin valor por defecto. Un borrador puede
            // guardarse sin ella; programar o publicar no.
            if ($schedule && $type->network() === Network::TikTok && TikTokPublisher::directPost($account) && blank($options['privacy_level'] ?? null)) {
                $errors[$key][] = 'Elige la visibilidad de TikTok antes de programar o publicar.';
            }

            // Primer comentario (C-01b): vacio = sin comentario. El maximo y los tipos que lo admiten salen de `media_specs`.
            $firstComment = isset($raw['first_comment']) && trim((string) $raw['first_comment']) !== '' ? (string) $raw['first_comment'] : null;
            if ($firstComment !== null) {
                $max = config("media_specs.types.{$type->value}.first_comment.max");
                if ($max === null) {
                    $errors[$key][] = "{$account->network->label()} no admite primer comentario en este tipo de contenido.";
                } elseif (mb_strlen($firstComment) > (int) $max) {
                    $errors[$key][] = "El primer comentario admite como maximo $max caracteres en {$account->network->label()} (tiene ".mb_strlen($firstComment).').';
                }
            }

            $targets[] = ['account' => $account, 'type' => $type, 'options' => $options, 'text' => $ownText, 'first_comment' => $firstComment, 'media' => $ownMedia, 'manual' => filter_var($raw['manual'] ?? false, FILTER_VALIDATE_BOOLEAN)];
        }

        // Campaña (C-03, CA-12): null = quitarla; solo de esta marca y de este usuario. Omitida = no se toca (`false`).
        $campaignId = false;
        if (array_key_exists('campaign_id', $data)) {
            $campaignId = ($data['campaign_id'] === null || $data['campaign_id'] === '') ? null : (int) $data['campaign_id'];
            $errors += $this->campaignErrors($user, $brand, $campaignId, $currentCampaignId);
        }

        if ($errors !== []) {
            throw new PostValidationException($errors);
        }

        return [
            'campaign_id' => $campaignId,
            'text' => $text,
            // Notas internas: null = la entrada no las trae (no se tocan); '' o solo espacios = borrarlas. Nunca viajan a los publishers.
            'notes' => array_key_exists('notes', $data) ? self::normalizeNotes($data['notes']) : false,
            'scheduled_at' => $at,
            'status' => $schedule ? PostStatus::Scheduled : PostStatus::Draft,
            'publish_now' => $publishNow,
            'media' => $media,
            'targets' => $targets,
        ];
    }

    /**
     * Sincroniza media y destinos con el plan. Los destinos existentes (ya bloqueados y editables) se actualizan;
     * los que sobran se borran; los nuevos se crean.
     *
     * @param  array{status: PostStatus, media: list<MediaAsset>, targets: list<array{account: SocialAccount, type: ContentType, options: array, text: ?string, first_comment: ?string, media: ?list<array{id: int, alt: ?string, cover_id: ?int}>}>}  $plan
     * @param  Collection<string, PostTarget>|null  $existing
     * @param  array{text: ?string, media: list<int>}|null  $before  contenido previo del post (solo en edicion)
     */
    private function fill(Post $post, array $plan, ?Collection $existing = null, ?array $before = null): void
    {
        $existing ??= new Collection;
        // La media comun es la base de los destinos que heredan; texto y media EFECTIVOS se comparan por destino (ADR-005, T-17).
        $commonIds = array_map(fn (MediaAsset $m) => (int) $m->id, $plan['media']);
        $post->media()->sync(collect($plan['media'])->mapWithKeys(fn (MediaAsset $m, int $i) => [$m->id => ['position' => $i]])->all());

        $kept = [];

        foreach ($plan['targets'] as $t) {
            $current = $existing->get($t['account']->id.'|'.$t['type']->value);

            if ($current === null) {
                $target = new PostTarget([
                    'social_account_id' => $t['account']->id,
                    'network' => $t['account']->network,
                    'content_type' => $t['type'],
                    'status' => $plan['status'],
                    'options' => $t['options'] ?: null,
                    'text' => $t['text'],
                    'media' => $t['media'],
                    'manual' => $t['manual'] ?? false,
                    'first_comment' => $t['first_comment'] ?? null,
                ]);
                $target->user_id = $post->user_id;
                $target->post_id = $post->id;
                $target->save();

                continue;
            }

            $kept[$current->id] = true;
            // Un destino con error vuelve a empezar de cero; uno pendiente conserva sus intentos (idempotencia) mientras
            // el contenido sea el mismo: si cambia el texto, la media o las opciones del destino, el estado residual
            // (referencia externa, progreso, intentos) pertenece al contenido viejo y se descarta. Solo la fecha: se conserva.
            // El texto efectivo VIEJO se calcula aqui, antes de escribir `text` en el destino: es el que `freshAttempt` guarda en
            // `reconcile.text` para que el reintento reconozca lo ya publicado con el texto anterior.
            $oldText = $this->effectiveTextOf($current, $before['text'] ?? null);
            $newText = $t['text'] ?? ($plan['text'] ?? null);
            $textChanged = $before !== null && $oldText !== $newText;
            $optionsChanged = $before !== null && ($current->options ?? []) != ($t['options'] ?? []);
            $mediaChanged = $before !== null && TargetMedia::effective($current, $before['media']) != TargetMedia::effective($t['media'], $commonIds);
            $fresh = $current->status === PostStatus::Failed || $mediaChanged || $textChanged || $optionsChanged;
            // El primer comentario NO cuenta para `$fresh`: se publica despues del destino y no cambia lo que sale en la red.
            $manual = (bool) ($t['manual'] ?? false);
            $rescheduled = $before !== null && ! $this->sameInstant($before['scheduled_at'] ?? null, $plan['scheduled_at'] ?? null);
            // El aviso manual se rearma si cambia la hora, el modo manual o el contenido: el usuario volvera a recibirlo.
            $rearm = ($manual !== (bool) $current->manual || $rescheduled || $fresh) ? ['manual_notified_at' => null] : [];
            $attributes = ['options' => $t['options'] ?: null, 'text' => $t['text'], 'media' => $t['media'], 'manual' => $manual, 'first_comment' => $t['first_comment'] ?? null] + $rearm + ($fresh ? $this->freshAttempt($current, $oldText, $before['scheduled_at'] ?? null) : []);
            if (! $fresh) {
                $attributes += $this->releaseBackoff($current, (bool) ($plan['publish_now'] ?? false));
            }
            if ($fresh) {
                $attributes = $this->keepRatePause($current, $attributes);
                YouTubePublisher::forgetUploadSession($current->id);
            }

            if ($current->status === $plan['status']) {
                $current->forceFill($attributes)->save();
            } else {
                $this->applyTransition($current, $plan['status'], $attributes);
            }
        }

        foreach ($existing as $current) {
            if (! isset($kept[$current->id]) && $current->status->canBeDeleted()) {
                $current->delete();
            }
        }

        $post->syncStatus();
    }

    /**
     * Atributos de un destino que empieza un intento NUEVO. Si el intento anterior pudo haber salido (referencia/contenedor,
     * progreso o intentos con un error ambiguo), la prueba de idempotencia NO se pierde: se guarda en `progress.reconcile`
     * (referencia, progreso, texto y ventana del contenido viejo) y el siguiente claim la consulta en la red ANTES de publicar
     * (revision F3 i3 R1).
     *
     * @return array<string, mixed>
     */
    private function freshAttempt(PostTarget $target, ?string $oldText, ?CarbonInterface $oldScheduledAt): array
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
                'since' => app(PublishRequestFactory::class)->searchWindowStart($target, $oldScheduledAt)?->toIso8601String(),
            ], fn ($v) => $v !== null) + ['pending' => true];
        }

        $attributes['progress'] = $carried !== null ? ['reconcile' => $carried] : null;

        return $attributes;
    }

    /** Texto efectivo de un destino con el texto de plantilla indicado (el propio si lo tiene; `null` = vinculado). */
    private function effectiveTextOf(PostTarget $target, ?string $templateText): ?string
    {
        return $target->text ?? $templateText;
    }

    /** «Publicar ahora» salta la espera de un reintento transitorio, pero NO la pausa por cuota de la red. */
    private function releaseBackoff(PostTarget $target, bool $publishNow): array
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
    private function keepRatePause(PostTarget $current, array $attributes): array
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

    /** Un string sin zona se interpreta en la zona de la marca; el resultado se guarda en UTC. */
    private function parseWhen(string|CarbonInterface $when, Brand $brand): CarbonImmutable
    {
        if ($when instanceof CarbonInterface) {
            return CarbonImmutable::instance($when)->utc();
        }

        return CarbonImmutable::parse($when, $brand->timezone ?: 'UTC')->utc();
    }
}
