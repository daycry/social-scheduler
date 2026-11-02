<?php

namespace App\Connectors\Meta\Instagram;

use App\Enums\ContentType;

/** Feed presets supported by the current Instagram media-container contract. */
final class InstagramPostOptions
{
    public const MAX_COLLABORATORS = 3;

    public static function supports(ContentType $type): bool
    {
        return in_array($type, [ContentType::InstagramImage, ContentType::InstagramReel, ContentType::InstagramCarousel], true);
    }

    public static function capabilities(): array
    {
        return ['max_collaborators' => self::MAX_COLLABORATORS, 'content_types' => ['instagram_image', 'instagram_reel', 'instagram_carousel'], 'ai_disclosure' => true];
    }

    public static function normalize(array $options): array
    {
        if (array_key_exists('is_ai_generated', $options) && in_array($options['is_ai_generated'], [0, 1, '0', '1'], true)) {
            $options['is_ai_generated'] = (bool) (int) $options['is_ai_generated'];
        }
        if (isset($options['collaborators']) && is_array($options['collaborators'])) {
            $options['collaborators'] = array_map(fn ($name) => is_string($name) ? mb_strtolower(preg_replace('/^@/', '', trim($name))) : $name, $options['collaborators']);
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

        return $params;
    }
}
