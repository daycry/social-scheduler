<?php

namespace App\Services;

use App\Connectors\Google\YouTubePublisher;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Exceptions\InvalidPostTransition;
use App\Exceptions\PostValidationException;
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

            $post->forceFill(['text' => $plan['text'], 'scheduled_at' => $plan['scheduled_at']])->save();
            $this->fill($post, $plan, $existing);

            return $post->fresh(['targets', 'media']);
        });
    }

    /** Programa un borrador (o una publicacion con error): exige fecha futura y contenido valido. */
    public function schedule(User $user, Post $post, string|CarbonInterface|null $when = null): Post
    {
        $this->assertOwns($user, $post);

        $brand = Brand::withoutUserScope()->findOrFail($post->brand_id);
        $data = [
            'text' => $post->text,
            'scheduled_at' => $when ?? $post->scheduled_at,
            'schedule' => true,
            'media' => $post->media()->pluck('media_assets.id')->all(),
            'targets' => $post->targets()->get()->map(fn (PostTarget $t) => [
                'account_id' => $t->social_account_id, 'content_type' => $t->content_type->value, 'options' => $t->options ?? [],
            ])->all(),
        ];

        return $this->update($user, $post, $data);
    }

    /** Vuelve a borrador (deja de publicarse) una publicacion pendiente. */
    public function unschedule(User $user, Post $post): Post
    {
        $this->assertOwns($user, $post);

        DB::transaction(function () use ($post) {
            foreach ($this->lockEditableTargets($post) as $target) {
                if ($target->status !== PostStatus::Draft) {
                    $this->applyTransition($target, PostStatus::Draft);
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

            $post->forceFill(['scheduled_at' => $at])->save();
            $targets->filter(fn (PostTarget $t) => $t->status === PostStatus::Failed)
                ->each(function (PostTarget $t) {
                    $this->transition($t, PostStatus::Scheduled, self::FRESH_ATTEMPT);
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
            $this->lockEditableTargets($post, 'No se puede borrar una publicacion que ya se ha publicado o se esta publicando.');
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
            Post::withoutUserScope()->findOrFail($fresh->post_id)->syncStatus();

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
    private function lockEditableTargets(Post $post, string $message = 'La publicacion ya se ha publicado o se esta publicando y no se puede modificar.'): Collection
    {
        $targets = PostTarget::withoutUserScope()->where('post_id', $post->id)->orderBy('id')->lockForUpdate()->get();

        if ($targets->contains(fn (PostTarget $t) => $t->status->isInFlight() || $t->status->isFinal())) {
            throw new PostValidationException(['post' => [$message]]);
        }

        return $targets->keyBy(fn (PostTarget $t) => $t->social_account_id.'|'.$t->content_type->value);
    }

    /**
     * Valida todo y devuelve el plan normalizado (sin tocar la BD).
     *
     * @param  array<string, mixed>  $data
     * @return array{text: ?string, scheduled_at: ?CarbonImmutable, status: PostStatus, media: list<MediaAsset>, targets: list<array{account: SocialAccount, type: ContentType, options: array}>}
     */
    private function plan(User $user, Brand $brand, array $data): array
    {
        $errors = [];
        $schedule = (bool) ($data['schedule'] ?? false);
        $text = isset($data['text']) ? (string) $data['text'] : null;

        $at = null;
        if (($data['scheduled_at'] ?? null) !== null && $data['scheduled_at'] !== '') {
            try {
                $at = $this->parseWhen($data['scheduled_at'], $brand);
            } catch (Throwable) {
                $errors['scheduled_at'][] = 'La fecha no es valida.';
            }
        }

        if ($schedule && $at === null && ! isset($errors['scheduled_at'])) {
            $errors['scheduled_at'][] = 'Indica la fecha y hora de publicacion.';
        } elseif ($schedule && $at !== null && $at->lte(now())) {
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
     */
    private function fill(Post $post, array $plan, ?Collection $existing = null): void
    {
        $existing ??= new Collection;
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
            // Un destino con error vuelve a empezar de cero; uno pendiente conserva sus intentos (idempotencia).
            $attributes = ['options' => $t['options'] ?: null] + ($current->status === PostStatus::Failed ? self::FRESH_ATTEMPT : []);
            if ($current->status === PostStatus::Failed) {
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

    /** Un string sin zona se interpreta en la zona de la marca; el resultado se guarda en UTC. */
    private function parseWhen(string|CarbonInterface $when, Brand $brand): CarbonImmutable
    {
        if ($when instanceof CarbonInterface) {
            return CarbonImmutable::instance($when)->utc();
        }

        return CarbonImmutable::parse($when, $brand->timezone ?: 'UTC')->utc();
    }
}
