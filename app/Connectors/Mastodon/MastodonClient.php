<?php

namespace App\Connectors\Mastodon;

use App\Services\Media\UnsafeUrlException;
use App\Services\Media\UrlGuard;
use App\Support\SafeUrl;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * Cliente de la API REST de una instancia de Mastodon (T-87). La instancia la escribe el usuario, asi que toda peticion pasa por la guardia SSRF:
 * la URL se valida (DNS incluido) justo antes de pedirla, la conexion se fija a la IP validada, no se siguen redirecciones y solo se admite https.
 * No guarda estado: el token se pasa por llamada (cabecera `Authorization`) y nunca se registra.
 */
class MastodonClient
{
    /**
     * Normaliza lo que escribe el usuario a `https://host`: sin esquema se asume https, un `http://` se rechaza y no se admiten ruta, parametros,
     * usuario ni un puerto distinto de 443. El host tiene que ser publico.
     *
     * @throws MastodonException
     */
    public function normalizeInstance(string $input): string
    {
        $input = trim($input);
        $url = preg_match('~^[a-z][a-z0-9+.-]*://~i', $input) === 1 ? $input : 'https://'.$input;
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            throw new MastodonException('Escribe la dirección de tu instancia de Mastodon, por ejemplo mastodon.social.');
        }
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new MastodonException('La instancia de Mastodon tiene que usar https.');
        }
        $path = (string) ($parts['path'] ?? '');
        if (($path !== '' && $path !== '/') || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new MastodonException('Escribe solo la dirección de la instancia (sin ruta, puerto ni parámetros), por ejemplo mastodon.social.');
        }

        try {
            return 'https://'.app(UrlGuard::class)->validate('https://'.$parts['host'])->host;
        } catch (UnsafeUrlException) {
            throw new MastodonException('Esa instancia de Mastodon no es válida o no es pública.');
        }
    }

    /**
     * Datos de la instancia, sin autenticar (`GET /api/v2/instance`): de ahi salen los limites.
     *
     * @return array<string, mixed>
     */
    public function instance(string $base): array
    {
        return (array) $this->send($base, '/api/v2/instance', fn (PendingRequest $http, string $url) => $http->get($url))->json();
    }

    /**
     * Cuenta del token (`GET /api/v1/accounts/verify_credentials`).
     *
     * @return array<string, mixed> id, username, acct, display_name, avatar, url...
     */
    public function verifyCredentials(string $base, #[\SensitiveParameter] string $token): array
    {
        return (array) $this->send($base, '/api/v1/accounts/verify_credentials', fn (PendingRequest $http, string $url) => $http->withToken($token)->get($url))->json();
    }

    /**
     * Sube una imagen (`POST /api/v2/media`, multipart). 200 = lista; 202 = Mastodon sigue procesandola (hay que sondear `media()`).
     *
     * @return array{status: int, media: array<string, mixed>}
     */
    public function uploadMedia(string $base, #[\SensitiveParameter] string $token, string $bytes, string $mime, string $filename, ?string $description): array
    {
        $response = $this->send($base, '/api/v2/media', fn (PendingRequest $http, string $url) => $http->withToken($token)
            ->attach('file', $bytes, $filename, ['Content-Type' => $mime])
            ->post($url, $description !== null && $description !== '' ? ['description' => $description] : []));

        return ['status' => $response->status(), 'media' => (array) $response->json()];
    }

    /**
     * Estado de una imagen subida (`GET /api/v1/media/:id`): 200 = lista, 206 = procesandose.
     *
     * @return array{status: int, media: array<string, mixed>}
     */
    public function media(string $base, #[\SensitiveParameter] string $token, string $id): array
    {
        $response = $this->send($base, '/api/v1/media/'.rawurlencode($id), fn (PendingRequest $http, string $url) => $http->withToken($token)->get($url));

        return ['status' => $response->status(), 'media' => (array) $response->json()];
    }

    /**
     * Crea el estado (`POST /api/v1/statuses`). La instancia guarda la `Idempotency-Key` una hora: repetirla devuelve el estado ya creado.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> El Status (id, url, uri...)
     */
    public function createStatus(string $base, #[\SensitiveParameter] string $token, array $body, string $idempotencyKey): array
    {
        return (array) $this->send($base, '/api/v1/statuses', fn (PendingRequest $http, string $url) => $http->withToken($token)->withHeaders(['Idempotency-Key' => $idempotencyKey])->asJson()->post($url, $body))->json();
    }

    /**
     * @param  Closure(PendingRequest, string): Response  $call  Recibe el cliente y la URL validada.
     *
     * @throws MastodonException
     */
    private function send(string $base, string $path, Closure $call): Response
    {
        $guard = app(UrlGuard::class);

        try {
            $validated = $guard->validate(rtrim($base, '/').$path);
        } catch (UnsafeUrlException) {
            throw self::unsafe('La instancia de Mastodon no es válida o no es pública.');
        }
        if ($validated->scheme !== 'https') {
            throw self::unsafe('La instancia de Mastodon tiene que usar https.');
        }

        // Tope del cuerpo: se corta por la cabecera `Content-Length` y, sin ella (troceado), en cuanto lo descargado lo pasa. Opciones nativas de Guzzle: no
        // cambian el manejador cURL, asi que la IP fijada sigue aplicandose (`stream => true` la rechazaria).
        $cap = max(1024, (int) config('social.mastodon.max_response_bytes', 1_048_576));
        $exceeded = false;
        $http = Http::acceptJson()->timeout((int) config('social.http_timeout', 15))->withoutRedirecting()->withOptions($guard->requestOptions($validated) + [
            'on_headers' => function (ResponseInterface $response) use ($cap, &$exceeded): void {
                if ((int) $response->getHeaderLine('Content-Length') > $cap) {
                    $exceeded = true;

                    throw self::tooBig();
                }
            },
            // Abortar desde el callback de cURL llega como un fallo de conexion: `$exceeded` distingue el corte por tamano.
            'progress' => function ($downloadTotal, $downloaded) use ($cap, &$exceeded): void {
                if ($downloaded > $cap) {
                    $exceeded = true;

                    throw self::tooBig();
                }
            },
        ]);

        try {
            $response = $call($http, $validated->url);
        } catch (ConnectionException) {
            throw $exceeded ? self::tooBig() : new MastodonException('No se pudo conectar con la instancia de Mastodon.');
        } catch (RequestException $e) {
            // Respuesta cortada a mitad o error del cliente HTTP: se trata como el error HTTP que traia, nunca como un fallo sin controlar.
            throw new MastodonException('La instancia de Mastodon respondió con un error.', $e->response->status());
        }

        if (strlen($response->body()) > $cap) {
            throw self::tooBig();
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            throw new MastodonException('La instancia de Mastodon respondió con una redirección que no se admite.', $response->status());
        }

        if ($response->failed()) {
            $error = $response->json('error');
            $detail = is_string($error) ? mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f\s]+/u', ' ', $error)), 0, 200) : '';

            throw new MastodonException('La instancia de Mastodon respondió con un error.', $response->status(), $response->status() === 429 ? self::retryAt($response) : null, $detail);
        }

        return $response;
    }

    private static function tooBig(): MastodonException
    {
        return new MastodonException('La respuesta de la instancia de Mastodon es demasiado grande.', 502);
    }

    /**
     * URL que viene de una instancia ajena, saneada: https y sin caracteres raros (`SafeUrl`) y, si se pide, del host de la instancia. Si no, null.
     */
    public static function safeUrl(mixed $url, ?string $host = null): ?string
    {
        if (! is_string($url) || ! SafeUrl::isSafe($url) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }

        return $host === null || strtolower((string) parse_url($url, PHP_URL_HOST)) === strtolower($host) ? $url : null;
    }

    /** Instancia no permitida: no es un fallo de red (reintentar no lo arregla), asi que lleva un 400 y su motivo. */
    private static function unsafe(string $message): MastodonException
    {
        return new MastodonException($message, 400, null, $message);
    }

    /** Cuando se libera el limite: `Retry-After` (segundos) o `X-RateLimit-Reset` (fecha o marca de tiempo); nada si ninguna cabecera es legible. */
    private static function retryAt(Response $response): ?CarbonImmutable
    {
        $after = trim($response->header('Retry-After'));
        if (ctype_digit($after) && $after !== '') {
            return CarbonImmutable::now()->addSeconds(min((int) $after, 86400));
        }

        $reset = trim($response->header('X-RateLimit-Reset'));
        if ($reset === '') {
            return null;
        }

        try {
            $at = ctype_digit($reset) ? CarbonImmutable::createFromTimestamp((int) $reset) : CarbonImmutable::parse($reset);
        } catch (\Throwable) {
            return null;
        }

        return $at->isFuture() && $at->lte(CarbonImmutable::now()->addDay()) ? $at : null;
    }
}
