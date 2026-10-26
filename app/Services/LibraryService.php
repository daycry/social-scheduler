<?php

namespace App\Services;

use App\Enums\ContentType;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Campaign;
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
            ->map(fn ($t) => ['account_id' => $t->social_account_id, 'content_type' => $t->content_type->value] + $this->ownText($t->text))->values()->all();

        return $this->store($user, Brand::withoutUserScope()->findOrFail($post->brand_id), $name, $post->text, $post->media()->pluck('media_assets.id')->all(), $targets, $post->campaign_id);
    }

    /**
     * Guarda el contenido ACTUAL del compositor (texto, media y destinos), sin que exista un post (CA-26). Solo se conservan los
     * destinos de cuentas de esta marca y usuario con un tipo valido para su red; la media ajena la descarta `store()`.
     *
     * @param  array{text?: ?string, media?: list<int|string>, targets?: list<array{account_id?: mixed, content_type?: mixed, text?: mixed}>}  $data
     */
    public function saveFromComposer(User $user, Brand $brand, string $name, array $data): PostTemplate
    {
        if ($brand->user_id !== $user->id) {
            throw new PostValidationException(['brand' => ['La marca no pertenece al usuario.']]);
        }

        $accounts = SocialAccount::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->get()->keyBy('id');
        $targets = [];
        foreach ((array) ($data['targets'] ?? []) as $t) {
            $account = $accounts->get((int) ($t['account_id'] ?? 0));
            $type = ContentType::tryFrom((string) ($t['content_type'] ?? ''));
            if ($account && $type && $type->network() === $account->network) {
                $targets[] = ['account_id' => $account->id, 'content_type' => $type->value] + $this->ownText($t['text'] ?? null);
            }
        }

        return $this->store($user, $brand, $name, isset($data['text']) ? (string) $data['text'] : null, array_map('intval', (array) ($data['media'] ?? [])), $targets, isset($data['campaign_id']) ? (int) $data['campaign_id'] : null);
    }

    /**
     * @param  list<int>  $mediaIds
     * @param  list<array{account_id: int, content_type: string, text?: string}>  $targets  `text` solo si el destino tiene texto propio
     */
    public function store(User $user, Brand $brand, string $name, ?string $text, array $mediaIds = [], array $targets = [], ?int $campaignId = null): PostTemplate
    {
        if ($brand->user_id !== $user->id) {
            throw new PostValidationException(['brand' => ['La marca no pertenece al usuario.']]);
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new PostValidationException(['name' => ['El nombre es obligatorio (maximo 120 caracteres).']]);
        }
        if ($campaignId !== null && ! Campaign::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->whereKey($campaignId)->exists()) {
            throw new PostValidationException(['campaign_id' => ['La campaña no existe en esta marca.']]);
        }
        if (PostTemplate::withoutUserScope()->where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
            throw new PostValidationException(['name' => ['Has alcanzado el maximo de plantillas.']]);
        }

        return DB::transaction(function () use ($user, $brand, $name, $text, $mediaIds, $targets, $campaignId) {
            $template = new PostTemplate(['name' => $name, 'text' => $text, 'targets' => $targets ?: null]);
            $template->campaign_id = $campaignId;
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

    /**
     * El texto propio de un destino viaja en la plantilla solo si existe (`null` = vinculado a la plantilla, como las plantillas
     * guardadas antes de C-03, que no traen la clave). Una cadena es el texto propio tal cual se guardo, incluida la vacia: al crear
     * el borrador, `PostService::plan` trata una cadena vacia o solo con espacios como `null` (vinculado), igual que la web.
     *
     * @return array{text?: string}
     */
    private function ownText(mixed $text): array
    {
        return is_string($text) ? ['text' => $text] : [];
    }

    /**
     * Crea un borrador a partir de la plantilla; descarta los destinos/media que ya no son validos. Si la campana de la plantilla
     * ya no se puede asignar (archivada despues de guardarla, o borrada), el borrador nace SIN campana y `$notice` lo explica.
     */
    public function use(User $user, PostTemplate $template, ?string &$notice = null): Post
    {
        $notice = null;

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
                $targets[] = ['account_id' => $account->id, 'content_type' => $type->value] + $this->ownText($t['text'] ?? null);
            }
        }

        $data = ['text' => $template->text, 'schedule' => false, 'media' => $mediaIds];
        // La campaña viaja con la plantilla (C-03). `PostService` rechaza asignar una campaña archivada, asi que una plantilla cuya
        // campaña se archivo despues sigue siendo usable: el borrador nace sin campaña y se avisa (nunca se desarchiva sola).
        if ($template->campaign_id !== null) {
            $campaign = Campaign::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->find($template->campaign_id);

            if ($campaign !== null && ! $campaign->isArchived()) {
                $data['campaign_id'] = $campaign->id;
            } else {
                $notice = $campaign !== null
                    ? "La campaña «{$campaign->name}» está archivada: el borrador se ha creado sin campaña."
                    : 'La campaña de la plantilla ya no existe: el borrador se ha creado sin campaña.';
            }
        }

        try {
            return $this->posts->create($user, $brand, $data + ['targets' => $targets]);
        } catch (PostValidationException) {
            // Un destino que ya no admite esta media (p. ej. cambio de tipo): se conserva el contenido como borrador sin destinos.
            return $this->posts->create($user, $brand, $data + ['targets' => []]);
        }
    }
}
