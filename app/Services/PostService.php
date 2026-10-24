<?php

namespace App\Services;

use App\Connectors\Data\PublishResult;
use App\Connectors\Google\YouTubePublisher;
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
 *  - targets: list<array{account_id: int, content_type: string, options?: array, text?: ?string}> (`text` null/ausente = vinculado a la plantilla; texto propio = se publica ese)
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

        $plan = $this->plan($user, $brand, $data);

        return DB::transaction(function () use ($user, $brand, $plan) {
            $post = new Post(['text' => $plan['text'], 'scheduled_at' => $plan['scheduled_at']]);
            if ($plan['notes'] !== false) {
                $post->notes = $plan['notes'];
            }
            $post->user_id = $user->id;
            $post->brand_id = $brand->id;
            if ($plan['campaign_id'] !== false) {
                $post->campaign_id = $plan['campaign_id'];
            }
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

            $post->forceFill(['text' => $plan['text'], 'scheduled_at' => $plan['scheduled_at']]);
            if ($plan['notes'] !== false) {
                $post->notes = $plan['notes']; // fuera de `$before`/`fill()`: una nota nunca cuenta como «contenido cambiado»
            }
            if ($plan['campaign_id'] !== false) {
                $post->campaign_id = $plan['campaign_id']; // por post entero (D3): no cuenta como «contenido cambiado»
            }
            $post->save();
            $this->fill($post, $plan, $existing, $before);
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
                'account_id' => $t->social_account_id, 'content_type' => $t->content_type->value, 'options' => $t->options ?? [], 'text' => $t->text, 'first_comment' => $t->first_comment,
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

            $post->forceFill(['scheduled_at' => $at])->save();
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
    private const TIKTOK_DIRECT_OPTIONS = ['disable_duet', 'disable_stitch', 'brand_organic_toggle', 'brand_content_toggle', 'is_aigc'];

    /** Opciones booleanas: la regla `boolean` de la web y el MCP admite `1`, `0`, `"1"` y `"0"` sin convertirlas. */
    private const BOOLEAN_OPTIONS = [
        'share_to_feed', 'shorts_hashtag', 'made_for_kids', 'notify_subscribers', 'contains_synthetic_media',
        'disable_comment', 'disable_duet', 'disable_stitch', 'brand_organic_toggle', 'brand_content_toggle', 'is_aigc',
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
        $errors = [];

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

    /**
     * Valida todo y devuelve el plan normalizado (sin tocar la BD).
     *
     * @param  array<string, mixed>  $data
     * @return array{text: ?string, scheduled_at: ?CarbonImmutable, status: PostStatus, publish_now: bool, media: list<MediaAsset>, targets: list<array{account: SocialAccount, type: ContentType, options: array, text: ?string, first_comment: ?string}>}
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

            // Texto propio del destino (null = vinculado a la plantilla): se valida el EFECTIVO, que es el que se publica.
            // Un texto propio vacio o solo con espacios es `null` (vinculado): publicaria vacio y `findPublished` no podria reconocerlo.
            // Igual que la web (`TrimStrings` + `ConvertEmptyStringsToNull`), y vale tambien para el MCP.
            $ownText = isset($raw['text']) && trim((string) $raw['text']) !== '' ? (string) $raw['text'] : null;
            foreach ($this->validator->validate($type, $media, $ownText ?? $text) as $message) {
                $errors[$key][] = $message;
            }

            $options = self::normalizeOptions((array) ($raw['options'] ?? []));
            foreach ($this->optionErrors($type, $account, $options) as $message) {
                $errors[$key][] = $message;
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

            $targets[] = ['account' => $account, 'type' => $type, 'options' => $options, 'text' => $ownText, 'first_comment' => $firstComment];
        }

        // Campaña (C-03, CA-12): null = quitarla; solo de esta marca y de este usuario. Omitida = no se toca (`false`).
        $campaignId = false;
        if (array_key_exists('campaign_id', $data)) {
            $campaignId = ($data['campaign_id'] === null || $data['campaign_id'] === '') ? null : (int) $data['campaign_id'];
            if ($campaignId !== null && ! Campaign::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->whereKey($campaignId)->exists()) {
                $errors['campaign_id'][] = 'La campaña no existe en esta marca.';
            }
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
     * @param  array{status: PostStatus, media: list<MediaAsset>, targets: list<array{account: SocialAccount, type: ContentType, options: array, text: ?string, first_comment: ?string}>}  $plan
     * @param  Collection<string, PostTarget>|null  $existing
     * @param  array{text: ?string, media: list<int>}|null  $before  contenido previo del post (solo en edicion)
     */
    private function fill(Post $post, array $plan, ?Collection $existing = null, ?array $before = null): void
    {
        $existing ??= new Collection;
        // La media es comun a todos los destinos; el TEXTO se compara por destino (texto efectivo viejo frente al nuevo, ADR-005).
        $mediaChanged = $before !== null && $before['media'] !== array_map(fn (MediaAsset $m) => (int) $m->id, $plan['media']);
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
            $fresh = $current->status === PostStatus::Failed || $mediaChanged || $textChanged || $optionsChanged;
            // El primer comentario NO cuenta para `$fresh`: se publica despues del destino y no cambia lo que sale en la red.
            $attributes = ['options' => $t['options'] ?: null, 'text' => $t['text'], 'first_comment' => $t['first_comment'] ?? null] + ($fresh ? $this->freshAttempt($current, $oldText, $before['scheduled_at'] ?? null) : []);
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
