<?php

namespace App\Services;

use App\Connectors\Data\PublishResult;
use App\Connectors\Google\YouTubePublisher;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Exceptions\InvalidPostTransition;
use App\Exceptions\PostValidationException;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
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
 *  - targets: list<array{account_id: int, content_type: string, options?: array}>
 */
class PostService
{
    /** Estado de un destino que empieza un intento NUEVO (tras un error o una edicion): nada del intento anterior sobrevive. */
    private const FRESH_ATTEMPT = [
        'external_id' => null, 'external_url' => null, 'external_reference' => null, 'attempts' => 0,
        'progress' => null, 'error_code' => null, 'error_message' => null, 'status_note' => null, 'started_at' => null, 'next_attempt_at' => null,
    ];

    /** Errores que NO dejan duda de que la red no recibio nada (rechazo, medio invalido...): el resto puede haber salido. */
    private const AMBIGUOUS_ERRORS = [null, 'transitorio', 'reintentos_agotados'];

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

        $plan = $this->plan($user, $brand, $data);

        return DB::transaction(function () use ($user, $brand, $plan) {
            $post = new Post(['text' => $plan['text'], 'scheduled_at' => $plan['scheduled_at']]);
            $post->user_id = $user->id;
            $post->brand_id = $brand->id;
            $post->save();

            $this->fill($post, $plan);
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
        $plan = $this->plan($user, $brand, $data);

        return DB::transaction(function () use ($post, $plan) {
            // Bloquea los destinos ANTES de decidir: el claim de `PublishPostTarget` toma el mismo lock, asi que o
            // gana el claim (aqui se ve `publicando` y se rechaza) o ganamos nosotros (el claim ve el estado nuevo).
            $existing = $this->lockEditableTargets($post);

            // Contenido ANTES de la edicion: si cambia el texto o la media, el estado residual de un intento anterior
            // (contenedor de Instagram, hijos de un carrusel, sesion de subida...) ya no corresponde a lo que se va a publicar.
            $before = ['text' => $post->text, 'scheduled_at' => $post->scheduled_at, 'media' => $post->media()->pluck('media_assets.id')->map(fn ($id) => (int) $id)->all()];

            $post->forceFill(['text' => $plan['text'], 'scheduled_at' => $plan['scheduled_at']])->save();
            $this->fill($post, $plan, $existing, $before);
            $this->dispatchAfterCommit($post, $plan);

            return $post->fresh(['targets', 'media']);
        });
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
                'account_id' => $t->social_account_id, 'content_type' => $t->content_type->value, 'options' => $t->options ?? [],
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
                    $this->applyTransition($target, PostStatus::Draft, $this->freshAttempt($target, $post->text, $post->scheduled_at));
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

            $post->forceFill(['scheduled_at' => $at])->save();
            $targets->filter(fn (PostTarget $t) => $t->status === PostStatus::Failed)
                ->each(function (PostTarget $t) use ($post, $oldScheduledAt) {
                    $this->transition($t, PostStatus::Scheduled, $this->freshAttempt($t, $post->text, $oldScheduledAt));
                    YouTubePublisher::forgetUploadSession($t->id); // la sesion de subida del intento anterior no se reutiliza
                });
            // Los `programada` con intentos previos conservan la ventana de busqueda anterior (no duplicar).
            $targets->filter(fn (PostTarget $t) => $t->status === PostStatus::Scheduled)
                ->each(fn (PostTarget $t) => ($w = $this->keepWindow($t, $oldScheduledAt, $at)) !== [] ? $t->forceFill($w)->save() : null);
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
                    $this->applyTransition($target, PostStatus::Draft, $this->freshAttempt($target, $post->text, $post->scheduled_at));
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

    /**
     * Valida todo y devuelve el plan normalizado (sin tocar la BD).
     *
     * @param  array<string, mixed>  $data
     * @return array{text: ?string, scheduled_at: ?CarbonImmutable, status: PostStatus, publish_now: bool, media: list<MediaAsset>, targets: list<array{account: SocialAccount, type: ContentType, options: array}>}
     */
    private function plan(User $user, Brand $brand, array $data): array
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
                $errors[$key][] = "{$type->label()} de {$account->network->label()} no esta disponible: necesita un dominio verificado en TikTok.";

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

            foreach ($this->validator->validate($type, $media, $text) as $message) {
                $errors[$key][] = $message;
            }

            $targets[] = ['account' => $account, 'type' => $type, 'options' => (array) ($raw['options'] ?? [])];
        }

        if ($errors !== []) {
            throw new PostValidationException($errors);
        }

        return [
            'text' => $text,
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
     * @param  array{status: PostStatus, media: list<MediaAsset>, targets: list<array{account: SocialAccount, type: ContentType, options: array}>}  $plan
     * @param  Collection<string, PostTarget>|null  $existing
     * @param  array{text: ?string, media: list<int>}|null  $before  contenido previo del post (solo en edicion)
     */
    private function fill(Post $post, array $plan, ?Collection $existing = null, ?array $before = null): void
    {
        $existing ??= new Collection;
        $contentChanged = $before !== null && (
            ($before['text'] ?? null) !== ($plan['text'] ?? null)
            || $before['media'] !== array_map(fn (MediaAsset $m) => (int) $m->id, $plan['media'])
        );
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
            $optionsChanged = $before !== null && ($current->options ?? []) != ($t['options'] ?? []);
            $fresh = $current->status === PostStatus::Failed || $contentChanged || $optionsChanged;
            $attributes = ['options' => $t['options'] ?: null] + ($fresh ? $this->freshAttempt($current, $before['text'] ?? null, $before['scheduled_at'] ?? null) : []);
            if (! $fresh) {
                $attributes += $this->keepWindow($current, $before['scheduled_at'] ?? null, $plan['scheduled_at'] ?? null)
                    + $this->releaseBackoff($current, (bool) ($plan['publish_now'] ?? false));
            }
            if ($fresh) {
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
                'since' => $oldScheduledAt?->copy()->subMinutes(5)->toIso8601String(),
            ], fn ($v) => $v !== null) + ['pending' => true];
        }

        $attributes['progress'] = $carried !== null ? ['reconcile' => $carried] : null;

        return $attributes;
    }

    /**
     * Un destino con intentos previos (posiblemente ambiguos) conserva la ventana de busqueda de lo ya publicado cuando la fecha
     * del post se mueve (p. ej. «Publicar ahora» o un cambio de fecha): sin esto `findPublished` buscaria desde la fecha NUEVA
     * y dejaria fuera lo publicado antes, duplicando. Se guarda como `progress.reconcile.since` (el mismo mecanismo que `fallida`).
     *
     * @return array<string, mixed>
     */
    private function keepWindow(PostTarget $target, ?CarbonInterface $old, ?CarbonInterface $new): array
    {
        if ($old === null || $new === null || $old->equalTo($new) || $target->attempts <= 0 || isset($target->progress['reconcile'])) {
            return [];
        }

        $progress = (array) ($target->progress ?? []);
        $progress['reconcile'] = array_filter([
            'reference' => $target->external_reference,
            'since' => $old->copy()->subMinutes(5)->toIso8601String(),
        ], fn ($v) => $v !== null) + ['pending' => true];

        return ['progress' => $progress];
    }

    /** «Publicar ahora» salta la espera de un reintento transitorio, pero NO la pausa por cuota de la red. */
    private function releaseBackoff(PostTarget $target, bool $publishNow): array
    {
        if (! $publishNow || $target->status !== PostStatus::Scheduled || $target->next_attempt_at === null || $target->error_code === 'cuota') {
            return [];
        }

        return ['next_attempt_at' => null];
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
