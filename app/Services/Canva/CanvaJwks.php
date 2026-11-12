<?php

namespace App\Services\Canva;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verificacion de la firma de los JWT de Canva (T-26), p. ej. el `correlation_jwt` de la navegacion de retorno.
 * Fuente: https://www.canva.dev/docs/connect/return-navigation-guide/ (consultada el 2026-11-12): Canva publica sus claves publicas como
 * JWKS en `https://api.canva.com/rest/v1/connect/keys`, sin credenciales, y el JWT se valida contra ese conjunto con `audience = client_id`;
 * el payload lleva `sub`, `team_id` y `type = "rti"`. La guia no documenta `iss` (no se exige) ni nombra el algoritmo: RS256 es el de la
 * especificacion de esta tarea, sin verificar contra una respuesta real de Canva (smoke pendiente del usuario).
 *
 * Las claves se cachean; un `kid` desconocido provoca UNA renovacion por minuto como mucho (rotacion de claves sin que un `kid` inventado
 * pueda forzar una peticion por intento). Si no se pueden obtener las claves o algo no cuadra, el JWT se rechaza: nunca se acepta sin verificar.
 */
class CanvaJwks
{
    private const ALGORITHM = 'RS256';

    private const MAX_LENGTH = 4096;

    private const CACHE_KEY = 'canva.jwks';

    private const CACHE_TTL_SECONDS = 3600;

    private const REFRESH_THROTTLE_KEY = 'canva.jwks.refresh';

    private const REFRESH_THROTTLE_SECONDS = 60;

    /** Reclamaciones del JWT si la firma, la audiencia y la caducidad son validas; null en cualquier otro caso. @return array<string, mixed>|null */
    public function verify(string $jwt): ?array
    {
        $clientId = (string) config('services.canva.client_id');
        $parts = explode('.', $jwt);
        if ($clientId === '' || strlen($jwt) > self::MAX_LENGTH || count($parts) !== 3) {
            return null;
        }
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        $kid = is_array($header) ? ($header['kid'] ?? null) : null;
        if (! is_array($header) || ($header['alg'] ?? null) !== self::ALGORITHM || ! is_string($kid) || $kid === '') {
            return null;
        }

        $cold = ! Cache::has(self::CACHE_KEY); // sin cache se acaban de pedir las claves: no se vuelven a pedir en la misma llamada
        $keys = $this->keys();
        if (! isset($keys[$kid]) && ! $cold && Cache::add(self::REFRESH_THROTTLE_KEY, 1, self::REFRESH_THROTTLE_SECONDS)) {
            $keys = $this->keys(refresh: true);
        }
        if (! isset($keys[$kid])) {
            return null;
        }

        try {
            // Cada `Key` lleva su algoritmo fijo (RS256): un JWT con otro `alg` (none, HS256…) no se decodifica.
            $claims = (array) JWT::decode($jwt, [$kid => $keys[$kid]]);
        } catch (Throwable) {
            return null;
        }
        $audience = $claims['aud'] ?? null;
        if (! isset($claims['exp']) || ! in_array($clientId, (array) $audience, true)) {
            return null;
        }

        return $claims;
    }

    /** @return array<string, Key> las claves publicas por `kid` (vacio si no se pudieron obtener). */
    private function keys(bool $refresh = false): array
    {
        $set = $refresh ? null : Cache::get(self::CACHE_KEY);
        if (! is_array($set)) {
            $set = $this->fetch();
            if ($set !== []) {
                Cache::put(self::CACHE_KEY, $set, self::CACHE_TTL_SECONDS);
            }
        }
        try {
            return $set === [] ? [] : JWK::parseKeySet(['keys' => $set], self::ALGORITHM);
        } catch (Throwable) {
            Cache::forget(self::CACHE_KEY);

            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function fetch(): array
    {
        try {
            $response = Http::acceptJson()->timeout((int) config('services.canva.timeout', 20))->connectTimeout(10)
                ->get(rtrim((string) config('services.canva.api_base'), '/').'/connect/keys');
        } catch (ConnectionException) {
            Log::warning('canva.jwks_unreachable');

            return [];
        }
        if (! $response->successful()) {
            Log::warning('canva.jwks_failed', ['status' => $response->status()]);

            return [];
        }

        return array_values(array_filter((array) $response->json('keys'), fn ($k) => is_array($k) && is_string($k['kid'] ?? null) && ($k['kty'] ?? null) === 'RSA'));
    }
}
