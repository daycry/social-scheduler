<?php

namespace App\Connectors\Meta\Facebook;

use App\Enums\ContentType;

final class FacebookPostOptions
{
    /** Tipos que admiten `place` (`/{page}/feed` y `/{page}/photos`); los videos, Reels e historias no lo documentan. */
    private const PLACE_TYPES = [ContentType::FacebookText, ContentType::FacebookPhoto, ContentType::FacebookPhotos];

    public static function normalize(array $options): array
    {
        if (isset($options['place']) && is_int($options['place'])) {
            $options['place'] = (string) $options['place'];
        }

        return $options;
    }

    public static function errors(array $options, ContentType $type): array
    {
        $errors = [];
        $title = $options['facebook_title'] ?? '';
        if ($title !== '') {
            if (! in_array($type, [ContentType::FacebookReel, ContentType::FacebookVideo], true)) {
                $errors[] = 'El título de Facebook solo está disponible para sus vídeos y Reels.';
            } elseif (! is_string($title) || mb_strlen($title) > 255) {
                $errors[] = 'El título de Facebook debe ser texto de hasta 255 caracteres.';
            }
        }

        $place = $options['place'] ?? null;
        if (! in_array($place, [null, '', false], true)) {
            if (! in_array($type, self::PLACE_TYPES, true)) {
                $errors[] = 'El lugar de Facebook solo está disponible en publicaciones de texto y de fotos.';
            } elseif (! is_string($place) || preg_match('/^\d{1,20}$/D', $place) !== 1) {
                $errors[] = 'El lugar de Facebook debe ser el id numérico de una página de lugar (place).';
            }
        }

        return $errors;
    }

    public static function params(array $options): array
    {
        return filled($options['facebook_title'] ?? null) ? ['title' => $options['facebook_title']] : [];
    }

    /** `place` de `/{page}/feed` y `/{page}/photos`: id de la Pagina del lugar. */
    public static function placeParams(array $options): array
    {
        return filled($options['place'] ?? null) ? ['place' => (string) $options['place']] : [];
    }
}
