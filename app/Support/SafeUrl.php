<?php

namespace App\Support;

/**
 * URLs de destino de usuario para las que se acepta redirigir (SmartLinks): solo http/https con host, sin credenciales
 * incrustadas ni caracteres de control. Rechaza `javascript:`, `data:`, `file:`, URLs relativas y `//host`.
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

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        return ! isset($parts['user']) && ! isset($parts['pass']) && $parts['host'] !== '';
    }
}
