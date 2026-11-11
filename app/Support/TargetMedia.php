<?php

namespace App\Support;

use App\Enums\ContentType;
use App\Models\PostTarget;

/**
 * Contrato unico de la media por destino (T-17). `PostTarget::$media` es `null` (hereda la media comun del post) o una lista ordenada
 * de `{id, alt, cover_id}`. Web, API y MCP la reciben como `targets[].media`; la validacion, la persistencia y la publicacion
 * leen SIEMPRE el resultado de `effective()`.
 */
final class TargetMedia
{
    public const ALT_MAX = 1000;

    /** Tope de las miniaturas de YouTube (`thumbnails.set`). */
    public const YOUTUBE_COVER_MAX_BYTES = 2_097_152;

    /** Tipos que publican texto alternativo por imagen. */
    private const ALT_TYPES = [
        ContentType::InstagramImage, ContentType::InstagramCarousel, ContentType::FacebookPhoto,
        ContentType::ThreadsImage, ContentType::ThreadsCarousel, ContentType::BlueskyPost,
        ContentType::LinkedInImage, ContentType::LinkedInCarousel, ContentType::PinterestPin,
    ];

    /** Tipos que publican una portada (imagen) para un video. */
    private const COVER_TYPES = [ContentType::InstagramReel, ContentType::YouTubeVideo, ContentType::YouTubeShort, ContentType::PinterestVideo];

    public static function supportsAlt(ContentType $type): bool
    {
        return in_array($type, self::ALT_TYPES, true);
    }

    public static function supportsCover(ContentType $type): bool
    {
        return in_array($type, self::COVER_TYPES, true);
    }

    /**
     * Normaliza la entrada de `targets[].media`: `null`/ausente = hereda; lista = media propia (`[]` es valida: el destino sin media).
     *
     * @return ?list<array{id: int, alt: ?string, cover_id: ?int}>
     */
    public static function normalize(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $items = [];
        foreach (array_values($raw) as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $item = ['id' => $item]; // atajo: solo el id
            }
            if (! is_array($item)) {
                continue;
            }
            $alt = isset($item['alt']) ? trim(str_replace(["\r\n", "\r"], "\n", (string) $item['alt'])) : '';
            $cover = $item['cover_id'] ?? null;
            $items[] = [
                'id' => (int) ($item['id'] ?? 0),
                'alt' => $alt === '' ? null : $alt,
                'cover_id' => $cover === null || $cover === '' ? null : (int) $cover,
            ];
        }

        return $items;
    }

    /**
     * Media efectiva del destino: la propia o, si hereda, la comun del post (ids en orden, sin metadatos).
     *
     * @param  list<int>  $commonIds
     * @return list<array{id: int, alt: ?string, cover_id: ?int}>
     */
    public static function effective(PostTarget|array|null $own, array $commonIds): array
    {
        $own = $own instanceof PostTarget ? $own->media : $own;

        if (is_array($own)) {
            return self::normalize($own) ?? [];
        }

        return array_map(fn ($id) => ['id' => (int) $id, 'alt' => null, 'cover_id' => null], array_values($commonIds));
    }
}
