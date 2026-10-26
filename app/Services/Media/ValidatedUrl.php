<?php

namespace App\Services\Media;

/** URL ya validada por `UrlGuard`: `ip` es la direccion publica a la que hay que fijar la conexion. */
final class ValidatedUrl
{
    /**
     * @param  list<string>  $ips  Todas las IPs a las que resolvio el host (todas publicas).
     */
    public function __construct(
        public readonly string $url,
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
        public readonly array $ips,
    ) {}
}
