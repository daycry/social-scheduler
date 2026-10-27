<?php

namespace App\Connectors\Bluesky;

use App\Connectors\Contracts\Publisher;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use App\Services\Media\ImageDownscaler;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Publicacion en Bluesky (`app.bsky.feed.post`). Texto con *facets* (enlaces, hashtags, menciones en bytes UTF-8) y de 0 a 4
 * imagenes (`uploadBlob` + `aspectRatio`); las que superan `social.bluesky.image_max_bytes` se suben como la copia recomprimida
 * de `ImageDownscaler`. Toda llamada autenticada pasa por `BlueskySession::call`, que renueva la sesion si caduca.
 */
class BlueskyPublisher implements Publisher
{
    public const COLLECTION = 'app.bsky.feed.post';

    public function __construct(
        private readonly BlueskyClient $client,
        private readonly BlueskySession $session,
        private readonly ImageDownscaler $downscaler,
    ) {}

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        try {
            return match ($request->type) {
                ContentType::BlueskyPost => $this->publishPost($account, $request),
                default => throw new PermanentPublishException('Bluesky no admite este tipo de contenido.', 'tipo_no_admitido'),
            };
        } catch (BlueskyException $e) {
            throw BlueskyErrors::translate($e);
        }
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        return null; // la idempotencia por rkey llega con T-25
    }

    // ------------------------------------------------------------------ texto e imagenes

    private function publishPost(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $images = [];
        foreach ($request->media as $media) {
            $images[] = $this->uploadImage($account, $media);
            $request->budget()->spent();
        }

        $record = $this->record($account, $request, $images === [] ? null : ['$type' => 'app.bsky.embed.images', 'images' => $images]);

        $created = $this->session->call($account, fn (string $token, string $pds) => $this->client->createRecord($pds, $token, $this->did($account), self::COLLECTION, $record));
        $request->budget()->spent();

        return $this->published($account, (string) ($created['uri'] ?? ''), $created);
    }

    /**
     * Registro `app.bsky.feed.post`.
     *
     * @param  ?array<string, mixed>  $embed
     * @return array<string, mixed>
     */
    private function record(SocialAccount $account, PublishRequest $request, ?array $embed, ?string $text = null): array
    {
        $text = trim((string) ($text ?? $request->text));
        $facets = Facets::build($text, fn (string $handle) => $this->resolveMention($handle));

        return array_filter([
            '$type' => self::COLLECTION,
            'text' => $text,
            'createdAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'langs' => array_slice((array) config('social.bluesky.default_langs', []), 0, 3) ?: null,
            'facets' => $facets ?: null,
            'embed' => $embed,
        ], fn ($v) => $v !== null);
    }

    /**
     * Sube una imagen (o su copia recomprimida) y devuelve el elemento de `app.bsky.embed.images`.
     *
     * @return array<string, mixed>
     */
    private function uploadImage(SocialAccount $account, PublishMedia $media): array
    {
        if ($media->isVideo() || $media->disk === null || $media->path === null) {
            throw new PermanentPublishException('Bluesky solo admite imagenes en este tipo de publicacion.', 'tipo_no_admitido');
        }

        $fit = $this->downscaler->fitFile($media->disk, $media->path, $media->id, (int) $media->size, $media->mime, (int) config('social.bluesky.image_max_bytes'));
        if ($fit === null) {
            throw new PermanentPublishException('La imagen es demasiado grande para Bluesky y no se ha podido reducir.', 'archivo_grande');
        }

        try {
            $bytes = (string) Storage::disk($fit['disk'])->get($fit['path']);
        } catch (Throwable) {
            throw new PermanentPublishException('No se pudo leer una de las imagenes.', 'media_ilegible');
        }

        if ($bytes === '') {
            throw new PermanentPublishException('No se pudo leer una de las imagenes.', 'media_ilegible');
        }

        $blob = $this->session->call($account, fn (string $token, string $pds) => $this->client->uploadBlob($pds, $token, $bytes, $fit['mime']));

        // Proporcion de lo que realmente se sube (la copia puede tener otro tamano que la original).
        $size = @getimagesizefromstring($bytes);
        $width = $size !== false ? $size[0] : $media->width;
        $height = $size !== false ? $size[1] : $media->height;

        return array_filter([
            'alt' => '',
            'image' => $blob,
            'aspectRatio' => $width && $height ? ['width' => (int) $width, 'height' => (int) $height] : null,
        ], fn ($v) => $v !== null);
    }

    // ------------------------------------------------------------------ utilidades

    /** DID de la cuenta (repositorio donde se escribe). */
    private function did(SocialAccount $account): string
    {
        return (string) ($account->meta['did'] ?? $account->external_id);
    }

    /** DID de un handle mencionado, o null si no existe o falla la consulta (la mencion queda como texto plano). */
    private function resolveMention(string $handle): ?string
    {
        try {
            return $this->client->resolveHandle($handle);
        } catch (BlueskyException) {
            return null;
        }
    }

    /** @param array<string, mixed> $created */
    private function published(SocialAccount $account, string $uri, array $created): PublishResult
    {
        if (! preg_match('~^at://[^/]+/app\.bsky\.feed\.post/([^/]+)$~', $uri, $m)) {
            throw new PermanentPublishException('Bluesky no devolvio el identificador de la publicacion.', 'sin_id');
        }

        $profile = (string) ($account->meta['handle'] ?? $account->username ?? $this->did($account));

        return PublishResult::published($uri, 'https://bsky.app/profile/'.$profile.'/post/'.$m[1], ['cid' => $created['cid'] ?? null]);
    }
}
