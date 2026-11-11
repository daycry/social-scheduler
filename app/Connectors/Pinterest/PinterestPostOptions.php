<?php

namespace App\Connectors\Pinterest;

use App\Enums\ContentType;
use App\Models\SocialAccount;
use App\Support\SafeUrl;

/**
 * Opciones de Pinterest (T-18): `pinterest_board_id` (OBLIGATORIA: todo pin vive en un tablero), `pinterest_title` (hasta 100) y
 * `pinterest_link` (enlace de destino https, hasta 2048). La descripcion es el texto del destino (hasta 800) y el alt viene de la media.
 */
final class PinterestPostOptions
{
    public static function capabilities(SocialAccount $account): array
    {
        return [
            'boards' => array_values((array) ($account->meta['boards'] ?? [])),
            'board_required' => true,
            'title_max' => (int) config('social.pinterest.title_max', 100),
            'description_max' => (int) config('social.pinterest.description_max', 800),
            'link' => true,
            'alt_text' => true,
            'cover' => true,
        ];
    }

    /** @return list<string> */
    public static function errors(array $options, ContentType $type): array
    {
        $errors = [];
        $asked = fn (string $k) => array_key_exists($k, $options) && ! in_array($options[$k], [null, false, [], ''], true);

        if ($type->network()->value !== 'pinterest') {
            foreach (['pinterest_board_id', 'pinterest_title', 'pinterest_link'] as $k) {
                if ($asked($k)) {
                    $errors[] = "La opcion $k solo existe en Pinterest.";
                }
            }

            return $errors;
        }

        $board = $options['pinterest_board_id'] ?? null;
        if (! is_string($board) && ! is_int($board) || preg_match('/^\d{1,30}$/D', (string) $board) !== 1) {
            $errors[] = 'Elige el tablero de Pinterest (pinterest_board_id): todo pin necesita uno.';
        }

        $max = (int) config('social.pinterest.title_max', 100);
        if ($asked('pinterest_title') && (! is_string($options['pinterest_title']) || mb_strlen($options['pinterest_title']) > $max)) {
            $errors[] = "El titulo del pin debe ser texto de hasta $max caracteres.";
        }

        if ($asked('pinterest_link')) {
            $link = $options['pinterest_link'];
            if (! is_string($link) || mb_strlen($link) > (int) config('social.pinterest.link_max', 2048) || ! str_starts_with(strtolower($link), 'https://') || ! SafeUrl::isSafe($link)) {
                $errors[] = 'El enlace del pin debe ser una URL https valida y publica.';
            }
        }

        return $errors;
    }

    /** Titulo efectivo: la opcion o la primera linea del texto (recortada). */
    public static function title(array $options, ?string $text): ?string
    {
        $title = trim((string) ($options['pinterest_title'] ?? ''));
        if ($title === '') {
            $title = trim(strtok(str_replace("\r", '', (string) $text), "\n") ?: '');
        }

        return $title === '' ? null : mb_substr($title, 0, (int) config('social.pinterest.title_max', 100));
    }
}
