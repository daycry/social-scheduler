<?php

namespace App\Support;

/**
 * URLs de destino de usuario para las que se acepta redirigir (SmartLinks): solo http/https con host, sin credenciales
 * incrustadas ni caracteres de control o de formato Unicode (Cc, Cf, Zl, Zp: saltos de linea, anchura cero, marcas bidi), sin
 * `%00` y con host sin mezcla de escrituras (homografos IDN: «аpple.com» con una «а» cirilica). Rechaza `javascript:`,
 * `data:`, `file:`, URLs relativas y `//host`.
 */
final class SafeUrl
{
    public static function isSafe(?string $url): bool
    {
        if ($url === null || $url === '' || strlen($url) > 2000) {
            return false;
        }

        // Caracteres de control o espacios (los navegadores los eliminan y reconstruyen otro esquema: "java\tscript:").
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }

        // Unicode: control (Cc), formato (Cf: ZWSP, ZWJ, RLO...), separadores de linea (Zl) y de parrafo (Zp). Un patron invalido
        // (UTF-8 mal formado) tambien se rechaza.
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $url) !== 0) {
            return false;
        }

        // `%00` (y su version en mayusculas/doble codificacion) en cualquier parte: trunca cadenas en codigo nativo.
        if (preg_match('/%(25)*00/i', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || $parts['host'] === '') {
            return false;
        }

        return self::hostIsSane((string) $parts['host']);
    }

    /** Host ASCII (o punycode que se decodifica a una sola escritura). Rechaza la mezcla de escrituras en una misma etiqueta. */
    private static function hostIsSane(string $host): bool
    {
        // Hosts literales IPv6 y direcciones IP: nada que normalizar.
        if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $unicode = $host;
        if (function_exists('idn_to_utf8') && str_contains(strtolower($host), 'xn--')) {
            $decoded = idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($decoded === false) {
                return false; // punycode invalido
            }
            $unicode = $decoded;
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7f]/', $unicode) === 1) {
            // Debe tener forma ASCII valida (UTS46); si no, el navegador y el servidor podrian entender hosts distintos.
            if (idn_to_ascii($unicode, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) === false) {
                return false;
            }
        }

        foreach (explode('.', $unicode) as $label) {
            if (self::scriptsIn($label) > 1) {
                return false;
            }
        }

        return true;
    }

    /** Cuantas escrituras distintas (latina, cirilica, griega...) hay en la etiqueta; digitos y guiones no cuentan. */
    private static function scriptsIn(string $label): int
    {
        $found = [];
        foreach (['Latin', 'Cyrillic', 'Greek', 'Armenian', 'Hebrew', 'Arabic', 'Han', 'Hiragana', 'Katakana', 'Hangul', 'Thai', 'Devanagari'] as $script) {
            if (preg_match('/\p{'.$script.'}/u', $label) === 1) {
                $found[$script] = true;
            }
        }

        // Han + Hiragana + Katakana conviven legitimamente en japones.
        if (isset($found['Han']) || isset($found['Hiragana']) || isset($found['Katakana'])) {
            $cjk = array_intersect_key($found, ['Han' => 1, 'Hiragana' => 1, 'Katakana' => 1]);
            $found = array_diff_key($found, $cjk) + ['CJK' => true];
        }

        return count($found);
    }
}
