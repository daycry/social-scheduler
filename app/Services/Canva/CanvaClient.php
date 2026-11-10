<?php

namespace App\Services\Canva;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de la Canva Connect API (T-16). Solo habla con `services.canva.api_base`. Las URLs que Canva devuelve (exportaciones, miniaturas)
 * NO se piden aqui: la descarga pasa por `CanvaSource`/`UrlGuard`. Los tokens llegan como parametro sensible y nunca se registran; los
 * mensajes de error no incluyen el cuerpo de la respuesta del proveedor.
 */
class CanvaClient
{
    public static function configured(): bool
    {
        return filled(config('services.canva.client_id')) && filled(config('services.canva.client_secret'));
    }

    /**
     * PKCE (S256): verificador de alta entropia (43-128 caracteres) y su desafio.
     *
     * @return array{verifier: string, challenge: string}
     */
    public static function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    public function authorizationUrl(string $state, string $challenge, string $redirectUri): string
    {
        return config('services.canva.authorize_url').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => (string) config('services.canva.client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', (array) config('services.canva.scopes')),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 's256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: string} */
    public function exchangeCode(#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier, string $redirectUri): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirectUri]);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: string} */
    public function refresh(#[\SensitiveParameter] string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function revoke(#[\SensitiveParameter] string $token): void
    {
        try {
            $this->basic()->asForm()->post($this->url('/oauth/revoke'), ['token' => $token]);
        } catch (ConnectionException) {
            // Desconectar siempre borra los tokens locales; una revocacion remota fallida no debe impedirlo.
        }
    }

    /** @return list<string> */
    public function capabilities(#[\SensitiveParameter] string $token): array
    {
        $json = $this->send($token, 'get', '/users/me/capabilities');

        return array_values(array_filter((array) ($json['capabilities'] ?? []), 'is_string'));
    }

    public function profileName(#[\SensitiveParameter] string $token): ?string
    {
        $json = $this->send($token, 'get', '/users/me/profile');
        $name = $json['profile']['display_name'] ?? null;

        return is_string($name) && $name !== '' ? mb_substr($name, 0, 120) : null;
    }

    /** @return array{items: list<array<string, mixed>>, continuation: ?string} */
    public function designs(#[\SensitiveParameter] string $token, ?string $query, ?string $continuation, int $limit = 24): array
    {
        $params = array_filter(['query' => $query, 'continuation' => $continuation, 'limit' => $limit, 'sort_by' => $query ? 'relevance' : 'modified_descending'], fn ($v) => $v !== null && $v !== '');
        $json = $this->send($token, 'get', '/designs', $params);

        return ['items' => array_values(array_filter((array) ($json['items'] ?? []), 'is_array')), 'continuation' => is_string($json['continuation'] ?? null) ? $json['continuation'] : null];
    }

    /** @return array<string, mixed> */
    public function design(#[\SensitiveParameter] string $token, string $designId): array
    {
        $json = $this->send($token, 'get', '/designs/'.rawurlencode($designId));

        return (array) ($json['design'] ?? []);
    }

    /** @return array<string, mixed> */
    public function createDesign(#[\SensitiveParameter] string $token, int $width, int $height, string $title): array
    {
        $json = $this->send($token, 'post', '/designs', null, ['design_type' => ['type' => 'custom', 'width' => $width, 'height' => $height], 'title' => $title]);

        return (array) ($json['design'] ?? []);
    }

    /** @return array<string, mixed> */
    public function createExport(#[\SensitiveParameter] string $token, string $designId, array $format): array
    {
        $json = $this->send($token, 'post', '/exports', null, ['design_id' => $designId, 'format' => $format]);

        return (array) ($json['job'] ?? []);
    }

    /** @return array<string, mixed> */
    public function exportJob(#[\SensitiveParameter] string $token, string $jobId): array
    {
        $json = $this->send($token, 'get', '/exports/'.rawurlencode($jobId));

        return (array) ($json['job'] ?? []);
    }

    /**
     * Sube los bytes de un recurso a la biblioteca de Canva (no necesita una URL publica).
     *
     * @return array<string, mixed>
     */
    public function uploadAsset(#[\SensitiveParameter] string $token, string $name, string $bytes): array
    {
        try {
            $response = $this->request($token)->withHeaders([
                'Asset-Upload-Metadata' => json_encode(['name_base64' => base64_encode(mb_substr($name, 0, 50))], JSON_THROW_ON_ERROR),
            ])->withBody($bytes, 'application/octet-stream')->post($this->url('/asset-uploads'));
        } catch (ConnectionException) {
            throw new CanvaException('No se pudo contactar con Canva. Inténtalo de nuevo en unos minutos.', 502, 'unreachable');
        }

        return (array) ($this->checked($response)['job'] ?? []);
    }

    /** @return array<string, mixed> */
    public function assetUploadJob(#[\SensitiveParameter] string $token, string $jobId): array
    {
        $json = $this->send($token, 'get', '/asset-uploads/'.rawurlencode($jobId));

        return (array) ($json['job'] ?? []);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: string} */
    private function token(#[\SensitiveParameter] array $form): array
    {
        try {
            $response = $this->basic()->asForm()->post($this->url('/oauth/token'), $form);
        } catch (ConnectionException) {
            throw new CanvaException('No se pudo contactar con Canva. Inténtalo de nuevo en unos minutos.', 502, 'unreachable');
        }
        if (! $response->successful()) {
            $status = $response->status();
            // Solo un 400/401 con invalid_grant (o credenciales invalidas) caduca la conexion; 5xx, 429 y demas son transitorios y se reintentan.
            if (in_array($status, [400, 401], true) && in_array((string) $response->json('error'), ['invalid_grant', 'invalid_client', 'unauthorized_client'], true)) {
                throw new CanvaException('Canva ha rechazado la autorización. Vuelve a conectar la cuenta.', 401, 'token_rejected');
            }

            throw match (true) {
                $status === 429 => new CanvaException('Canva está limitando las peticiones. Espera un minuto e inténtalo de nuevo.', 429, 'rate_limited'),
                $status >= 500 => new CanvaException('Canva no responde ahora mismo. Inténtalo de nuevo más tarde.', 502, 'unavailable'),
                default => new CanvaException('Canva no ha aceptado la autorización. Inténtalo de nuevo.', 422, 'token_error'),
            };
        }
        $json = (array) $response->json();
        if (! is_string($json['access_token'] ?? null) || $json['access_token'] === '') {
            throw new CanvaException('Canva no devolvió ninguna credencial. Vuelve a conectar la cuenta.', 502, 'token_missing');
        }

        return [
            'access_token' => $json['access_token'],
            'refresh_token' => is_string($json['refresh_token'] ?? null) ? $json['refresh_token'] : null,
            'expires_in' => max(60, (int) ($json['expires_in'] ?? 14400)),
            'scope' => (string) ($json['scope'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function send(#[\SensitiveParameter] string $token, string $method, string $path, ?array $query = null, ?array $json = null): array
    {
        $request = $this->request($token);
        try {
            $response = $method === 'get'
                ? $request->get($this->url($path), $query ?? [])
                : $request->asJson()->post($this->url($path), $json ?? []);
        } catch (ConnectionException) {
            throw new CanvaException('No se pudo contactar con Canva. Inténtalo de nuevo en unos minutos.', 502, 'unreachable');
        }

        return $this->checked($response);
    }

    /** @return array<string, mixed> */
    private function checked(Response $response): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }
        $code = (string) ($response->json('code') ?? '');
        $message = (string) ($response->json('message') ?? '');
        $status = $response->status();

        throw match (true) {
            $status === 401 => new CanvaException('La conexión con Canva ha caducado. Vuelve a conectar la cuenta.', 401, 'unauthorized'),
            $status === 403 && str_contains($message, 'capabilit') => new CanvaException('Tu plan de Canva no incluye esta función.', 403, 'capability_required'),
            $status === 403 => new CanvaException('Canva no permite esta operación con los permisos concedidos. Vuelve a conectar la cuenta y acepta todos los permisos.', 403, 'permission_denied'),
            $status === 404 => new CanvaException('Canva no encuentra ese elemento. Puede haberse borrado.', 404, 'not_found'),
            $status === 429 => new CanvaException('Canva está limitando las peticiones. Espera un minuto e inténtalo de nuevo.', 429, 'rate_limited'),
            $status >= 500 => new CanvaException('Canva no responde ahora mismo. Inténtalo de nuevo más tarde.', 502, 'unavailable'),
            default => new CanvaException('Canva ha rechazado la petición'.($code !== '' ? " ($code)" : '').'.', 422, 'rejected'),
        };
    }

    private function request(#[\SensitiveParameter] string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout((int) config('services.canva.timeout', 20))->connectTimeout(10);
    }

    private function basic(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.canva.client_id'), (string) config('services.canva.client_secret'))
            ->acceptJson()->timeout((int) config('services.canva.timeout', 20))->connectTimeout(10);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.canva.api_base'), '/').$path;
    }
}
