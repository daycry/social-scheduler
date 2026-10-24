<?php

namespace App\Services\Media;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/** Descarga de una URL publica. Toda peticion (y cada redireccion) pasa por `UrlGuard` y va fijada a la IP validada. */
class UrlSource implements RemoteSource
{
    public function __construct(private readonly UrlGuard $guard, private readonly string $url) {}

    public function fetch($sink, int $offset, ?string $validator, Closure $policy, int $cap, float $deadline): RemotePass
    {
        $meta = null;

        // Cabeceras de la respuesta: en el cliente real se leen antes del cuerpo (corta una descarga que no vale).
        $read = function (ResponseInterface $r) use (&$meta, &$cap, $offset, $policy): void {
            $meta = $this->readHeaders($r, $offset, $policy);
            $cap = $meta['cap'] ?? $cap;
        };

        $headers = ['Range' => "bytes=$offset-", 'Accept-Encoding' => 'identity', 'User-Agent' => 'SocialScheduler/1.0 (media-import)'];

        if ($offset > 0 && $validator !== null) {
            $headers['If-Range'] = $validator;
        }

        $aborted = false;
        $response = null;

        try {
            $response = $this->guard->follow($this->url, function (ValidatedUrl $v, array $curl) use ($sink, $offset, $headers, $deadline, &$cap, $read, &$meta) {
                // Un salto anterior (redireccion, error) pudo dejar su cuerpo en el sink: se vuelve al punto de control.
                ftruncate($sink, $offset);
                fseek($sink, $offset);
                $meta = null;

                $curl[CURLOPT_NOPROGRESS] = false;
                $curl[CURLOPT_PROGRESSFUNCTION] = function ($ch, $dlTotal, $dlNow) use (&$cap, $offset, $deadline) {
                    return ($dlNow > $cap - $offset || microtime(true) >= $deadline) ? 1 : 0;
                };

                return $this->guard->client($v)
                    ->withHeaders($headers)
                    ->withOptions([
                        'curl' => $curl,
                        'sink' => $sink,
                        'decode_content' => false,
                        'connect_timeout' => 10,
                        'timeout' => max(5.0, $deadline - microtime(true)) + 15,
                        'on_headers' => $read,
                    ])
                    ->get($v->url);
            });
        } catch (Throwable $e) {
            for ($p = $e; $p !== null; $p = $p->getPrevious()) {
                if ($p instanceof ImportFailedException || $p instanceof RangeIgnored || $p instanceof UnsafeUrlException) {
                    $e = $p;
                    break;
                }
            }

            if ($e instanceof RangeIgnored) {
                return new RemotePass(true, false, false, null, null, null, 0);
            }

            if (! ($e instanceof ConnectException || $e instanceof ConnectionException) || $meta === null) {
                throw $e;
            }

            // Cortada por el presupuesto de la pasada (o por el tope de bytes): lo escrito se conserva en el parcial.
            $aborted = true;
        }

        if ($response !== null) {
            try {
                $meta ??= $this->readHeaders($response->toPsrResponse(), $offset, $policy);
                $cap = $meta['cap'] ?? $cap;
            } catch (RangeIgnored) {
                return new RemotePass(true, false, false, null, null, null, 0);
            }
        }

        if ($meta === null) {
            throw new ImportFailedException('El servidor remoto no devolvió el fichero.');
        }

        if ($meta['status'] === 416) {
            // Rango fuera de lo que tiene el remoto: o ya estaba completo, o cambio.
            return ($meta['total'] !== null && $meta['total'] === $offset)
                ? new RemotePass(false, true, true, $meta['total'], $validator, null, 0)
                : new RemotePass(true, false, false, null, null, null, 0);
        }

        fflush($sink);
        $written = max(0, (int) (fstat($sink)['size'] ?? 0) - $offset);

        if ($offset + $written > $cap) {
            throw new ImportFailedException('El fichero supera el tamaño máximo permitido.');
        }

        $complete = ! $aborted && ($meta['total'] === null || $offset + $written >= $meta['total']);

        return new RemotePass(false, $complete, $meta['ranges'], $meta['total'], $meta['validator'], $meta['mime'], $written);
    }

    public function name(): string
    {
        $path = (string) parse_url($this->url, PHP_URL_PATH);
        $name = basename(rawurldecode($path));

        return $name !== '' && $name !== '/' ? $name : 'importado';
    }

    public function reference(): string
    {
        $p = parse_url($this->url) ?: [];

        return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '').($p['path'] ?? '');
    }

    /**
     * Lee y valida las cabeceras de una respuesta. `null` en una redireccion. Lanza si es un error o si el tipo o el tamano no valen.
     *
     * @return array{status: int, total: ?int, validator: ?string, mime: ?string, ranges: bool, cap: int}|null
     */
    private function readHeaders(ResponseInterface $r, int $offset, Closure $policy): ?array
    {
        $status = $r->getStatusCode();

        if ($status >= 300 && $status < 400) {
            return null;
        }

        if ($status === 416) {
            $total = preg_match('#/(\d+)$#', $r->getHeaderLine('Content-Range'), $m) ? (int) $m[1] : null;

            return ['status' => 416, 'total' => $total, 'validator' => null, 'mime' => null, 'ranges' => true, 'cap' => PHP_INT_MAX];
        }

        // 5xx y 429 son transitorios: el job reintenta con espera. El resto de errores no se arregla reintentando.
        if ($status >= 500 || $status === 429) {
            throw new RuntimeException("El servidor remoto respondió {$status}.");
        }

        if ($status !== 200 && $status !== 206) {
            throw new ImportFailedException("El servidor remoto respondió {$status}.");
        }

        if ($offset > 0 && $status === 200) {
            throw new RangeIgnored;
        }

        $mime = strtolower(trim(explode(';', $r->getHeaderLine('Content-Type'))[0]));

        if ($status === 206) {
            $total = preg_match('#^bytes\s+\d+-\d+/(\d+)$#i', trim($r->getHeaderLine('Content-Range')), $m) ? (int) $m[1] : null;
        } else {
            $length = trim($r->getHeaderLine('Content-Length'));
            $total = ctype_digit($length) ? (int) $length : null;
        }

        $etag = $r->getHeaderLine('ETag');
        $validator = ($etag !== '' && ! str_starts_with($etag, 'W/')) ? $etag : ($r->getHeaderLine('Last-Modified') ?: null);

        return [
            'status' => $status,
            'total' => $total,
            'validator' => $validator,
            'mime' => $mime !== '' ? $mime : null,
            'ranges' => $status === 206 || str_contains(strtolower($r->getHeaderLine('Accept-Ranges')), 'bytes'),
            'cap' => $policy($mime !== '' ? $mime : null, $total),
        ];
    }
}
