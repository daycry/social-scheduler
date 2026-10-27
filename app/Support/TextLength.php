<?php

namespace App\Support;

/**
 * Longitud de un texto segun la regla de cada red (`text.count` en `config/media_specs.php`). Una sola implementacion en el
 * servidor; su espejo en el cliente es `resources/js/Composables/textLength.js`: si cambia una, cambia la otra.
 *
 *  - chars:     puntos de codigo Unicode (`mb_strlen`), como Instagram, Facebook, YouTube y TikTok.
 *  - graphemes: grafemas (clusters de usuario): un emoji compuesto o una bandera cuentan 1 (Bluesky).
 *  - threads:   1 por grafema; el grafema con un emoji cuenta sus bytes UTF-8 enteros (Threads, conservador). La documentacion (T-54) dice «los emojis cuentan el
 *               numero de bytes UTF-8»; la interpretacion para secuencias (ZWJ, banderas) se comprueba en el smoke (T-64, M-02).
 *
 * Se cuenta sobre el texto recortado, como hace `TrimStrings` en el servidor.
 */
final class TextLength
{
    public const CHARS = 'chars';

    public const GRAPHEMES = 'graphemes';

    public const THREADS = 'threads';

    /**
     * Secuencia de emoji (mismo patron, con la sintaxis de cada lenguaje, en `textLength.js`): indicadores regionales (bandera), keycap,
     * pictograma con VS16, tono de piel o etiquetas unido por ZWJ, y los modificadores sueltos. No usa `\X` ni `Intl.Segmenter`.
     */
    private const EMOJI_SEQUENCE = '/[\x{1F1E6}-\x{1F1FF}]{1,2}|[0-9#*]\x{FE0F}?\x{20E3}|\p{Extended_Pictographic}[\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{E0020}-\x{E007F}]*(?:\x{200D}\p{Extended_Pictographic}[\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{E0020}-\x{E007F}]*)*|[\x{200D}\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{20E3}]/u';

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

    /**
     * Conservador: cada secuencia de emoji (ver `EMOJI_SEQUENCE`) cuenta los bytes UTF-8 de la secuencia completa; fuera de los emojis
     * se cuentan code points (una marca combinante cuenta 1, como si fuera un caracter mas).
     */
    private static function threads(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $total = mb_strlen($text);
        if (preg_match_all(self::EMOJI_SEQUENCE, $text, $m)) {
            foreach ($m[0] as $sequence) {
                $total += strlen($sequence) - mb_strlen($sequence);
            }
        }

        return $total;
    }
}
