<?php

namespace App\Services\Media;

use InvalidArgumentException;

/**
 * Descarga del MP4 de un GIF de Giphy (C-04b). Comparte el pipeline de la URL (`UrlSource`: `UrlGuard`, IP fijada, `Range`, pasadas) y
 * ANADE una lista cerrada de hosts: la URL inicial y CADA redireccion deben ser https, puerto 443 y un host de Giphy permitido
 * (`social.giphy.media_host_pattern`). Asi el servidor no puede ser llevado a otro destino por una URL manipulada ni por un redirect.
 */
class GiphySource extends UrlSource
{
    public function __construct(UrlGuard $guard, string $url)
    {
        if (! GiphyClient::allowedMediaUrl($url)) {
            throw new InvalidArgumentException('Solo se pueden importar GIFs de Giphy.');
        }

        parent::__construct($guard, $url);
    }

    protected function assertAllowed(ValidatedUrl $v): void
    {
        if ($v->scheme !== 'https' || $v->port !== 443 || ! GiphyClient::allowedHost($v->host)) {
            throw new UnsafeUrlException('La descarga del GIF se ha redirigido fuera de Giphy.');
        }
    }

    public function name(): string
    {
        $id = preg_match('#^/media/(?:v1\.[^/]+/)?([A-Za-z0-9]+)/#', (string) parse_url($this->url, PHP_URL_PATH), $m) ? $m[1] : null;

        return $id !== null ? "giphy-$id.mp4" : 'giphy.mp4';
    }
}
