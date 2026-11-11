<?php

namespace App\Connectors\Meta\Instagram;

use App\Enums\ContentType;

/** Feed presets supported by the current Instagram media-container contract. */
final class InstagramPostOptions
{
    public const MAX_COLLABORATORS = 3;

    /** Tope de etiquetas de usuario por imagen (limite de la Graph API de Instagram). */
    public const MAX_USER_TAGS = 20;

    public static function supports(ContentType $type): bool
    {
        return in_array($type, [ContentType::InstagramImage, ContentType::InstagramReel, ContentType::InstagramCarousel], true);
    }

    public static function capabilities(): array
    {
        return ['max_collaborators' => self::MAX_COLLABORATORS, 'content_types' => ['instagram_image', 'instagram_reel', 'instagram_carousel'], 'ai_disclosure' => true,
            // T-18: `location_id` (id numerico de lugar de Facebook; la app NO busca lugares) y `user_tags` [{username, x, y}] solo en imagenes.
            'location_id' => true, 'user_tags' => ['content_types' => ['instagram_image'], 'max' => self::MAX_USER_TAGS, 'coordinates' => 'x,y entre 0 y 1']];
    }

    public static function normalize(array $options): array
    {
        if (array_key_exists('is_ai_generated', $options) && in_array($options['is_ai_generated'], [0, 1, '0', '1'], true)) {
            $options['is_ai_generated'] = (bool) (int) $options['is_ai_generated'];
        }
        if (isset($options['collaborators']) && is_array($options['collaborators'])) {
            $options['collaborators'] = array_map(fn ($name) => is_string($name) ? mb_strtolower(preg_replace('/^@/', '', trim($name))) : $name, $options['collaborators']);
        }

        if (isset($options['user_tags']) && is_array($options['user_tags'])) {
            $options['user_tags'] = array_map(function ($tag) {
                if (is_array($tag) && is_string($tag['username'] ?? null)) {
                    $tag['username'] = mb_strtolower(preg_replace('/^@/', '', trim($tag['username'])));
                }

                return $tag;
            }, $options['user_tags']);
        }
        if (isset($options['location_id']) && is_int($options['location_id'])) {
            $options['location_id'] = (string) $options['location_id'];
        }

        return $options;
    }

    public static function errors(array $options, ContentType $type): array
    {
        $errors = [];
        $names = $options['collaborators'] ?? [];
        if (! is_array($names) || ! array_is_list($names) || count($names) > self::MAX_COLLABORATORS) {
            $errors[] = 'Indica una lista de hasta 3 usuarios de Instagram como colaboradores.';
        } else {
            $seen = [];
            foreach ($names as $name) {
                if (! is_string($name) || preg_match('/^[a-zA-Z0-9._]{1,30}$/D', $name) !== 1 || in_array(strtolower($name), $seen, true)) {
                    $errors[] = 'Los colaboradores deben ser usuarios de Instagram válidos y distintos, sin enlaces.';
                    break;
                }
                $seen[] = strtolower($name);
            }
        }
        if (array_key_exists('is_ai_generated', $options) && $options['is_ai_generated'] !== null && ! is_bool($options['is_ai_generated'])) {
            $errors[] = 'La indicación de contenido generado con IA debe ser booleana.';
        }
        if (! self::supports($type) && ($names !== [] || ($options['is_ai_generated'] ?? false) !== false)) {
            $errors[] = 'Colaboradores e indicación de IA solo están disponibles aquí para imágenes, Reels y carruseles de Instagram.';
        }

        $errors = array_merge($errors, self::locationAndTagErrors($options, $type));

        return $errors;
    }

    /** @return list<string> */
    private static function locationAndTagErrors(array $options, ContentType $type): array
    {
        $errors = [];
        $asked = fn (string $k) => array_key_exists($k, $options) && ! in_array($options[$k], [null, false, [], ''], true);

        if ($asked('location_id')) {
            if (! self::supports($type)) {
                $errors[] = 'La ubicacion solo esta disponible para imagenes, Reels y carruseles de Instagram.';
            } elseif (! is_string($options['location_id']) || preg_match('/^\d{1,20}$/D', $options['location_id']) !== 1) {
                $errors[] = 'La ubicacion de Instagram debe ser el id numerico de un lugar (location_id).';
            }
        }

        if ($asked('user_tags')) {
            $tags = $options['user_tags'];
            if ($type !== ContentType::InstagramImage) {
                $errors[] = 'Las etiquetas de usuario con coordenadas solo estan disponibles en las imagenes de Instagram.';
            } elseif (! is_array($tags) || ! array_is_list($tags) || count($tags) > self::MAX_USER_TAGS) {
                $errors[] = 'Indica una lista de hasta '.self::MAX_USER_TAGS.' etiquetas de usuario, cada una con username, x e y.';
            } else {
                $seen = [];
                foreach ($tags as $tag) {
                    $ok = is_array($tag) && is_string($tag['username'] ?? null) && preg_match('/^[a-zA-Z0-9._]{1,30}$/D', $tag['username']) === 1
                        && (is_int($tag['x'] ?? null) || is_float($tag['x'] ?? null)) && (is_int($tag['y'] ?? null) || is_float($tag['y'] ?? null))
                        && $tag['x'] >= 0 && $tag['x'] <= 1 && $tag['y'] >= 0 && $tag['y'] <= 1 && ! in_array(strtolower($tag['username']), $seen, true);
                    if (! $ok) {
                        $errors[] = 'Cada etiqueta de usuario necesita un username de Instagram valido y distinto, y coordenadas x e y entre 0 y 1.';
                        break;
                    }
                    $seen[] = strtolower($tag['username']);
                }
            }
        }

        return $errors;
    }

    public static function containerParams(array $options): array
    {
        $params = [];
        if (! empty($options['collaborators'])) {
            $params['collaborators'] = json_encode($options['collaborators'], JSON_THROW_ON_ERROR);
        }
        if (isset($options['is_ai_generated'])) {
            $params['is_ai_generated'] = $options['is_ai_generated'] ? 'true' : 'false';
        }

        if (! empty($options['location_id'])) {
            $params['location_id'] = (string) $options['location_id'];
        }
        if (! empty($options['user_tags'])) {
            $params['user_tags'] = json_encode(array_map(fn ($t) => ['username' => $t['username'], 'x' => (float) $t['x'], 'y' => (float) $t['y']], $options['user_tags']), JSON_THROW_ON_ERROR);
        }

        return $params;
    }
}
