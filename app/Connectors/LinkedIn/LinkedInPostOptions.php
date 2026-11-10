<?php

namespace App\Connectors\LinkedIn;

use App\Enums\ContentType;
use App\Models\SocialAccount;

/**
 * Opciones propias de LinkedIn (T-18): `linkedin_visibility` (PUBLIC | CONNECTIONS) y `linkedin_disable_reshare` (booleano).
 * CONNECTIONS solo existe en perfiles personales: una pagina de empresa publica siempre en abierto (`LinkedInPublisher` y esta validacion
 * lo respetan; no se descarta en silencio).
 */
final class LinkedInPostOptions
{
    public const VISIBILITIES = ['PUBLIC', 'CONNECTIONS'];

    public static function capabilities(SocialAccount $account): array
    {
        $page = ($account->meta['kind'] ?? 'profile') === 'page';

        return [
            'kind' => $page ? 'page' : 'profile',
            'visibilities' => $page ? ['PUBLIC'] : self::VISIBILITIES,
            'text_max' => (int) config('media_specs.types.linkedin_text.text.max', 3000),
            'first_comment' => true,
            'alt_text' => true,
        ];
    }

    public static function normalize(array $options): array
    {
        if (isset($options['linkedin_visibility']) && is_string($options['linkedin_visibility'])) {
            $options['linkedin_visibility'] = strtoupper(trim($options['linkedin_visibility']));
        }

        return $options;
    }

    /** @return list<string> */
    public static function errors(array $options, ContentType $type, ?SocialAccount $account = null): array
    {
        $errors = [];
        $asked = fn (string $k) => array_key_exists($k, $options) && ! in_array($options[$k], [null, false, ''], true);
        $isLinkedIn = $type->network()->value === 'linkedin';

        if (! $isLinkedIn) {
            foreach (['linkedin_visibility', 'linkedin_disable_reshare'] as $k) {
                if ($asked($k)) {
                    $errors[] = "La opcion $k solo existe en LinkedIn.";
                }
            }

            return $errors;
        }

        if ($asked('linkedin_visibility')) {
            $visibility = $options['linkedin_visibility'];
            if (! is_string($visibility) || ! in_array($visibility, self::VISIBILITIES, true)) {
                $errors[] = 'La visibilidad de LinkedIn debe ser PUBLIC o CONNECTIONS.';
            } elseif ($visibility === 'CONNECTIONS' && $account !== null && ($account->meta['kind'] ?? 'profile') === 'page') {
                $errors[] = 'Las paginas de LinkedIn publican siempre en abierto: la visibilidad CONNECTIONS solo existe en perfiles personales.';
            }
        }
        if (array_key_exists('linkedin_disable_reshare', $options) && $options['linkedin_disable_reshare'] !== null && ! is_bool($options['linkedin_disable_reshare'])) {
            $errors[] = 'Impedir que se comparta en LinkedIn debe ser un booleano.';
        }

        return $errors;
    }
}
