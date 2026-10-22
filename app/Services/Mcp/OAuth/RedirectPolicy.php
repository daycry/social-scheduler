<?php

namespace App\Services\Mcp\OAuth;

/**
 * Politica de redirect_uri de los clientes OAuth del MCP (RFC 8252 + OAuth 2.1).
 * Permitidos: loopback http (localhost, 127.0.0.1, [::1], cualquier puerto), los callbacks de Claude y los prefijos
 * https:// de `services.mcp.oauth.redirect_allowed`. Siempre sin userinfo ni fragmento y comparando el host EXACTO.
 */
class RedirectPolicy
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    private const CLAUDE_CALLBACKS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];

    /** @param  list<string>|null  $allowedPrefixes  null = los de la configuracion */
    public function __construct(private ?array $allowedPrefixes = null) {}

    public function isAllowed(string $uri): bool
    {
        $parts = $this->parse($uri);
        if ($parts === null) {
            return false;
        }

        if ($parts['scheme'] === 'http') {
            return in_array($parts['host'], self::LOOPBACK_HOSTS, true);
        }

        if (in_array($uri, self::CLAUDE_CALLBACKS, true)) {
            return true;
        }

        foreach ($this->prefixes() as $prefix) {
            if ($this->matchesPrefix($parts, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Corresponde `$requested` a alguno de los `$registered`? Coincidencia exacta; en loopback el puerto puede variar
     * (RFC 8252 7.3) pero esquema, host y ruta deben ser iguales.
     *
     * @param  list<string>  $registered
     */
    public function matchesRegistered(array $registered, string $requested): bool
    {
        if (in_array($requested, $registered, true)) {
            return true;
        }

        $req = $this->parse($requested);
        if ($req === null || $req['scheme'] !== 'http' || ! in_array($req['host'], self::LOOPBACK_HOSTS, true)) {
            return false;
        }

        foreach ($registered as $uri) {
            $reg = $this->parse($uri);
            if ($reg !== null && $reg['scheme'] === 'http' && $reg['host'] === $req['host'] && $reg['path'] === $req['path'] && $reg['query'] === $req['query']) {
                return true;
            }
        }

        return false;
    }

    /** @return array{scheme: string, host: string, path: string, query: string, port: ?int}|null */
    private function parse(string $uri): ?array
    {
        if ($uri === '' || strlen($uri) > 2000 || preg_match('/[\x00-\x20\x7f\\\\]/', $uri)) {
            return null;
        }

        $p = parse_url($uri);
        if ($p === false || ! isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass']) || str_contains($uri, '#')) {
            return null;
        }

        // Sin separadores ni puntos codificados (%2f, %5c, %2e, %25 en cualquier caja: el servidor de destino puede
        // decodificarlos y normalizar la ruta) ni segmentos `.`/`..`: permiten escapar del prefijo permitido.
        if (preg_match('/%(2f|5c|2e|25)/i', $p['path'] ?? '')) {
            return null;
        }
        foreach (explode('/', $p['path'] ?? '') as $seg) {
            if ($seg === '.' || $seg === '..') {
                return null;
            }
        }

        $scheme = strtolower($p['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return [
            'scheme' => $scheme,
            'host' => strtolower($p['host']),
            'path' => $p['path'] ?? '',
            'query' => $p['query'] ?? '',
            'port' => $p['port'] ?? null,
        ];
    }

    /** @param  array{scheme: string, host: string, path: string, query: string, port: ?int}  $parts */
    private function matchesPrefix(array $parts, string $prefix): bool
    {
        $pre = $this->parse($prefix);
        if ($pre === null || $pre['scheme'] !== 'https' || $parts['scheme'] !== 'https') {
            return false;
        }

        if ($pre['host'] !== $parts['host'] || $pre['port'] !== $parts['port']) {
            return false;
        }

        $base = rtrim($pre['path'], '/');

        return $base === '' || $parts['path'] === $base || str_starts_with($parts['path'], $base.'/');
    }

    /** @return list<string> */
    private function prefixes(): array
    {
        return array_values(array_filter($this->allowedPrefixes ?? (array) config('services.mcp.oauth.redirect_allowed', []), fn ($p) => is_string($p) && str_starts_with($p, 'https://')));
    }
}
