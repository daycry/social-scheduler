<?php

namespace App\Connectors\Pinterest;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Support\KnownSizeStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP de la API v5 de Pinterest (T-18). OAuth en `social.pinterest.auth_host` (autorizar) y `api_host/v5/oauth/token` (canje y
 * refresco, con `Authorization: Basic base64(id:secreto)`); el resto va con `Authorization: Bearer`. El token nunca viaja en la URL ni se
 * registra. Errores: 401 -> reconectar; 429 -> cuota; 5xx y red -> transitorio; 403 (permisos o app en acceso de prueba) y 4xx -> permanente.
 */
class PinterestClient
{
    /** Hosts a los que Pinterest puede mandar una subida de video (URL prefirmada de su API): se rechaza cualquier otro. */
    private const UPLOAD_HOST_SUFFIXES = ['amazonaws.com', 'pinterest.com', 'pinimg.com'];

    /** @return array<string, mixed> */
    public function token(#[\SensitiveParameter] array $params): array
    {
        $id = (string) config('services.pinterest.client_id');
        $secret = (string) config('services.pinterest.client_secret');

        try {
            $response = Http::timeout((int) config('social.http_timeout', 15))->acceptJson()->withBasicAuth($id, $secret)->asForm()
                ->post($this->base().'/v5/oauth/token', $params);
        } catch (ConnectionException) {
            throw new ConnectorException('No se pudo contactar con Pinterest. Se reintentara mas tarde.');
        }

        $json = $response->json() ?? [];
        if ($response->successful() && ! empty($json['access_token'])) {
            return $json;
        }

        $message = mb_substr((string) ($json['message'] ?? $json['error_description'] ?? $json['error'] ?? 'respuesta inesperada'), 0, 200);
        Log::warning('pinterest.oauth_error', ['status' => $response->status(), 'code' => $json['code'] ?? null]);

        if ($response->status() === 401 || in_array((string) ($json['error'] ?? ''), ['invalid_grant', 'invalid_refresh_token'], true)) {
            throw new ReconnectRequiredException('Pinterest rechazo las credenciales ('.$message.'). Reconecta la cuenta.');
        }
        if ($response->serverError() || $response->status() === 429) {
            throw new ConnectorException('Pinterest no esta disponible temporalmente ('.$message.').');
        }

        throw new ConnectorException('Pinterest rechazo la conexion: '.$message);
    }

    /** GET/POST de la API v5 con el token del usuario. `$read` marca lecturas opcionales (un rechazo es esperable). */
    public function api(string $method, string $path, #[\SensitiveParameter] string $token, ?array $json = null, array $query = [], bool $read = false): Response
    {
        $http = Http::timeout((int) config('social.http_timeout', 15))->acceptJson()->withToken($token);
        $url = $this->base().'/'.ltrim($path, '/');

        try {
            $response = match (strtoupper($method)) {
                'GET' => $http->get($url, $query),
                'POST' => $http->post($url, $json ?? new \stdClass),
                default => throw new \InvalidArgumentException("Metodo $method no soportado."),
            };
        } catch (ConnectionException) {
            throw new TransientPublishException('No se pudo contactar con Pinterest (tiempo agotado o red).', 'red');
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->exceptionFor($response, $read);
    }

    /**
     * Sube el video a la URL prefirmada que dio `POST /v5/media` (multipart: primero los campos de `upload_parameters`, el fichero al final).
     *
     * @param  array<string, scalar>  $parameters
     * @param  resource  $stream  flujo del video (nunca su contenido en un string: ver PinterestPublisher)
     * @param  int  $size  tamano REAL en bytes (un stream remoto no lo declara) y $timeout ya acotado al presupuesto del paso
     */
    public function upload(string $url, array $parameters, $stream, string $mime, int $size, int $timeout): void
    {
        $this->assertUploadUrl($url);

        $parts = [];
        foreach ($parameters as $name => $value) {
            $parts[] = ['name' => (string) $name, 'contents' => (string) $value];
        }
        $parts[] = ['name' => 'file', 'contents' => new KnownSizeStream(Utils::streamFor($stream), $size), 'filename' => 'video', 'headers' => ['Content-Type' => $mime]];

        try {
            $response = Http::timeout(max(1, $timeout))->asMultipart()->post($url, $parts);
        } catch (ConnectionException) {
            throw new TransientPublishException('No se pudo subir el video a Pinterest (tiempo agotado o red).', 'red');
        }

        if (in_array($response->status(), [400, 403, 404, 410], true)) {
            throw new PinterestUploadRejectedException($response->status());
        }
        if (! $response->successful()) {
            throw $this->exceptionFor($response, false);
        }
    }

    public function assertUploadUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $ok = ($parts['scheme'] ?? '') === 'https' && $host !== '' && ! isset($parts['user'])
            && collect(self::UPLOAD_HOST_SUFFIXES)->contains(fn (string $s) => $host === $s || str_ends_with($host, '.'.$s));
        if (! $ok) {
            throw new PermanentPublishException('Pinterest devolvio una URL de subida no reconocida; no se envia el video.', 'url_subida');
        }
    }

    public function exceptionFor(Response $response, bool $read): \Throwable
    {
        $status = $response->status();
        $json = $response->json() ?? [];
        $message = mb_substr((string) ($json['message'] ?? 'respuesta inesperada'), 0, 300);
        Log::log($read ? 'debug' : 'warning', $read ? 'pinterest.read_error' : 'pinterest.api_error', ['status' => $status, 'code' => $json['code'] ?? null]);

        if ($status === 401) {
            return new ReconnectRequiredException('Pinterest rechazo las credenciales ('.$message.'). Reconecta la cuenta.');
        }
        if ($status === 429) {
            return new QuotaExceededException('Pinterest limito las peticiones de la app ('.$message.').', null, 'rate_limit');
        }
        if ($status === 403) {
            return new PermanentPublishException('Pinterest nego el permiso ('.$message.'). Las apps nuevas estan en acceso de prueba (solo sandbox) hasta que Pinterest las aprueba; revisa los permisos y vuelve a conectar.', 'permisos');
        }
        if ($response->serverError() || $status === 408) {
            return new TransientPublishException('Pinterest no esta disponible temporalmente ('.$message.').', 'pinterest_no_disponible');
        }

        return new PermanentPublishException('Pinterest rechazo la publicacion: '.$message, 'rechazada');
    }

    private function base(): string
    {
        return rtrim((string) config('social.pinterest.api_host'), '/');
    }
}
