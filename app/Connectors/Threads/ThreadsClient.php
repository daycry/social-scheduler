<?php

namespace App\Connectors\Threads;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\GraphErrors;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP de la Threads API (host `social.threads.graph_host`, `graph.threads.com`; versionado `social.threads.api_version`).
 * Propio y separado de `GraphClient` (T1): comparte con el solo la tabla de codigos de credencial y de limite (`GraphErrors`).
 *
 * El token viaja como documenta Meta: en el CUERPO en los POST y en la query en los GET (nunca se registra). Con `THREADS_TOKEN_HEADER=true`
 * va en `Authorization: Bearer` (fuera de la URL y de los registros de proxy); la documentacion no lo describe, asi que es opt-in hasta
 * confirmarlo en el smoke (T-64). Los intercambios de token (`oauth/access_token`, `access_token`, `refresh_access_token`) van sin version.
 *
 * Traduce los errores a las excepciones de la cola: credencial -> reconectar; limite de uso, 429, 5xx y red -> transitorio; permisos o
 * contenido -> permanente con mensaje legible. Los errores de Threads llegan con dos formas: `{error: {message, code, error_subcode}}`
 * y `{error_type, code, error_message}` (esta ultima en los intercambios de token).
 */
class ThreadsClient
{
    /**
     * GET de la API versionada (perfil, contenedores, insights, publicaciones).
     *
     * `$read` marca una LECTURA de metricas: un rechazo es un dato «no disponible» esperable y se registra en `debug`.
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query, string $token, bool $read = false): array
    {
        return $this->send(fn (PendingRequest $http) => $this->tokenInHeader()
            ? $http->withToken($token)->get($this->url($path), $query)
            : $http->get($this->url($path), $query + ['access_token' => $token]), $read);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $params, string $token): array
    {
        return $this->send(fn (PendingRequest $http) => $this->tokenInHeader()
            ? $http->withToken($token)->asForm()->post($this->url($path), $params)
            : $http->asForm()->post($this->url($path), $params + ['access_token' => $token]));
    }

    /**
     * Intercambio de tokens, sin version (`oauth/access_token`, `access_token`, `refresh_access_token`). Los fallos son
     * `ConnectorException` con un mensaje apto para el usuario.
     *
     * @return array<string, mixed>
     */
    public function exchange(string $method, string $path, array $params): array
    {
        try {
            $http = $this->http();
            $response = strtoupper($method) === 'POST'
                ? $http->asForm()->post($this->base().'/'.ltrim($path, '/'), $params)
                : $http->get($this->base().'/'.ltrim($path, '/'), $params);
        } catch (ConnectionException) {
            throw new ConnectorException('No se pudo contactar con Threads. Se reintentara mas tarde.');
        }

        $json = $response->json() ?? [];

        if ($response->successful() && ! isset($json['error']) && ! isset($json['error_type'])) {
            return $json;
        }

        [$code, $sub, $message] = $this->errorOf($json);
        Log::warning('threads.oauth_error', ['status' => $response->status(), 'code' => $code, 'subcode' => $sub]);

        if (GraphErrors::needsReconnect($code) || $response->status() === 401) {
            throw new ReconnectRequiredException('Threads rechazo las credenciales ('.$message.'). Reconecta la cuenta.');
        }

        if ($response->serverError() || $response->status() === 429 || GraphErrors::isRateLimit($code)) {
            throw new ConnectorException('Threads no esta disponible temporalmente ('.$message.').');
        }

        throw new ConnectorException('Threads rechazo la conexion: '.$message);
    }

    /** @param \Closure(PendingRequest): Response $call */
    private function send(\Closure $call, bool $read = false): array
    {
        try {
            $response = $call($this->http());
        } catch (ConnectionException) {
            // Puede que Threads haya recibido la peticion: el reintento consulta antes de publicar.
            throw new TransientPublishException('No se pudo contactar con Threads (tiempo agotado o red).', 'red');
        }

        $json = $response->json() ?? [];

        if ($response->successful() && ! isset($json['error'])) {
            return $json;
        }

        [$code, $sub, $message] = $this->errorOf($json);

        if ($read) {
            Log::debug('threads.read_error', ['status' => $response->status(), 'code' => $code, 'subcode' => $sub]);
        } else {
            Log::warning('threads.publish_error', ['status' => $response->status(), 'code' => $code, 'subcode' => $sub]);
        }

        throw $this->exceptionFor($response, $code, $message);
    }

    private function exceptionFor(Response $response, int $code, string $message): ConnectorException
    {
        if (GraphErrors::needsReconnect($code) || $response->status() === 401) {
            return new ReconnectRequiredException('Threads rechazo las credenciales ('.$message.'). Reconecta la cuenta.');
        }

        if ($response->status() === 429 || GraphErrors::isRateLimit($code)) {
            return new TransientPublishException('Threads limito temporalmente las peticiones ('.$message.').', 'rate_limit');
        }

        if (in_array($code, [3, 10, 200, 299], true) || str_starts_with($message, '(#10)') || str_starts_with($message, '(#200)')) {
            return new PermanentPublishException('Faltan permisos en Threads para publicar ('.$message.'). Revisa los permisos concedidos y vuelve a conectar la cuenta.', 'permisos');
        }

        if ($response->serverError() || in_array($code, [1, 2], true)) {
            return new TransientPublishException('Threads no esta disponible temporalmente ('.$message.').', 'threads_no_disponible');
        }

        return new PermanentPublishException('Threads rechazo la publicacion: '.$message, 'rechazada');
    }

    /** @return array{0: int, 1: int, 2: string} codigo, subcodigo y mensaje legible. */
    private function errorOf(array $json): array
    {
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = (int) ($error['code'] ?? $json['code'] ?? 0);
        $sub = (int) ($error['error_subcode'] ?? 0);
        $message = (string) ($error['error_user_msg'] ?? $error['message'] ?? $json['error_message'] ?? 'respuesta inesperada');

        return [$code, $sub, mb_substr($message, 0, 300)];
    }

    private function tokenInHeader(): bool
    {
        return (bool) config('social.threads.token_in_header', false);
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('social.http_timeout', 15))->acceptJson();
    }

    private function base(): string
    {
        return rtrim((string) config('social.threads.graph_host', 'https://graph.threads.com'), '/');
    }

    private function url(string $path): string
    {
        return $this->base().'/'.trim((string) config('social.threads.api_version', 'v1.0'), '/').'/'.ltrim($path, '/');
    }
}
