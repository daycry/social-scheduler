<?php

namespace App\Connectors\Meta\Facebook;

use App\Enums\ContentType;

final class FacebookPostOptions
{
    public static function errors(array $options, ContentType $type): array
    {
        $title = $options['facebook_title'] ?? '';
        if ($title === '') {
            return [];
        }
        if (! in_array($type, [ContentType::FacebookReel, ContentType::FacebookVideo], true)) {
            return ['El título de Facebook solo está disponible para sus vídeos y Reels.'];
        }

        return is_string($title) && mb_strlen($title) <= 255 ? [] : ['El título de Facebook debe ser texto de hasta 255 caracteres.'];
    }

    public static function params(array $options): array
    {
        return filled($options['facebook_title'] ?? null) ? ['title' => $options['facebook_title']] : [];
    }
}
