<?php

namespace App\Services\Canva;

use App\Models\Brand;
use App\Models\Canva\CanvaConnection;
use App\Models\Canva\CanvaJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conexion de Canva por marca (T-16). OAuth 2.0 con PKCE: cada intento tiene un `state` de UN SOLO USO y su verificador PKCE,
 * guardados en un registro de cache (con candado corto) ligado al usuario y a la marca; el verificador nunca va al navegador.
 * Los tokens se cifran, se refrescan antes de caducar (el refresh token de Canva solo vale una vez, asi que se serializa) y se
 * borran al desconectar.
 */
class CanvaConnections
{
    private const ATTEMPT_TTL = 900;

    private const MAX_ATTEMPTS = 10;

    public function __construct(private readonly CanvaClient $client) {}

    /** Inicia un intento y devuelve la URL de autorizacion de Canva. */
    public function start(User $user, Brand $brand, string $redirectUri): string
    {
        $this->assertConfigured();
        $state = Str::random(40);
        $pkce = CanvaClient::pkce();
        $key = $this->key($user);
        Cache::lock($key.'.lock', 5)->block(5, function () use ($key, $state, $pkce, $brand, $user): void {
            $attempts = array_filter((array) Cache::get($key, []), fn ($a) => is_array($a) && ($a['issued_at'] ?? 0) > now()->timestamp - self::ATTEMPT_TTL);
            $attempts[hash('sha256', $state)] = [
                'brand_id' => $brand->id, 'user_id' => $user->id, 'issued_at' => now()->timestamp, 'verifier' => Crypt::encryptString($pkce['verifier']),
            ];
            Cache::put($key, array_slice($attempts, -self::MAX_ATTEMPTS, null, true), self::ATTEMPT_TTL);
        });

        return $this->client->authorizationUrl($state, $pkce['challenge'], $redirectUri);
    }

    /**
     * Consume el intento (una sola vez) y devuelve la marca y el verificador. Un state desconocido, repetido, caducado o de otro usuario es null.
     *
     * @return array{brand_id: int, verifier: string}|null
     */
    public function take(User $user, string $state): ?array
    {
        if ($state === '' || strlen($state) > 128) {
            return null;
        }
        $key = $this->key($user);

        return Cache::lock($key.'.lock', 5)->block(5, function () use ($key, $state, $user): ?array {
            $attempts = (array) Cache::get($key, []);
            $hash = hash('sha256', $state);
            $attempt = $attempts[$hash] ?? null;
            if (! is_array($attempt)) {
                return null;
            }
            unset($attempts[$hash]);
            Cache::put($key, $attempts, self::ATTEMPT_TTL);
            if (($attempt['user_id'] ?? null) !== $user->id || ($attempt['issued_at'] ?? 0) <= now()->timestamp - self::ATTEMPT_TTL) {
                return null;
            }

            return ['brand_id' => (int) $attempt['brand_id'], 'verifier' => Crypt::decryptString($attempt['verifier'])];
        });
    }

    /** Canjea el codigo y guarda (o sustituye) la conexion de la marca; consulta tambien el perfil y las capacidades del plan. */
    public function complete(User $user, Brand $brand, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier, string $redirectUri): CanvaConnection
    {
        $this->assertConfigured();
        $token = $this->client->exchangeCode($code, $verifier, $redirectUri);
        $scopes = $token['scope'] === '' ? (array) config('services.canva.scopes') : array_values(array_filter(explode(' ', $token['scope'])));
        $name = in_array('profile:read', $scopes, true) ? $this->safely(fn () => $this->client->profileName($token['access_token'])) : null;
        $capabilities = in_array('profile:read', $scopes, true) ? $this->safely(fn () => $this->client->capabilities($token['access_token'])) : null;

        $previous = null;
        $saved = DB::transaction(function () use ($user, $brand, $token, $scopes, $name, $capabilities, &$previous): CanvaConnection {
            $connection = CanvaConnection::withoutUserScope()->where('brand_id', $brand->id)->first() ?? new CanvaConnection;
            $previous = $connection->exists ? ($connection->refresh_token ?: $connection->access_token) : null;
            $connection->forceFill([
                'user_id' => $user->id, 'brand_id' => $brand->id,
                'access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token'],
                'expires_at' => now()->addSeconds($token['expires_in']), 'scopes' => $scopes, 'display_name' => $name,
                'capabilities' => $capabilities, 'capabilities_at' => $capabilities === null ? null : now(), 'status' => 'active',
            ])->save();

            return $connection;
        });
        // Los tokens anteriores dejan de servir (CWE-613): revocacion en best-effort, nunca impide conectar.
        if ($previous !== null) {
            $this->revokeQuietly($previous);
        }

        return $saved;
    }

    private function revokeQuietly(string $token): void
    {
        try {
            $this->client->revoke($token);
        } catch (\Throwable) {
            // La revocacion remota es accesoria.
        }
    }

    public function find(User $user, Brand $brand): ?CanvaConnection
    {
        return CanvaConnection::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->first();
    }

    public function disconnect(User $user, Brand $brand): bool
    {
        $connection = $this->find($user, $brand);
        if ($connection === null) {
            return false;
        }
        // Con el candado del refresco se relee la conexion: se revoca siempre el refresh token VIGENTE (CWE-613).
        $revoke = Cache::lock('canva.refresh.'.$connection->id, 15)->block(10, function () use ($connection): ?string {
            $current = CanvaConnection::withoutUserScope()->find($connection->id);
            if ($current === null) {
                return null;
            }
            $token = $current->refresh_token ?: $current->access_token;
            $current->delete();

            return $token;
        });
        CanvaJob::withoutUserScope()->where('brand_id', $brand->id)->whereIn('status', [CanvaJob::QUEUED, CanvaJob::PROCESSING])
            ->update(['status' => CanvaJob::FAILED, 'error_code' => 'not_connected', 'error' => 'Canva se desconectó mientras se procesaba el trabajo.']);
        if ($revoke !== null && CanvaClient::configured()) {
            $this->revokeQuietly($revoke);
        }

        return true;
    }

    /** Token de acceso vigente; si caduca en menos de un minuto lo refresca (serializado: el refresh token solo se puede usar una vez). */
    public function accessToken(CanvaConnection $connection): string
    {
        if ($connection->expires_at === null || $connection->expires_at->gt(now()->addMinute())) {
            return $connection->access_token;
        }

        return Cache::lock('canva.refresh.'.$connection->id, 15)->block(10, function () use ($connection): string {
            $connection->refresh();
            if ($connection->expires_at !== null && $connection->expires_at->gt(now()->addMinute())) {
                return $connection->access_token;
            }
            if (blank($connection->refresh_token)) {
                $this->expire($connection);
            }

            try {
                $token = $this->client->refresh((string) $connection->refresh_token);
            } catch (CanvaException $e) {
                if ($e->reason === 'token_rejected') {
                    $this->expire($connection);
                }

                throw $e;
            }
            $connection->forceFill([
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
                'expires_at' => now()->addSeconds($token['expires_in']),
            ])->save();

            return $connection->access_token;
        });
    }

    /** Capacidades del plan, con una cache de 15 min en la conexion (Canva limita este endpoint a 10 por minuto). @return list<string> */
    public function capabilities(CanvaConnection $connection, bool $force = false): array
    {
        if (! in_array('profile:read', (array) $connection->scopes, true)) {
            return [];
        }
        if (! $force && $connection->capabilities_at !== null && $connection->capabilities_at->gt(now()->subMinutes(15))) {
            return array_values((array) $connection->capabilities);
        }
        $caps = $this->client->capabilities($this->accessToken($connection));
        $connection->forceFill(['capabilities' => $caps, 'capabilities_at' => now()])->save();

        return $caps;
    }

    private function expire(CanvaConnection $connection): never
    {
        $connection->forceFill(['status' => 'expired'])->save();

        throw new CanvaException('La conexión con Canva ha caducado. Vuelve a conectar la cuenta.', 401, 'unauthorized');
    }

    private function assertConfigured(): void
    {
        if (! CanvaClient::configured()) {
            throw new CanvaException('La integración con Canva no está configurada en esta instalación.', 404, 'not_configured');
        }
    }

    private function key(User $user): string
    {
        return 'canva.oauth.'.$user->id;
    }

    private function safely(callable $call): mixed
    {
        try {
            return $call();
        } catch (CanvaException) {
            return null; // el perfil y las capacidades son accesorios: conectar no depende de ellos
        }
    }
}
