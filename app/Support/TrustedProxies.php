<?php

namespace App\Support;

/** Interpreta la variable de entorno TRUSTED_PROXIES para el middleware de proxies de confianza. */
class TrustedProxies
{
    /** @return string|list<string>|null `'*'`, una lista de IPs/CIDR o null (no confiar en ninguno). */
    public static function parse(?string $value): string|array|null
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($value === '*' || $value === '**') {
            return $value;
        }

        $list = array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v) => $v !== ''));

        return $list === [] ? null : $list;
    }
}
