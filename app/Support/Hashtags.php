<?php

namespace App\Support;

/**
 * Extraccion de hashtags, compartida por los *facets* de Bluesky y las sugerencias de hashtags (C-04a). Es la MISMA regla que
 * `MediaValidator::countHashtags()` y `resources/js/Composables/textCount.js`: un `#` seguido de letras, numeros o guion bajo
 * (Unicode) y NO precedido de letra, numero, guion bajo o `/` (`a#b` y los `#` de una URL no cuentan).
 *
 * Las posiciones son BYTES UTF-8 (lo que exigen los *facets* de atproto: `byteStart`/`byteEnd`).
 */
final class Hashtags
{
    private const PATTERN = '/(?<![\p{L}\p{N}_\/])#([\p{L}\p{N}_]+)/u';

    /**
     * @param  bool  $numeric  false = descarta los hashtags formados solo por digitos (`#2024`), como hacen Bluesky y Threads.
     * @return list<array{tag: string, start: int, end: int}> `tag` sin `#`; `start`/`end` en bytes (el `#` incluido).
     */
    public static function find(string $text, bool $numeric = true): array
    {
        if (! preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $found = [];
        foreach ($matches as $m) {
            [$full, $start] = $m[0];
            $tag = $m[1][0];
            if (! $numeric && ctype_digit($tag)) {
                continue;
            }
            $found[] = ['tag' => $tag, 'start' => $start, 'end' => $start + strlen($full)];
        }

        return $found;
    }

    /** @return list<string> Tags (sin `#`) en orden de aparicion, sin repetir (sin distinguir mayusculas). */
    public static function tags(string $text, bool $numeric = true): array
    {
        $seen = [];
        foreach (self::find($text, $numeric) as $hit) {
            $seen[mb_strtolower($hit['tag'])] ??= $hit['tag'];
        }

        return array_values($seen);
    }
}
