<?php

namespace App\Connectors\Mastodon;

use App\Models\SocialAccount;
use App\Support\TextLength;
use Carbon\CarbonImmutable;

/**
 * Limites de UNA instancia de Mastodon (T-87), guardados en `meta.limits` de la cuenta al conectar (`GET /api/v2/instance`) y refrescados al publicar
 * cuando tienen mas de `social.mastodon.limits_ttl_hours`. Sin dato se usan los valores prudentes de `social.mastodon.default_*` (500 caracteres, 4 imagenes).
 * El compositor y `MediaValidator` leen de aqui los limites de ESA cuenta.
 *
 * El texto se cuenta como lo cuenta Mastodon (`TextLength::MASTODON`: grafemas, cada URL como `characters_reserved_per_url` y una mencion remota como `@usuario`):
 * `specFor` lo fija en la regla y `forComposer` manda la reserva al compositor, que usa el espejo `textLength.js`.
 */
final class MastodonLimits
{
    /**
     * Normaliza la respuesta de `GET /api/v2/instance`: solo los valores presentes y validos (el resto se completa con los por defecto al leerlos).
     *
     * @param  array<string, mixed>  $instance
     * @return array<string, mixed>
     */
    public static function fromInstance(array $instance): array
    {
        $statuses = (array) ($instance['configuration']['statuses'] ?? []);
        $media = (array) ($instance['configuration']['media_attachments'] ?? []);
        $mimes = array_values(array_filter((array) ($media['supported_mime_types'] ?? []), fn ($m) => is_string($m) && preg_match('~^[a-z]+/[a-z0-9.+-]+$~i', $m) === 1));

        return array_filter([
            'max_characters' => self::positive($statuses['max_characters'] ?? null),
            'characters_reserved_per_url' => self::positive($statuses['characters_reserved_per_url'] ?? null),
            'max_media_attachments' => self::positive($statuses['max_media_attachments'] ?? null),
            'supported_mime_types' => $mimes !== [] ? $mimes : null,
            'image_size_limit' => self::positive($media['image_size_limit'] ?? null),
            'description_limit' => self::positive($media['description_limit'] ?? null),
            'fetched_at' => CarbonImmutable::now()->toIso8601String(),
        ], fn ($v) => $v !== null);
    }

    /**
     * Limites efectivos de la cuenta: los guardados sobre los por defecto.
     *
     * @return array{max_characters: int, characters_reserved_per_url: int, max_media_attachments: int, image_mime_types: list<string>, image_size_limit: int, description_limit: ?int}
     */
    public static function forAccount(SocialAccount $account): array
    {
        $stored = (array) ($account->meta['limits'] ?? []);
        $mimes = array_values(array_filter((array) ($stored['supported_mime_types'] ?? config('social.mastodon.default_mime_types')), fn ($m) => is_string($m) && str_starts_with($m, 'image/')));

        return [
            'max_characters' => self::positive($stored['max_characters'] ?? null) ?? (int) config('social.mastodon.default_max_characters', 500),
            'characters_reserved_per_url' => self::positive($stored['characters_reserved_per_url'] ?? null) ?? 23,
            'max_media_attachments' => self::positive($stored['max_media_attachments'] ?? null) ?? (int) config('social.mastodon.default_max_media', 4),
            'image_mime_types' => $mimes !== [] ? $mimes : (array) config('social.mastodon.default_mime_types'),
            'image_size_limit' => self::positive($stored['image_size_limit'] ?? null) ?? (int) config('social.mastodon.default_image_bytes'),
            'description_limit' => self::positive($stored['description_limit'] ?? null),
        ];
    }

    /** ¿Hay que volver a leerlos? Sin fecha o con mas de `limits_ttl_hours`. */
    public static function isStale(SocialAccount $account): bool
    {
        $fetched = $account->meta['limits']['fetched_at'] ?? null;
        if (! is_string($fetched)) {
            return true;
        }

        try {
            return CarbonImmutable::parse($fetched)->lte(CarbonImmutable::now()->subHours(max(1, (int) config('social.mastodon.limits_ttl_hours', 24))));
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * La regla de `media_specs.mastodon_post` con los limites de la cuenta: texto, imagenes, formatos y peso.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public static function specFor(array $spec, SocialAccount $account): array
    {
        $limits = self::forAccount($account);
        $spec['text']['max'] = $limits['max_characters'];
        $spec['text']['count'] = TextLength::MASTODON;
        $spec['text']['url_length'] = $limits['characters_reserved_per_url'];
        $spec['media']['max'] = $limits['max_media_attachments'];
        $spec['image']['mimes'] = $limits['image_mime_types'];
        $spec['image']['max_bytes'] = $limits['image_size_limit'];

        return $spec;
    }

    /**
     * Lo que el compositor necesita saber de la cuenta (contadores y avisos); sin secretos.
     *
     * @return array{max_chars: int, max_media: int, url_length: int}
     */
    public static function forComposer(SocialAccount $account): array
    {
        $limits = self::forAccount($account);

        return ['max_chars' => $limits['max_characters'], 'max_media' => $limits['max_media_attachments'], 'url_length' => $limits['characters_reserved_per_url']];
    }

    private static function positive(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
