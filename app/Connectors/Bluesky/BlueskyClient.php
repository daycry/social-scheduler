<?php

namespace App\Connectors\Bluesky;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * Cliente XRPC de atproto. Resuelve handle -> DID -> PDS y habla con el PDS de la cuenta. No guarda estado ni
 * credenciales: el token se pasa por llamada (cabecera Authorization) y nunca se registra. Las llamadas con red
 * simulada (`Http::fake`) son las unicas que corren en tests.
 */
class BlueskyClient
{
    /** Resuelve un handle a su DID en el entryway (`com.atproto.identity.resolveHandle`). */
    public function resolveHandle(string $handle): string
    {
        $data = $this->get($this->entryway(), 'com.atproto.identity.resolveHandle', ['handle' => $handle]);
        $did = (string) ($data['did'] ?? '');

        if (! preg_match('/^did:(plc|web):[A-Za-z0-9._:%-]+$/', $did)) {
            throw new BlueskyException('Bluesky no reconoce ese handle.', 'InvalidHandle', 400);
        }

        return $did;
    }

    /** URL del PDS de una cuenta, leida de su documento DID (`#atproto_pds`). Solo https y hosts publicos. */
    public function pdsFor(string $did): string
    {
        $url = str_starts_with($did, 'did:web:')
            ? 'https://'.rawurldecode(substr($did, 8)).'/.well-known/did.json'
            : rtrim((string) config('social.bluesky.plc_directory'), '/').'/'.$did;

        $doc = $this->send(fn (PendingRequest $http) => $http->get($url));

        foreach ((array) ($doc['service'] ?? []) as $service) {
            $isPds = ($service['type'] ?? null) === 'AtprotoPersonalDataServer' || str_ends_with((string) ($service['id'] ?? ''), '#atproto_pds');
            if ($isPds && is_string($service['serviceEndpoint'] ?? null)) {
                return self::assertPds($service['serviceEndpoint']);
            }
        }

        throw new BlueskyException('No se pudo localizar el servidor de esa cuenta de Bluesky.', 'PdsNotFound', 400);
    }

    /**
     * Crea una sesion con handle/DID y app password.
     *
     * @return array<string, mixed> accessJwt, refreshJwt, did, handle, emailConfirmed...
     */
    public function createSession(string $pds, string $identifier, string $password): array
    {
        return $this->post($pds, 'com.atproto.server.createSession', ['identifier' => $identifier, 'password' => $password]);
    }

    /** @return array<string, mixed> */
    public function refreshSession(string $pds, string $refreshJwt): array
    {
        return $this->post($pds, 'com.atproto.server.refreshSession', [], $refreshJwt);
    }

    /**
     * Consulta XRPC (GET).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $host, string $nsid, array $query = [], ?string $token = null): array
    {
        return $this->send(fn (PendingRequest $http) => ($token ? $http->withToken($token) : $http)->get(rtrim($host, '/').'/xrpc/'.$nsid, $query));
    }

    /**
     * Procedimiento XRPC (POST JSON).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $host, string $nsid, array $body = [], ?string $token = null): array
    {
        return $this->send(function (PendingRequest $http) use ($host, $nsid, $body, $token) {
            $request = $token ? $http->withToken($token) : $http;

            return $body === []
                ? $request->withBody('', 'application/json')->post(rtrim($host, '/').'/xrpc/'.$nsid)
                : $request->asJson()->post(rtrim($host, '/').'/xrpc/'.$nsid, $body);
        });
    }

    /**
     * Sube un blob (imagen o video) al PDS: cuerpo binario con su Content-Type. Devuelve el objeto `blob` (ref, mimeType, size).
     *
     * @return array<string, mixed>
     */
    public function uploadBlob(string $pds, string $token, string $bytes, string $mime): array
    {
        $data = $this->send(fn (PendingRequest $http) => $http->withToken($token)->withBody($bytes, $mime)->post(rtrim($pds, '/').'/xrpc/com.atproto.repo.uploadBlob'));
        $blob = $data['blob'] ?? null;

        return is_array($blob) ? $blob : throw new BlueskyException('Bluesky no devolvio el blob subido.', 'InvalidBlob', 502);
    }

    /**
     * Crea un registro en el repositorio de la cuenta. `$rkey` null = lo asigna el PDS.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed> uri, cid
     */
    public function createRecord(string $pds, string $token, string $repo, string $collection, array $record, ?string $rkey = null): array
    {
        return $this->post($pds, 'com.atproto.repo.createRecord', array_filter([
            'repo' => $repo, 'collection' => $collection, 'rkey' => $rkey, 'record' => $record,
        ], fn ($v) => $v !== null), $token);
    }

    /**
     * Registro de un repositorio, o null si no existe (`RecordNotFound`). Lectura publica: no necesita sesion.
     *
     * @return ?array<string, mixed> uri, cid, value
     */
    public function getRecord(string $pds, string $repo, string $collection, string $rkey): ?array
    {
        try {
            return $this->get($pds, 'com.atproto.repo.getRecord', ['repo' => $repo, 'collection' => $collection, 'rkey' => $rkey]);
        } catch (BlueskyException $e) {
            if ($e->errorCode === 'RecordNotFound') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Posts por URI (`app.bsky.feed.getPosts`). El parametro `uris` va REPETIDO (`uris=a&uris=b`), no como `uris[0]=a`.
     *
     * @param  list<string>  $uris  25 como maximo.
     * @return array<string, mixed>
     */
    public function getPosts(string $host, array $uris): array
    {
        $query = implode('&', array_map(fn (string $uri) => 'uris='.rawurlencode($uri), $uris));

        return $this->send(fn (PendingRequest $http) => $http->get(rtrim($host, '/').'/xrpc/app.bsky.feed.getPosts?'.$query));
    }

    /** Token de servicio (`com.atproto.server.getServiceAuth`) para que otro servicio actue en nombre de la cuenta. */
    public function serviceAuth(string $pds, string $token, string $aud, string $lxm, int $expiresIn = 1800): string
    {
        $data = $this->get($pds, 'com.atproto.server.getServiceAuth', ['aud' => $aud, 'lxm' => $lxm, 'exp' => time() + $expiresIn], $token);
        $service = (string) ($data['token'] ?? '');

        return $service !== '' ? $service : throw new BlueskyException('Bluesky no devolvio el token de servicio.', 'InvalidServiceAuth', 502);
    }

    /** @return array<string, mixed> canUpload, remainingDailyVideos, remainingDailyBytes, message, error */
    public function uploadLimits(string $serviceToken): array
    {
        return $this->get((string) config('social.bluesky.video_service'), 'app.bsky.video.getUploadLimits', [], $serviceToken);
    }

    /**
     * Sube el video en UNA peticion (cuerpo en streaming, sin cargarlo en memoria). Devuelve el `jobStatus`.
     *
     * @return array<string, mixed>
     */
    public function uploadVideo(string $serviceToken, string $did, string $name, StreamInterface $body, int $timeout): array
    {
        $url = rtrim((string) config('social.bluesky.video_service'), '/').'/xrpc/app.bsky.video.uploadVideo?'.http_build_query(['did' => $did, 'name' => $name]);

        return $this->send(fn (PendingRequest $http) => $http->withToken($serviceToken)->timeout($timeout)->withBody($body, 'video/mp4')->post($url));
    }

    /** @return array<string, mixed> `jobStatus`: jobId, state, progress, blob, error, message */
    public function jobStatus(string $jobId): array
    {
        $data = $this->get((string) config('social.bluesky.video_service'), 'app.bsky.video.getJobStatus', ['jobId' => $jobId]);

        return (array) ($data['jobStatus'] ?? $data);
    }

    public function entryway(): string
    {
        return rtrim((string) config('social.bluesky.entryway'), '/');
    }

    /** Solo https hacia un host con nombre publico: el PDS sale de un documento DID ajeno, no se confia a ciegas. */
    public static function assertPds(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $local = $host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || ! str_contains($host, '.');

        if (($parts['scheme'] ?? '') !== 'https' || $local) {
            throw new BlueskyException('El servidor de esa cuenta de Bluesky no es valido.', 'PdsInvalid', 400);
        }

        return 'https://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * @param  \Closure(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(\Closure $call): array
    {
        try {
            $response = $call(Http::acceptJson()->timeout((int) config('social.http_timeout', 15)));
        } catch (ConnectionException) {
            throw new BlueskyException('No se pudo conectar con Bluesky. Reintenta en unos minutos.', 'ConnectionFailed', 0);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($response->failed()) {
            $code = (string) ($json['error'] ?? 'UpstreamFailure');
            $message = (string) ($json['message'] ?? 'Bluesky respondio con un error.');

            throw new BlueskyException(mb_substr($message, 0, 300), $code, $response->status(), $json);
        }

        return $json;
    }
}
