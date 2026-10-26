<?php

namespace App\Services\Media;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/** Descarga de una URL publica. Toda peticion (y cada redireccion) pasa por `UrlGuard` y va fijada a la IP validada. */
class UrlSource implements RemoteSource
{
    /** 4xx que si se arreglan reintentando (el resto de 4xx es permanente). */
    private const TRANSIENT_4XX = [408, 425, 429];

    public function __construct(private readonly UrlGuard $guard, private readonly string $url) {}

    public function fetch($sink, int $offset, ?string $validator, Closure $policy, int $cap, float $deadline, ?int $knownTotal = null): RemotePass
    {
        $meta = null;
        $headerError = null;
        $aborted = false;

        // Cabeceras de la respuesta: en el cliente real se leen antes del cuerpo (corta una descarga que no vale). Guzzle envuelve
        // lo que lance `on_headers` en una `RequestException` sin la causa, asi que se guarda aparte para recuperarla.
        $read = function (ResponseInterface $r) use (&$meta, &$cap, &$headerError, $offset, $policy, $validator, $knownTotal): void {
            try {
                $meta = $this->readHeaders($r, $offset, $policy, $validator, $knownTotal);
                $cap = $meta['cap'] ?? $cap;
            } catch (Throwable $e) {
                $headerError = $e;

                throw $e;
            }
        };

        // Tope de bytes y plazo DURANTE la descarga, con la opcion nativa `progress` de Guzzle (no con CURLOPT_PROGRESSFUNCTION,
        // que Guzzle 8 rechaza): devolver `true` corta la transferencia y lo ya escrito queda en el parcial.
        $progress = function (int $dlTotal, int $dlNow) use (&$cap, &$aborted, $offset, $deadline): bool {
            if ($dlNow > $cap - $offset || microtime(true) >= $deadline) {
                $aborted = true;

                return true;
            }

            return false;
        };

        $headers = ['Range' => "bytes=$offset-", 'Accept-Encoding' => 'identity', 'User-Agent' => 'SocialScheduler/1.0 (media-import)'];

        if ($offset > 0 && $validator !== null) {
            $headers['If-Range'] = $validator;
        }

        $response = null;

        try {
            $response = $this->guard->follow($this->url, function (ValidatedUrl $v) use ($sink, $offset, $headers, $deadline, $read, $progress, &$meta, &$headerError, &$aborted) {
                // Un salto anterior (redireccion, error) pudo dejar su cuerpo en el sink: se vuelve al punto de control.
                ftruncate($sink, $offset);
                fseek($sink, $offset);
                $meta = null;
                $headerError = null;
                $aborted = false;

                return $this->guard->client($v)
                    ->withHeaders($headers)
                    ->withOptions([
                        'sink' => $sink,
                        'connect_timeout' => 10,
                        'timeout' => max(5.0, $deadline - microtime(true)) + 5,
                        'on_headers' => $read,
                        'progress' => $progress,
                    ])
                    ->get($v->url);
            });
        } catch (Throwable $e) {
            $e = $this->rootCause($e, $headerError);

            if ($e instanceof RangeIgnored) {
                return new RemotePass(true, false, false, null, null, null, 0, $e->reason);
            }

            $timedOut = $e instanceof ConnectException || $e instanceof ConnectionException;

            if (! ($aborted || $timedOut) || $meta === null) {
                throw $e;
            }

            // Cortada por el presupuesto de la pasada (o por el tope de bytes): lo escrito se conserva en el parcial.
            $aborted = true;
        }

        if ($response !== null) {
            try {
                $meta ??= $this->readHeaders($response->toPsrResponse(), $offset, $policy, $validator, $knownTotal);
                $cap = $meta['cap'] ?? $cap;
            } catch (RangeIgnored $e) {
                return new RemotePass(true, false, false, null, null, null, 0, $e->reason);
            }
        }

        if ($meta === null) {
            throw new ImportFailedException('El servidor remoto no devolvió el fichero.');
        }

        if ($meta['status'] === 416) {
            // Rango fuera de lo que tiene el remoto: o ya estaba completo, o cambio.
            return ($meta['total'] !== null && $meta['total'] === $offset)
                ? new RemotePass(false, true, true, $meta['total'], $validator, null, 0)
                : new RemotePass(true, false, false, null, null, null, 0, RangeIgnored::CHANGED);
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
     * La causa real de un fallo: la que guardo `on_headers` o, si no, la primera excepcion nuestra de la cadena `previous`; y un 4xx
     * de una `RequestException` sin causa recuperable se trata como permanente (mirando el estado de la respuesta).
     */
    private function rootCause(Throwable $e, ?Throwable $headerError): Throwable
    {
        if ($headerError !== null) {
            return $headerError;
        }

        for ($p = $e; $p !== null; $p = $p->getPrevious()) {
            if ($p instanceof ImportFailedException || $p instanceof RangeIgnored || $p instanceof UnsafeUrlException) {
                return $p;
            }
        }

        if ($e instanceof RequestException && $e->hasResponse()) {
            $status = $e->getResponse()->getStatusCode();

            if ($status >= 400 && $status < 500 && ! in_array($status, self::TRANSIENT_4XX, true)) {
                return new ImportFailedException("El servidor remoto respondió {$status}.");
            }
        }

        return $e;
    }

    /**
     * Lee y valida las cabeceras de una respuesta. `null` en una redireccion. Lanza si es un error o si el tipo o el tamano no valen.
     *
     * @return array{status: int, total: ?int, validator: ?string, mime: ?string, ranges: bool, cap: int}|null
     */
    private function readHeaders(ResponseInterface $r, int $offset, Closure $policy, ?string $sentValidator = null, ?int $knownTotal = null): ?array
    {
        $status = $r->getStatusCode();

        if ($status >= 300 && $status < 400) {
            return null;
        }

        if ($status === 416) {
            $total = preg_match('#/(\d+)$#', $r->getHeaderLine('Content-Range'), $m) ? (int) $m[1] : null;

            return ['status' => 416, 'total' => $total, 'validator' => null, 'mime' => null, 'ranges' => true, 'cap' => PHP_INT_MAX];
        }

        // 5xx, 408, 425 y 429 son transitorios: el job reintenta con espera. El resto de errores no se arregla reintentando.
        if ($status >= 500 || in_array($status, self::TRANSIENT_4XX, true)) {
            throw new RuntimeException("El servidor remoto respondió {$status}.");
        }

        if ($status !== 200 && $status !== 206) {
            throw new ImportFailedException("El servidor remoto respondió {$status}.");
        }

        $etag = $r->getHeaderLine('ETag');
        $validator = ($etag !== '' && ! str_starts_with($etag, 'W/')) ? $etag : ($r->getHeaderLine('Last-Modified') ?: null);

        if ($offset > 0 && $status === 200) {
            // Con `If-Range`, un 200 es «no admito Range» (mismo validador) o «el fichero cambio» (otro validador).
            throw new RangeIgnored(match (true) {
                $sentValidator === null || $validator === null => null,
                $sentValidator === $validator => RangeIgnored::IGNORED,
                default => RangeIgnored::CHANGED,
            });
        }

        $mime = strtolower(trim(explode(';', $r->getHeaderLine('Content-Type'))[0]));

        if ($status === 206) {
            $total = preg_match('#^bytes\s+\d+-\d+/(\d+)$#i', trim($r->getHeaderLine('Content-Range')), $m) ? (int) $m[1] : null;
        } else {
            $length = trim($r->getHeaderLine('Content-Length'));
            $total = ctype_digit($length) ? (int) $length : null;
        }

        // Sin validador no se puede usar `If-Range`: el total declarado es la unica pista de que el remoto cambio entre pasadas.
        if ($offset > 0 && $knownTotal !== null && $total !== null && $total !== $knownTotal) {
            throw new RangeIgnored(RangeIgnored::CHANGED);
        }

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
