<?php

namespace App\Connectors\LinkedIn;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP de LinkedIn (T-18): OAuth 2.0 en `social.linkedin.auth_host` y API REST versionada en `social.linkedin.api_host`
 * (cabeceras `LinkedIn-Version` y `X-Restli-Protocol-Version: 2.0.0`). El token viaja siempre en `Authorization: Bearer`, nunca en la
 * URL ni en los registros. Traduce los errores a las excepciones de la cola: 401 -> reconectar; 429 -> cuota; 5xx y red -> transitorio;
 * 403 (permisos o producto no aprobado) y 4xx de contenido -> permanente con mensaje legible.
 */
class LinkedInClient
{
    /** Hosts a los que LinkedIn puede mandar una subida (URL devuelta por su API): se rechaza cualquier otro. */
    private const UPLOAD_HOST_SUFFIXES = ['linkedin.com', 'licdn.com'];

    /**
     * Intercambio en `/oauth/v2/accessToken` (codigo o refresco). Los fallos son `ConnectorException` con mensaje apto para el usuario.
     *
     * @return array<string, mixed>
     */
    public function token(#[\SensitiveParameter] array $params): array
    {
        try {
            $response = Http::timeout((int) config('social.http_timeout', 15))->acceptJson()->asForm()
                ->post(rtrim((string) config('social.linkedin.auth_host'), '/').'/oauth/v2/accessToken', $params);
        } catch (ConnectionException) {
            throw new ConnectorException('No se pudo contactar con LinkedIn. Se reintentara mas tarde.');
        }

        $json = $response->json() ?? [];
        if ($response->successful() && ! empty($json['access_token'])) {
            return $json;
        }

        $error = (string) ($json['error'] ?? '');
        $message = mb_substr((string) ($json['error_description'] ?? ($error !== '' ? $error : 'respuesta inesperada')), 0, 200);
        Log::warning('linkedin.oauth_error', ['status' => $response->status(), 'error' => $error]);

        if ($error === 'invalid_grant' || $error === 'invalid_token' || $response->status() === 401) {
            throw new ReconnectRequiredException('LinkedIn rechazo las credenciales ('.$message.'). Reconecta la cuenta.');
        }
        if ($response->serverError() || $response->status() === 429) {
            throw new ConnectorException('LinkedIn no esta disponible temporalmente ('.$message.').');
        }

        throw new ConnectorException('LinkedIn rechazo la conexion: '.$message);
    }

    /** GET/POST de la API REST con el token del usuario. `$read` marca lecturas opcionales (un rechazo es esperable). */
    public function api(string $method, string $path, #[\SensitiveParameter] string $token, ?array $json = null, array $query = [], array $headers = [], bool $read = false): Response
    {
        $http = $this->http($token)->withHeaders($headers);
        $url = rtrim((string) config('social.linkedin.api_host'), '/').'/'.ltrim($path, '/');

        try {
            $response = match (strtoupper($method)) {
                'GET' => $http->get($url, $query),
                'POST' => $http->withQueryParameters($query)->post($url, $json ?? new \stdClass),
                default => throw new \InvalidArgumentException("Metodo $method no soportado."),
            };
        } catch (ConnectionException) {
            throw new TransientPublishException('No se pudo contactar con LinkedIn (tiempo agotado o red).', 'red');
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->exceptionFor($response, $read);
    }

    /**
     * Sube bytes a la URL de subida que dio LinkedIn (PUT). Devuelve la cabecera `ETag` (partes de video).
     *
     * @param  array<string, string>  $headers  cabeceras extra (la portada de video lleva `media-type-family: STILLIMAGE`)
     */
    public function upload(string $url, string $bytes, #[\SensitiveParameter] string $token, array $headers = []): ?string
    {
        $this->assertUploadUrl($url);

        try {
            $response = Http::timeout((int) config('social.linkedin.upload_timeout', 60))->withToken($token)->withHeaders($headers)
                ->withBody($bytes, 'application/octet-stream')->put($url);
        } catch (ConnectionException) {
            throw new TransientPublishException('No se pudo subir la media a LinkedIn (tiempo agotado o red).', 'red');
        }

        if (! $response->successful()) {
            throw $this->exceptionFor($response, false);
        }

        $etag = trim($response->header('ETag'), '"');

        return $etag !== '' ? $etag : null;
    }

    public function assertUploadUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $ok = ($parts['scheme'] ?? '') === 'https' && $host !== '' && ! isset($parts['user'])
            && collect(self::UPLOAD_HOST_SUFFIXES)->contains(fn (string $s) => $host === $s || str_ends_with($host, '.'.$s));
        if (! $ok) {
            throw new PermanentPublishException('LinkedIn devolvio una URL de subida no reconocida; no se envia la media.', 'url_subida');
        }
    }

    public function exceptionFor(Response $response, bool $read): \Throwable
    {
        $status = $response->status();
        $json = $response->json() ?? [];
        $message = mb_substr((string) ($json['message'] ?? $json['error_description'] ?? 'respuesta inesperada'), 0, 300);
        Log::log($read ? 'debug' : 'warning', $read ? 'linkedin.read_error' : 'linkedin.api_error', ['status' => $status, 'code' => $json['serviceErrorCode'] ?? null]);

        if ($status === 401) {
            return new ReconnectRequiredException('LinkedIn rechazo las credenciales ('.$message.'). Reconecta la cuenta.');
        }
        if ($status === 429) {
            return new QuotaExceededException('LinkedIn limito las peticiones de la app ('.$message.').', null, 'rate_limit');
        }
        if ($status === 403) {
            return new PermanentPublishException('Faltan permisos en LinkedIn para esta accion ('.$message.'). Para paginas hace falta la aprobacion de Community Management; revisa los permisos y vuelve a conectar.', 'permisos');
        }
        if ($response->serverError() || $status === 408) {
            return new TransientPublishException('LinkedIn no esta disponible temporalmente ('.$message.').', 'linkedin_no_disponible');
        }

        return new PermanentPublishException('LinkedIn rechazo la publicacion: '.$message, 'rechazada');
    }

    private function http(string $token): PendingRequest
    {
        return Http::timeout((int) config('social.http_timeout', 15))->acceptJson()->withToken($token)->withHeaders([
            'LinkedIn-Version' => (string) config('social.linkedin.api_version'),
            'X-Restli-Protocol-Version' => '2.0.0',
        ]);
    }
}
