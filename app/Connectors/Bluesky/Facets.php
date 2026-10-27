<?php

namespace App\Connectors\Bluesky;

use App\Support\Hashtags;
use App\Support\TextLength;
use Closure;

/**
 * *Facets* de un post de Bluesky (`app.bsky.richtext.facet`): enlaces, hashtags y menciones. Los indices son BYTES UTF-8
 * (`byteStart` inclusive, `byteEnd` exclusive), no caracteres ni grafemas: con emojis, `strlen` del prefijo es lo correcto.
 *
 *  - URL con esquema (http/https) -> `link`. Se recorta la puntuacion final ("mira https://a.com/b." no incluye el punto).
 *  - `#tag` -> `tag` (sin `#`, 64 grafemas como maximo; los formados solo por digitos se descartan).
 *  - `@handle` -> `mention` con el DID, SOLO si `$resolveHandle` lo resuelve; si no, queda como texto plano.
 */
final class Facets
{
    private const URL = '~(?<![\p{L}\p{N}_/@])https?://[^\s<>"\x{00A0}]+~u';

    private const MENTION = '~(?<![\p{L}\p{N}_@/.])@([a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+)~u';

    private const MAX_TAG_GRAPHEMES = 64;

    /**
     * @param  Closure(string): ?string  $resolveHandle  handle -> DID, o null si no existe.
     * @return list<array<string, mixed>> Ordenados por `byteStart`, sin solapes.
     */
    public static function build(string $text, Closure $resolveHandle): array
    {
        $facets = [];
        $taken = []; // rangos [start, end) ya cubiertos, para no solapar

        foreach (self::links($text) as [$start, $end, $uri]) {
            $facets[] = self::facet($start, $end, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => $uri]);
            $taken[] = [$start, $end];
        }

        foreach (Hashtags::find($text, false) as $hit) {
            if (self::overlaps($taken, $hit['start'], $hit['end']) || TextLength::count($hit['tag'], TextLength::GRAPHEMES) > self::MAX_TAG_GRAPHEMES) {
                continue;
            }
            $facets[] = self::facet($hit['start'], $hit['end'], ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $hit['tag']]);
            $taken[] = [$hit['start'], $hit['end']];
        }

        if (preg_match_all(self::MENTION, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $cache = [];
            foreach ($matches as $m) {
                [$full, $start] = $m[0];
                $end = $start + strlen($full);
                if (self::overlaps($taken, $start, $end)) {
                    continue;
                }
                $handle = strtolower($m[1][0]);
                $did = array_key_exists($handle, $cache) ? $cache[$handle] : ($cache[$handle] = $resolveHandle($handle));
                if ($did === null) {
                    continue; // mencion no resoluble: texto plano
                }
                $facets[] = self::facet($start, $end, ['$type' => 'app.bsky.richtext.facet#mention', 'did' => $did]);
                $taken[] = [$start, $end];
            }
        }

        usort($facets, fn (array $a, array $b) => $a['index']['byteStart'] <=> $b['index']['byteStart']);

        return $facets;
    }

    /** @return list<array{0: int, 1: int, 2: string}> [byteStart, byteEnd, uri] */
    private static function links(string $text): array
    {
        if (! preg_match_all(self::URL, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $out = [];
        foreach ($matches as $m) {
            [$url, $start] = $m[0];
            $url = self::trimTrailing($url);
            if ($url === '' || ! preg_match('~^https?://[^/\s]+\.[^/\s]+|^https?://localhost~i', $url)) {
                continue;
            }
            $out[] = [$start, $start + strlen($url), $url];
        }

        return $out;
    }

    /** Quita la puntuacion final que no forma parte de la URL (un `)` solo si no tiene su `(`). */
    private static function trimTrailing(string $url): string
    {
        while ($url !== '') {
            $last = substr($url, -1);
            if (str_contains('.,;:!?\'"*_~', $last)) {
                $url = substr($url, 0, -1);
            } elseif ($last === ')' && substr_count($url, ')') > substr_count($url, '(')) {
                $url = substr($url, 0, -1);
            } elseif ($last === ']' && substr_count($url, ']') > substr_count($url, '[')) {
                $url = substr($url, 0, -1);
            } else {
                break;
            }
        }

        return $url;
    }

    /** @param list<array{0: int, 1: int}> $taken */
    private static function overlaps(array $taken, int $start, int $end): bool
    {
        foreach ($taken as [$s, $e]) {
            if ($start < $e && $end > $s) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $feature
     * @return array<string, mixed>
     */
    private static function facet(int $start, int $end, array $feature): array
    {
        return ['index' => ['byteStart' => $start, 'byteEnd' => $end], 'features' => [$feature]];
    }
}
