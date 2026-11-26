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
 *  - mastodon:  grafemas, con cada URL (`https?://...`) contada como la reserva de la instancia (`characters_reserved_per_url`, 23 por defecto) y una
 *               mencion remota `@usuario@dominio` como `@usuario`: asi cuenta Mastodon.
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

    public const MASTODON = 'mastodon';

    /** Reserva por URL de Mastodon cuando la instancia no dice otra (`characters_reserved_per_url`). */
    public const MASTODON_URL_LENGTH = 23;

    public const MODES = [self::CHARS, self::GRAPHEMES, self::THREADS, self::MASTODON];

    /** @param ?int $urlLength Solo en modo `mastodon`: lo que cuenta cada URL (null = 23). */
    public static function count(?string $text, string $mode = self::CHARS, ?int $urlLength = null): int
    {
        $text = trim((string) $text);

        return match ($mode) {
            self::GRAPHEMES => self::graphemes($text),
            self::THREADS => self::threads($text),
            self::MASTODON => self::mastodon($text, $urlLength ?? self::MASTODON_URL_LENGTH),
            default => mb_strlen($text),
        };
    }

    /**
     * Regla de Mastodon: cada URL se sustituye por tantos caracteres como reserva la instancia, una mencion remota se queda en `@usuario` y se cuentan grafemas.
     * Mismos patrones (con ASCII explicito en `\w`, igual en los dos lenguajes) que `countMastodon` de `textLength.js`.
     */
    private static function mastodon(string $text, int $urlLength): int
    {
        if ($text === '') {
            return 0;
        }

        // La puntuacion final (punto, coma, cierre de parentesis o comillas) no forma parte de la URL, como en twitter-text.
        $text = (string) preg_replace_callback('~https?://\S+?(?=[.,:;!?)\]"\'»]*(?:\s|$))~iu', fn () => str_repeat('x', max(0, $urlLength)), $text);
        $text = (string) preg_replace('/(?<![=\/A-Za-z0-9_])(@[A-Za-z0-9_]+(?:[A-Za-z0-9_.-]+[A-Za-z0-9_]+)?)@[A-Za-z0-9_.-]+[A-Za-z0-9_]/u', '$1', $text);

        return self::graphemes($text);
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
