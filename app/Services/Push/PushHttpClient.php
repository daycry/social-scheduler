<?php

namespace App\Services\Push;

use App\Services\Media\UnsafeUrlException;
use App\Services\Media\UrlGuard;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Transporte PSR-18 síncrono con el mismo guard de URLs del proyecto. La respuesta remota se descarta sin leerla. */
final class PushHttpClient implements ClientInterface
{
    public function __construct(private readonly UrlGuard $guard) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = $this->guard->validate((string) $request->getUri());
        if ($url->scheme !== 'https') {
            throw new UnsafeUrlException('El receptor push debe usar HTTPS.');
        }
        $response = $this->guard->deliveryClient($url)->connectTimeout(3)->timeout(10)
            ->withHeaders($request->getHeaders())
            ->withBody((string) $request->getBody(), $request->getHeaderLine('Content-Type') ?: 'application/octet-stream')
            ->send($request->getMethod(), $url->url);

        return new Response($response->status());
    }
}
