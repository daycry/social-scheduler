<?php

namespace App\Support;

/**
 * Texto enriquecido de los sitios (T-15) con un subconjunto propio de marcado, NUNCA HTML de usuario: el texto se escapa entero
 * y solo despues se generan las etiquetas permitidas (`<p>`, `<ul>/<li>`, `<strong>`, `<em>`, `<a>`). Parrafos = lineas en
 * blanco; `- ` abre una lista; `**negrita**`, `*cursiva*` y `[texto](https://url)`. Los enlaces pasan por `SafeUrl` y llevan
 * `rel="nofollow noopener noreferrer ugc"`; un enlace no seguro se pinta como texto plano.
 */
final class SiteRichText
{
    public const MAX = 5000;

    /** Texto guardado: sin etiquetas ni caracteres de control/formato (se conservan los saltos de linea). */
    public static function clean(mixed $value, int $max = self::MAX): string
    {
        $text = (string) $value; // sin strip_tags: todo se escapa al pintar y 1 <2 y 3> 0 es texto legitimo
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);
        $text = (string) preg_replace('/[^\P{Cc}\n]|[\p{Cf}\p{Zl}\p{Zp}]/u', '', $text);
        $text = (string) preg_replace("/[ \t]+/u", ' ', $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return mb_substr(trim($text), 0, $max);
    }

    /** HTML seguro (ya escapado) del texto guardado. */
    public static function render(string $text): string
    {
        $out = '';
        foreach (preg_split("/\n{2,}/", self::clean($text)) ?: [] as $chunk) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $chunk)), fn ($l) => $l !== ''));
            if ($lines === []) {
                continue;
            }
            if (array_reduce($lines, fn ($all, $l) => $all && str_starts_with($l, '- '), true)) {
                $out .= '<ul>'.implode('', array_map(fn ($l) => '<li>'.self::inline(substr($l, 2)).'</li>', $lines)).'</ul>';
            } else {
                $out .= '<p>'.implode('<br>', array_map(fn ($l) => self::inline($l), $lines)).'</p>';
            }
        }

        return $out;
    }

    private static function inline(string $line): string
    {
        $parts = preg_split('/(\[[^\]\n]{1,100}\]\(https?:\/\/[^\s)]{1,500}\))/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $html = '';
        foreach ($parts as $part) {
            if (preg_match('/^\[([^\]\n]{1,100})\]\((https?:\/\/[^\s)]{1,500})\)$/u', $part, $m) === 1) {
                $html .= SafeUrl::isSafe($m[2])
                    ? '<a href="'.e($m[2]).'" rel="nofollow noopener noreferrer ugc" target="_blank">'.self::emphasis(e($m[1])).'</a>'
                    : self::emphasis(e($m[1]));
            } else {
                $html .= self::emphasis(e($part));
            }
        }

        return $html;
    }

    private static function emphasis(string $escaped): string
    {
        $escaped = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped);

        return (string) preg_replace('/\*(?!\s)(.+?)(?<!\s)\*/u', '<em>$1</em>', $escaped);
    }
}
