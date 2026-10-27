<?php

namespace App\Connectors\Bluesky;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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

            throw new BlueskyException(mb_substr($message, 0, 300), $code, $response->status());
        }

        return $json;
    }
}
