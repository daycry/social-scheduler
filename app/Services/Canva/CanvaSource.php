<?php

namespace App\Services\Canva;

use App\Services\Media\UnsafeUrlException;
use App\Services\Media\UrlGuard;
use App\Services\Media\UrlSource;
use App\Services\Media\ValidatedUrl;
use InvalidArgumentException;

/**
 * Descarga de un fichero exportado por Canva (T-16). Comparte el pipeline de `UrlSource` (`UrlGuard`, IP fijada, tope de bytes, tipo real) y
 * AÑADE una lista cerrada: la URL inicial y CADA redireccion deben ser https, puerto 443 y un host de canva.com. La URL de Canva caduca a las
 * 24 h y puede llevar una firma en la query: `reference()` la guarda sin query.
 */
class CanvaSource extends UrlSource
{
    public function __construct(UrlGuard $guard, string $url, private readonly string $label = 'canva')
    {
        if (! self::allowedUrl($url)) {
            throw new InvalidArgumentException('Solo se pueden importar ficheros exportados por Canva.');
        }

        parent::__construct($guard, $url);
    }

    public static function allowedUrl(string $url): bool
    {
        $p = parse_url($url);

        return is_array($p) && ($p['scheme'] ?? '') === 'https' && (($p['port'] ?? 443) === 443) && ! isset($p['user'], $p['pass'])
            && self::allowedHost((string) ($p['host'] ?? ''));
    }

    public static function allowedHost(string $host): bool
    {
        return (bool) preg_match((string) config('services.canva.download_host_pattern'), strtolower($host));
    }

    protected function assertAllowed(ValidatedUrl $v): void
    {
        if ($v->scheme !== 'https' || $v->port !== 443 || ! self::allowedHost($v->host)) {
            throw new UnsafeUrlException('La descarga de Canva se ha redirigido fuera de Canva.');
        }
    }

    public function name(): string
    {
        $ext = strtolower(pathinfo((string) parse_url($this->url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return $this->label.($ext !== '' && strlen($ext) <= 5 ? '.'.preg_replace('/[^a-z0-9]/', '', $ext) : '');
    }
}
