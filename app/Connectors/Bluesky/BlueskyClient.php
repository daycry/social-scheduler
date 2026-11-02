<?php

namespace App\Connectors\Bluesky;

use App\Services\Media\UrlGuard;
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
        $data = $this->get($this->hosts()->fixed($this->entryway()), 'com.atproto.identity.resolveHandle', ['handle' => $handle]);
        $did = (string) ($data['did'] ?? '');

        if (! preg_match('/^did:(plc|web):[A-Za-z0-9._:%-]+$/', $did)) {
            throw new BlueskyException('Bluesky no reconoce ese handle.', 'InvalidHandle', 400);
        }

        return $did;
    }

    /**
     * URL del PDS de una cuenta, leida de su documento DID (`#atproto_pds`). Solo https/443 y hosts publicos, y el documento tiene que
     * ser el del DID pedido.
     */
    public function pdsFor(string $did): string
    {
        if (str_starts_with($did, 'did:web:')) {
            $url = BlueskyHosts::didWebUrl($did);
        } elseif (preg_match('/^did:plc:[A-Za-z0-9]+$/', $did)) {
            $url = $this->hosts()->fixed(rtrim((string) config('social.bluesky.plc_directory'), '/')).'/'.$did;
        } else {
            throw new BlueskyException('Ese identificador de Bluesky no es valido.', 'InvalidDid', 400);
        }

        $doc = $this->send($url, fn (PendingRequest $http, string $u) => $http->get($u));

        if (($doc['id'] ?? null) !== $did) {
            throw new BlueskyException('No se pudo localizar el servidor de esa cuenta de Bluesky.', 'PdsNotFound', 400);
        }

        foreach ((array) ($doc['service'] ?? []) as $service) {
            $isPds = ($service['type'] ?? null) === 'AtprotoPersonalDataServer' || str_ends_with((string) ($service['id'] ?? ''), '#atproto_pds');
            if ($isPds && is_string($service['serviceEndpoint'] ?? null)) {
                return $this->assertPds($service['serviceEndpoint']);
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
        return $this->send(rtrim($host, '/').'/xrpc/'.$nsid, fn (PendingRequest $http, string $u) => ($token ? $http->withToken($token) : $http)->get($u, $query));
    }

    /**
     * Procedimiento XRPC (POST JSON).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $host, string $nsid, array $body = [], ?string $token = null): array
    {
        return $this->send(rtrim($host, '/').'/xrpc/'.$nsid, function (PendingRequest $http, string $u) use ($body, $token) {
            $request = $token ? $http->withToken($token) : $http;

            return $body === []
                ? $request->withBody('', 'application/json')->post($u)
                : $request->asJson()->post($u, $body);
        });
    }

    /**
     * Sube un blob (imagen o video) al PDS: cuerpo binario con su Content-Type. Devuelve el objeto `blob` (ref, mimeType, size).
     *
     * @return array<string, mixed>
     */
    public function uploadBlob(string $pds, string $token, string $bytes, string $mime): array
    {
        $data = $this->send(rtrim($pds, '/').'/xrpc/com.atproto.repo.uploadBlob', fn (PendingRequest $http, string $u) => $http->withToken($token)->withBody($bytes, $mime)->post($u));
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

        return $this->send(rtrim($host, '/').'/xrpc/app.bsky.feed.getPosts', fn (PendingRequest $http, string $u) => $http->get($u.'?'.$query));
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
        return $this->get($this->videoService(), 'app.bsky.video.getUploadLimits', [], $serviceToken);
    }

    /**
     * Sube el video en UNA peticion (cuerpo en streaming, sin cargarlo en memoria). Devuelve el `jobStatus`.
     *
     * @return array<string, mixed>
     */
    public function uploadVideo(string $serviceToken, string $did, string $name, StreamInterface $body, int $timeout): array
    {
        $url = $this->videoService().'/xrpc/app.bsky.video.uploadVideo';

        return $this->send($url, fn (PendingRequest $http, string $u) => $http->withToken($serviceToken)->timeout($timeout)->withBody($body, 'video/mp4')->post($u.'?'.http_build_query(['did' => $did, 'name' => $name])));
    }

    /** @return array<string, mixed> `jobStatus`: jobId, state, progress, blob, error, message */
    public function jobStatus(string $jobId): array
    {
        $data = $this->get($this->videoService(), 'app.bsky.video.getJobStatus', ['jobId' => $jobId]);

        return (array) ($data['jobStatus'] ?? $data);
    }

    public function entryway(): string
    {
        return rtrim((string) config('social.bluesky.entryway'), '/');
    }

    /** AppView de lectura (host fijo y permitido). */
    public function appview(): string
    {
        return $this->hosts()->fixed(rtrim((string) config('social.bluesky.appview'), '/'));
    }

    /** Servicio de video (host fijo y permitido). */
    public function videoService(): string
    {
        return $this->hosts()->fixed(rtrim((string) config('social.bluesky.video_service'), '/'));
    }

    /**
     * El PDS sale de un documento DID ajeno: se normaliza a `https://host` (sin puerto ni ruta) tras validarlo (DNS publico). Se
     * vuelve a validar en cada peticion, no solo al conectar.
     */
    public function assertPds(string $url): string
    {
        $v = $this->hosts()->validate($url);
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (($path !== '' && $path !== '/') || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new BlueskyException('El servidor de esa cuenta de Bluesky no es valido.', 'PdsInvalid', 400);
        }

        return 'https://'.$v->host;
    }

    private function hosts(): BlueskyHosts
    {
        return new BlueskyHosts(app(UrlGuard::class));
    }

    /**
     * Toda peticion pasa por la guardia SSRF: la URL se valida (DNS incluido) justo antes de pedirla, la conexion se fija a la IP
     * validada y no se siguen redirecciones (una 3xx es un error: ningun endpoint XRPC legitimo redirige).
     *
     * @param  \Closure(PendingRequest, string): Response  $call  Recibe el cliente y la URL validada.
     * @return array<string, mixed>
     */
    private function send(string $url, \Closure $call): array
    {
        $validated = $this->hosts()->validate($url);
        $http = Http::acceptJson()->timeout((int) config('social.http_timeout', 15))->withoutRedirecting()
            ->withOptions(app(UrlGuard::class)->requestOptions($validated));

        try {
            $response = $call($http, $validated->url);
        } catch (ConnectionException) {
            throw new BlueskyException('No se pudo conectar con Bluesky. Reintenta en unos minutos.', 'ConnectionFailed', 0);
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            throw new BlueskyException('Bluesky respondio con una redireccion que no se admite.', 'RedirectBlocked', 400);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($response->failed()) {
            $code = (string) ($json['error'] ?? '');
            $code = preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $code) ? $code : 'UpstreamFailure';
            // El mensaje lo escribe el servidor remoto: se limpia (sin control ni saltos) y se acota; un cuerpo que no sea JSON nunca se devuelve.
            $message = is_string($json['message'] ?? null) ? trim((string) preg_replace('/[\x00-\x1f\x7f\s]+/u', ' ', $json['message'])) : '';

            throw new BlueskyException($message !== '' ? mb_substr($message, 0, 200) : 'Bluesky respondio con un error.', $code, $response->status(), $json);
        }

        return $json;
    }
}
