<?php

namespace App\Services\Rss;

use App\Support\TextLength;

/**
 * Texto de una publicacion nacida de una entrada de feed: `titulo` + salto de linea + enlace, ajustado al limite del destino (T-88).
 * Lo que sobra se recorta del TITULO, por palabra y con «…», nunca del enlace; la longitud se cuenta como la cuenta la red (`text.count` y
 * `text.url_length` de `config/media_specs.php`: caracteres, grafemas, Threads o Mastodon con la URL a 23) y tambien se respeta `text.max_bytes`.
 * Si ni el enlace solo cabe, devuelve el texto completo (no cabe): la validacion de la publicacion lo rechazara y la franja quedara omitida CON su
 * motivo en `autolist_slots.skipped_reason`, no en silencio.
 */
final class RssText
{
    /**
     * @param  array{max?: int, count?: string, url_length?: ?int, max_bytes?: ?int}  $textSpec  La clave `text` de la regla del tipo de contenido.
     */
    public static function fit(string $title, ?string $link, array $textSpec): string
    {
        $compose = fn (string $t): string => $link === null ? $t : ($t === '' ? $link : $t."\n".$link);
        $fits = static function (string $text) use ($textSpec): bool {
            $max = (int) ($textSpec['max'] ?? PHP_INT_MAX);
            $bytes = $textSpec['max_bytes'] ?? null;

            return TextLength::count($text, (string) ($textSpec['count'] ?? TextLength::CHARS), $textSpec['url_length'] ?? null) <= $max
                && ($bytes === null || strlen(trim($text)) <= (int) $bytes);
        };

        $full = $compose($title);
        if ($fits($full)) {
            return $full;
        }

        // Por palabras, quitando desde el final.
        $words = preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        for ($n = count($words) - 1; $n >= 1; $n--) {
            $candidate = $compose(implode(' ', array_slice($words, 0, $n)).'…');
            if ($fits($candidate)) {
                return $candidate;
            }
        }

        // Una sola palabra enorme: por grafemas (un emoji compuesto no se parte).
        $graphemes = self::graphemes((string) ($words[0] ?? ''));
        for ($n = count($graphemes) - 1; $n >= 1; $n--) {
            $candidate = $compose(implode('', array_slice($graphemes, 0, $n)).'…');
            if ($fits($candidate)) {
                return $candidate;
            }
        }

        $linkOnly = $compose('');

        return $fits($linkOnly) ? $linkOnly : $full;
    }

    /**
     * Grafemas del texto: con intl, por `IntlBreakIterator` (como `TextLength`); sin intl, `\X` de PCRE (que puede agrupar emojis seguidos).
     *
     * @return list<string>
     */
    private static function graphemes(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (class_exists(\IntlBreakIterator::class)) {
            $out = [];
            $iterator = \IntlBreakIterator::createCharacterInstance('en');
            $iterator->setText($text);
            $start = 0;
            foreach ($iterator as $end) {
                if ($end > $start) {
                    $out[] = substr($text, $start, $end - $start);
                }
                $start = $end;
            }
            if ($out !== []) {
                return $out;
            }
        }
        preg_match_all('/\X/u', $text, $m);

        return $m[0];
    }
}
