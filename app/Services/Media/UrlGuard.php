<?php

namespace App\Services\Media;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Guardia SSRF (CWE-918) para las descargas remotas. Una URL solo se pide si:
 *  - es `http`/`https`, sin credenciales ni caracteres de control, y su puerto es 80 o 443;
 *  - el host no es `localhost` ni un literal numerico raro (decimal, octal, hexadecimal, abreviado);
 *  - TODAS las IPs a las que resuelve (A y AAAA) son publicas: sin privadas, reservadas, link-local, metadatos ni IPv4
 *    incrustada en IPv6 (`::ffff:0:0/96`, `64:ff9b::/96`, `2002::/16`).
 * La conexion se fija a la IP validada (`CURLOPT_RESOLVE`) para que un DNS que cambie entre la comprobacion y la conexion
 * (rebinding) no pueda llevarla a otra direccion, y las redirecciones se siguen a mano revalidando cada `Location`.
 */
class UrlGuard
{
    public const MAX_REDIRECTS = 5;

    /** @var list<array{string, int}> Rangos IPv4 bloqueados (red, prefijo). */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
        ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15],
        ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    /** @var list<array{string, int}> Rangos IPv6 bloqueados dentro de 2000::/3 (el resto del espacio no es unicast global). */
    private const BLOCKED_V6 = [
        ['2001::', 32],     // Teredo
        ['2001:2::', 48],   // benchmarking
        ['2001:10::', 28],  // ORCHID
        ['2001:db8::', 32], // documentacion
    ];

    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver  Resuelve un host a sus IPs; inyectable en los tests. */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::dnsResolver(...);
    }

    /**
     * @throws UnsafeUrlException
     */
    public function validate(string $url): ValidatedUrl
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new UnsafeUrlException('La URL no es valida.');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrlException('La URL no es valida.');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException('Solo se admiten URLs http o https.');
        }

        $authority = preg_match('#^[a-z][a-z0-9+.-]*://([^/?\#]*)#i', $url, $m) ? $m[1] : '';

        if (isset($parts['user']) || isset($parts['pass']) || str_contains($authority, '@')) {
            throw new UnsafeUrlException('La URL no puede llevar usuario ni contrasena.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, [80, 443], true)) {
            throw new UnsafeUrlException('Solo se admiten los puertos 80 y 443.');
        }

        $host = $this->normalizeHost($parts['host']);
        $ips = $this->resolve($host);

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new UnsafeUrlException('La URL apunta a una direccion no permitida.');
            }
        }

        return new ValidatedUrl($url, $scheme, $host, $port, $ips[0], $ips);
    }

    /**
     * Opciones de cURL (clave `curl` de Guzzle) para una peticion ya validada: IP fijada, solo HTTP/HTTPS (tambien en
     * redirecciones), sin proxy y sin seguir redirecciones por cuenta de cURL.
     *
     * @return array<int, mixed>
     */
    public function curlOptions(ValidatedUrl $v): array
    {
        $ip = str_contains($v->ip, ':') ? '['.$v->ip.']' : $v->ip;
        $options = [
            CURLOPT_RESOLVE => ["{$v->host}:{$v->port}:{$ip}"],
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        return $options;
    }

    /** Cliente HTTP para UNA peticion validada: IP fijada, sin redirecciones automaticas y sin proxy. */
    public function client(ValidatedUrl $v): PendingRequest
    {
        return Http::withoutRedirecting()->withOptions(['proxy' => '', 'curl' => $this->curlOptions($v)]);
    }

    /**
     * Pide `$url` con `$send` y sigue a mano hasta 5 redirecciones: cada destino se valida de nuevo (DNS incluido) ANTES
     * de pedirlo, asi que una redireccion a una IP interna nunca llega a hacerse.
     *
     * @param  Closure(ValidatedUrl, array<int, mixed>): Response  $send  Hace UNA peticion sin seguir redirecciones.
     *
     * @throws UnsafeUrlException
     */
    public function follow(string $url, Closure $send): Response
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $validated = $this->validate($url);
            $response = $send($validated, $this->curlOptions($validated));

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true) || ! filled($location = $response->header('Location'))) {
                return $response;
            }

            $url = self::resolveLocation($validated->url, $location);
        }

        throw new UnsafeUrlException('La URL redirige demasiadas veces.');
    }

    /** ¿Es una direccion IP publica y alcanzable (ni privada, ni reservada, ni de bucle, ni de metadatos)? */
    public static function isPublicIp(string $ip): bool
    {
        $bin = filter_var($ip, FILTER_VALIDATE_IP) !== false ? @inet_pton($ip) : false;

        if ($bin === false) {
            return false;
        }

        return strlen($bin) === 4 ? self::publicV4($bin) : self::publicV6($bin);
    }

    private static function publicV4(string $bin): bool
    {
        foreach (self::BLOCKED_V4 as [$net, $prefix]) {
            if (self::inRange($bin, (string) inet_pton($net), $prefix)) {
                return false;
            }
        }

        return filter_var(inet_ntop($bin), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function publicV6(string $bin): bool
    {
        // IPv4 incrustada: se juzga la IPv4, nunca el envoltorio (::ffff:7f00:1 es loopback).
        if (self::inRange($bin, (string) inet_pton('::ffff:0:0'), 96) || self::inRange($bin, (string) inet_pton('64:ff9b::'), 96)) {
            return self::publicV4(substr($bin, 12, 4));
        }

        if (self::inRange($bin, (string) inet_pton('2002::'), 16)) {
            return self::publicV4(substr($bin, 2, 4));
        }

        // Solo el unicast global (2000::/3): fuera quedan ::, ::1, ::/96 (IPv4-compatible), fc00::/7, fe80::/10, fec0::/10, ff00::/8...
        if (! self::inRange($bin, (string) inet_pton('2000::'), 3)) {
            return false;
        }

        foreach (self::BLOCKED_V6 as [$net, $prefix]) {
            if (self::inRange($bin, (string) inet_pton($net), $prefix)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $bin, string $net, int $prefix): bool
    {
        $bytes = intdiv($prefix, 8);

        if (substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }

        $bits = $prefix % 8;

        if ($bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(rtrim(trim($host, '[]'), '.'));

        if ($host === '') {
            throw new UnsafeUrlException('La URL no es valida.');
        }

        if (! preg_match('/^[\x20-\x7e]+$/', $host)) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) : false;

            if ($ascii === false || $ascii === '') {
                throw new UnsafeUrlException('El dominio de la URL no es valido.');
            }

            $host = strtolower($ascii);
        }

        return $host;
    }

    /**
     * @return non-empty-list<string>
     */
    private function resolve(string $host): array
    {
        // Literal de IP: se juzga tal cual, sin DNS. Cualquier otra forma numerica (decimal, octal, hex, abreviada) se rechaza.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || self::looksNumeric($host)) {
            throw new UnsafeUrlException('La URL apunta a una direccion no permitida.');
        }

        $ips = array_values(array_unique(array_filter(($this->resolver)($host), 'is_string')));

        if ($ips === []) {
            throw new UnsafeUrlException('No se pudo resolver el dominio de la URL.');
        }

        return $ips;
    }

    /** Un nombre cuya ultima etiqueta es numerica (o 0x..) no es un dominio: es una IP escrita de forma ambigua. */
    private static function looksNumeric(string $host): bool
    {
        $last = substr($host, (int) strrpos('.'.$host, '.'));

        return (bool) preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', $last);
    }

    /** @return list<string> */
    private static function dnsResolver(string $host): array
    {
        $ips = [];

        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $key) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                if (isset($record[$key])) {
                    $ips[] = (string) $record[$key];
                }
            }
        }

        return $ips;
    }

    /** Resuelve el `Location` de una redireccion contra la URL que la devolvio. */
    private static function resolveLocation(string $base, string $location): string
    {
        $location = trim($location);

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }

        $b = parse_url($base);
        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');

        if (str_starts_with($location, '//')) {
            return $b['scheme'].':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = rtrim(substr($b['path'] ?? '/', 0, strrpos($b['path'] ?? '/', '/') + 1), '/');

        return $origin.$dir.'/'.$location;
    }
}
