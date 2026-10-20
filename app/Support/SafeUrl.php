<?php

namespace App\Support;

/**
 * URLs de destino de usuario para las que se acepta redirigir (SmartLinks): solo http/https con host, sin credenciales
 * incrustadas ni caracteres de control o de formato Unicode (Cc, Cf, Zl, Zp: saltos de linea, anchura cero, marcas bidi), sin
 * `%00` y con host sin mezcla de escrituras (homografos IDN: «аpple.com» con una «а» cirilica). Rechaza `javascript:`,
 * `data:`, `file:`, URLs relativas y `//host`.
 *
 * Matices: el ZWJ (U+200D, emojis compuestos) se admite SOLO en ruta/query/fragmento, nunca en el host (los selectores de variacion
 * son marcas Mn y pasan). `%2500` es un `%` literal seguido de `00`, no un byte nulo. La mezcla de escrituras en una etiqueta del host
 * sigue la regla «highly restrictive» de UTS #39: se permite Latin+Han+Hiragana+Katakana, Latin+Han+Bopomofo y Latin+Han+Hangul; se
 * rechazan las demas (Latin+Cirilico...). LIMITE CONOCIDO: un homografo de UNA sola escritura (todo cirilico, «аррӏе.com») no se bloquea
 * aqui; la pagina publica de SmartLinks muestra bajo cada boton el dominio real de destino para que el visitante lo vea.
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

        // Unicode: control (Cc), separadores de linea (Zl) y de parrafo (Zp) en cualquier parte. Un patron invalido (UTF-8 mal formado)
        // tambien se rechaza.
        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $url) !== 0) {
            return false;
        }

        // Formato (Cf: ZWSP, RLO...): prohibido en esquema y host; en ruta/query/fragmento solo se admite el ZWJ (emojis compuestos).
        $rest = $url;
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://[^/?\#]*#', $url, $m) === 1) {
            if (preg_match('/\p{Cf}/u', $m[0]) !== 0) {
                return false;
            }
            $rest = substr($url, strlen($m[0]));
        }
        if (preg_match('/[^\x{200D}\P{Cf}]/u', $rest) !== 0) {
            return false;
        }

        // Byte nulo codificado `%00` (`%2500` es un `%` literal + `00`, no un nulo).
        if (stripos($url, '%00') !== false) {
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

    /**
     * Host de destino para MOSTRAR al visitante: en forma ASCII (punycode si es IDN), de modo que un homografo («xn--pple-43d.com»)
     * se vea distinto del dominio legitimo. Vacio si la URL no tiene host.
     */
    public static function displayHost(?string $url): string
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        if ($host !== '' && function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7f]/', $host) === 1) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }

        return $host;
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
            if (! self::labelIsSane($label)) {
                return false;
            }
        }

        return true;
    }

    /** Escrituras permitidas en una misma etiqueta (UTS #39, «highly restrictive»); cualquier otra mezcla se rechaza. */
    private const ALLOWED_MIXES = [
        ['Latin', 'Han', 'Hiragana', 'Katakana'],
        ['Latin', 'Han', 'Bopomofo'],
        ['Latin', 'Han', 'Hangul'],
    ];

    /** ¿La etiqueta usa una sola escritura, o una combinacion permitida? Digitos y guiones (Common) no cuentan. */
    private static function labelIsSane(string $label): bool
    {
        $found = [];
        foreach (['Latin', 'Cyrillic', 'Greek', 'Armenian', 'Hebrew', 'Arabic', 'Han', 'Hiragana', 'Katakana', 'Bopomofo', 'Hangul', 'Thai', 'Devanagari'] as $script) {
            if (preg_match('/\p{'.$script.'}/u', $label) === 1) {
                $found[] = $script;
            }
        }
        if (count($found) <= 1) {
            return true;
        }
        foreach (self::ALLOWED_MIXES as $mix) {
            if (array_diff($found, $mix) === []) {
                return true;
            }
        }

        return false;
    }
}
