<?php

namespace App\Services;

use App\Enums\ContentType;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Biblioteca (PA-06): guarda una publicacion o un texto como plantilla reutilizable (texto + media + destinos) y la
 * «usa» creando un BORRADOR con `PostService` (nunca programa nada por si solo).
 */
class LibraryService
{
    public const MAX_PER_USER = 500;

    public function __construct(private readonly PostService $posts) {}

    /** Guarda lo que hay en una publicacion (aunque este en la papelera o publicada: es solo una copia). */
    public function saveFromPost(User $user, Post $post, string $name): PostTemplate
    {
        if ($post->user_id !== $user->id) {
            throw new PostValidationException(['post' => ['La publicacion no pertenece al usuario.']]);
        }

        $targets = $post->targets()->whereNotNull('social_account_id')->get()
            ->map(fn ($t) => ['account_id' => $t->social_account_id, 'content_type' => $t->content_type->value])->values()->all();

        return $this->store($user, Brand::withoutUserScope()->findOrFail($post->brand_id), $name, $post->text, $post->media()->pluck('media_assets.id')->all(), $targets);
    }

    /**
     * @param  list<int>  $mediaIds
     * @param  list<array{account_id: int, content_type: string}>  $targets
     */
    public function store(User $user, Brand $brand, string $name, ?string $text, array $mediaIds = [], array $targets = []): PostTemplate
    {
        if ($brand->user_id !== $user->id) {
            throw new PostValidationException(['brand' => ['La marca no pertenece al usuario.']]);
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new PostValidationException(['name' => ['El nombre es obligatorio (maximo 120 caracteres).']]);
        }
        if (PostTemplate::withoutUserScope()->where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
            throw new PostValidationException(['name' => ['Has alcanzado el maximo de plantillas.']]);
        }

        return DB::transaction(function () use ($user, $brand, $name, $text, $mediaIds, $targets) {
            $template = new PostTemplate(['name' => $name, 'text' => $text, 'targets' => $targets ?: null]);
            $template->user_id = $user->id;
            $template->brand_id = $brand->id;
            $template->save();

            // Solo media del usuario, en orden.
            $owned = MediaAsset::withoutUserScope()->where('user_id', $user->id)->whereIn('id', $mediaIds)->pluck('id')->all();
            $ordered = array_values(array_filter(array_map('intval', $mediaIds), fn ($id) => in_array($id, $owned, true)));
            $template->media()->sync(collect(array_values(array_unique($ordered)))->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i]])->all());

            return $template;
        });
    }

    /** Crea un borrador a partir de la plantilla; descarta los destinos/media que ya no son validos. */
    public function use(User $user, PostTemplate $template): Post
    {
        if ($template->user_id !== $user->id) {
            throw new PostValidationException(['template' => ['La plantilla no pertenece al usuario.']]);
        }

        $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($template->brand_id);
        $mediaIds = $template->media()->pluck('media_assets.id')->map(fn ($id) => (int) $id)->all();

        $accounts = SocialAccount::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->get()->keyBy('id');
        $targets = [];
        foreach ((array) $template->targets as $t) {
            $account = $accounts->get((int) ($t['account_id'] ?? 0));
            $type = ContentType::tryFrom((string) ($t['content_type'] ?? ''));
            if ($account && $type && $type->network() === $account->network && $type->isOffered()) {
                $targets[] = ['account_id' => $account->id, 'content_type' => $type->value];
            }
        }

        $data = ['text' => $template->text, 'schedule' => false, 'media' => $mediaIds];

        try {
            return $this->posts->create($user, $brand, $data + ['targets' => $targets]);
        } catch (PostValidationException) {
            // Un destino que ya no admite esta media (p. ej. cambio de tipo): se conserva el contenido como borrador sin destinos.
            return $this->posts->create($user, $brand, $data + ['targets' => []]);
        }
    }
}
