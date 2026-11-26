<?php

namespace App\Connectors\Mastodon;

use App\Enums\ContentType;

/**
 * Opciones de Mastodon (T-87): `mastodon_visibility` (`public` por defecto, `unlisted`, `private` o `direct`), la visibilidad del estado.
 */
final class MastodonPostOptions
{
    public const VISIBILITIES = ['public', 'unlisted', 'private', 'direct'];

    public const DEFAULT_VISIBILITY = 'public';

    /** Visibilidades que ofrece el compositor, con su etiqueta. @return list<array{value: string, label: string}> */
    public static function capabilities(): array
    {
        return [
            ['value' => 'public', 'label' => 'Pública'],
            ['value' => 'unlisted', 'label' => 'Pública sin listar'],
            ['value' => 'private', 'label' => 'Solo seguidores'],
            ['value' => 'direct', 'label' => 'Solo las personas mencionadas'],
        ];
    }

    /** @return list<string> */
    public static function errors(array $options, ContentType $type): array
    {
        $asked = array_key_exists('mastodon_visibility', $options) && ! in_array($options['mastodon_visibility'], [null, false, [], ''], true);

        if (! $asked) {
            return [];
        }
        if ($type->network()->value !== 'mastodon') {
            return ['La opcion mastodon_visibility solo existe en Mastodon.'];
        }

        return is_string($options['mastodon_visibility']) && in_array($options['mastodon_visibility'], self::VISIBILITIES, true)
            ? []
            : ['La visibilidad de Mastodon debe ser public, unlisted, private o direct.'];
    }

    /** Visibilidad efectiva del destino. @param array<string, mixed> $options */
    public static function visibility(array $options): string
    {
        $value = $options['mastodon_visibility'] ?? null;

        return is_string($value) && in_array($value, self::VISIBILITIES, true) ? $value : self::DEFAULT_VISIBILITY;
    }
}
