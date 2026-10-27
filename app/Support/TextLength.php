<?php

namespace App\Support;

/**
 * Longitud de un texto segun la regla de cada red (`text.count` en `config/media_specs.php`). Una sola implementacion en el
 * servidor; su espejo en el cliente es `resources/js/Composables/textLength.js`: si cambia una, cambia la otra.
 *
 *  - chars:     puntos de codigo Unicode (`mb_strlen`), como Instagram, Facebook, YouTube y TikTok.
 *  - graphemes: grafemas (clusters de usuario): un emoji compuesto o una bandera cuentan 1 (Bluesky).
 *  - threads:   puntos de codigo, pero cada emoji cuenta sus bytes UTF-8 (Threads) ⚠️ regla por confirmar en T-54.
 *
 * Se cuenta sobre el texto recortado, como hace `TrimStrings` en el servidor.
 */
final class TextLength
{
    public const CHARS = 'chars';

    public const GRAPHEMES = 'graphemes';

    public const THREADS = 'threads';

    public const MODES = [self::CHARS, self::GRAPHEMES, self::THREADS];

    public static function count(?string $text, string $mode = self::CHARS): int
    {
        $text = trim((string) $text);

        return match ($mode) {
            self::GRAPHEMES => self::graphemes($text),
            self::THREADS => self::threads($text),
            default => mb_strlen($text),
        };
    }

    private static function graphemes(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        if (function_exists('grapheme_strlen') && ($n = grapheme_strlen($text)) !== false && $n !== null) {
            return $n;
        }

        // Sin intl: `\X` de PCRE (cluster de grafemas extendido) da lo mismo en la practica.
        return (int) preg_match_all('/\X/u', $text);
    }

    private static function threads(string $text): int
    {
        $extra = 0;
        if (preg_match_all('/\p{Extended_Pictographic}/u', $text, $m)) {
            foreach ($m[0] as $emoji) {
                $extra += strlen($emoji) - 1;
            }
        }

        return mb_strlen($text) + $extra;
    }
}
