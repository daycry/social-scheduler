<?php

namespace App\Connectors\Bluesky;

use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Models\SocialAccount;
use App\Services\SocialAccountService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sesion de una cuenta de Bluesky (D1: app password cifrada). Aislada aqui para poder cambiarla por OAuth de atproto
 * sin tocar publicador ni metricas.
 *
 *  - access_token = accessJwt (dura horas) y refresh_token = refreshJwt; `credential_secret` = app password.
 *  - Renovacion: `refreshSession`; si responde ExpiredToken/InvalidToken, `createSession` con la app password; si esta tambien
 *    falla, ReconnectRequiredException.
 *  - Un bloqueo por cuenta (`bsky-session:{id}`) evita que el job de refresco y el publicador roten el refreshJwt a la vez.
 */
class BlueskySession
{
    public function __construct(private readonly BlueskyClient $client) {}

    /**
     * Crea la sesion inicial a partir de handle y app password. No guarda nada.
     *
     * @return array{did: string, handle: string, pds: string, session: array<string, mixed>}
     */
    public function open(string $identifier, string $password): array
    {
        $identifier = ltrim(trim($identifier), '@');
        $did = str_starts_with($identifier, 'did:') ? $identifier : $this->client->resolveHandle(strtolower($identifier));
        $pds = $this->client->pdsFor($did);
        $session = $this->client->createSession($pds, $did, $password);

        // CWE-345: el DID de la sesion tiene que ser el que se resolvio. Un PDS propio podria devolver el de otra cuenta y suplantarla.
        if (($session['did'] ?? null) !== $did) {
            throw new BlueskyException('Bluesky devolvio una sesion que no corresponde a esa cuenta.', 'DidMismatch', 400);
        }

        return ['did' => $did, 'handle' => $this->verifiedHandle($did, $identifier, (string) ($session['handle'] ?? '')), 'pds' => $pds, 'session' => $session];
    }

    /**
     * CWE-345: el handle que declara el PDS no se da por bueno. Solo se muestra si resuelve (por el entryway fijo) al mismo DID;
     * si no, o no se puede comprobar, se usa el DID.
     */
    private function verifiedHandle(string $did, string $identifier, string $claimed): string
    {
        $claimed = strtolower(trim($claimed));
        if ($claimed === '') {
            return str_starts_with($identifier, 'did:') ? $did : strtolower($identifier);
        }

        // El handle escrito por el usuario ya se resolvio a este DID al abrir la sesion: no hace falta repetirlo.
        if (! str_starts_with($identifier, 'did:') && $claimed === strtolower($identifier)) {
            return $claimed;
        }

        try {
            return $this->client->resolveHandle($claimed) === $did ? $claimed : $did;
        } catch (\Throwable) {
            return $did;
        }
    }

    /** Fecha de caducidad (`exp`) de un JWT. Se lee SIN verificar la firma: solo sirve para planificar la renovacion. */
    public static function jwtExpiry(string $jwt): ?CarbonInterface
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $exp = is_array($payload) ? ($payload['exp'] ?? null) : null;

        return is_numeric($exp) ? CarbonImmutable::createFromTimestampUTC((int) $exp) : null;
    }

    /** PDS de la cuenta (guardado en `meta.pds` al conectar). */
    public static function pds(SocialAccount $account): string
    {
        $pds = (string) ($account->meta['pds'] ?? '');

        return $pds !== '' ? $pds : throw new ReconnectRequiredException('La cuenta de Bluesky no tiene servidor guardado: vuelve a conectarla.');
    }

    /**
     * Renueva las credenciales de la cuenta bajo el bloqueo por cuenta. Si otro proceso ya la renovo mientras se esperaba el
     * bloqueo, devuelve esas credenciales sin llamar a la red.
     *
     * @throws ReconnectRequiredException si ni el refreshJwt ni la app password sirven.
     */
    public function refresh(SocialAccount $account): RefreshResult
    {
        return $this->locked($account, fn (): RefreshResult => $this->refreshing($account));
    }

    /** @return RefreshResult Las credenciales vigentes: las de otro proceso si ya renovo, o las renovadas ahora. */
    private function refreshing(SocialAccount $account): RefreshResult
    {
        $fresh = SocialAccount::withoutUserScope()->find($account->id) ?? $account;

        if ($fresh->access_token !== $account->access_token && ! self::expiresSoon($fresh)) {
            return new RefreshResult((string) $fresh->access_token, $fresh->refresh_token, $fresh->token_expires_at);
        }

        return $this->renew($fresh);
    }

    /**
     * Ejecuta una llamada autenticada. Si el PDS responde con una sesion caducada, renueva (guardando las credenciales nuevas)
     * y repite UNA vez.
     *
     * @template T
     *
     * @param  Closure(string $accessToken, string $pds): T  $call
     * @return T
     */
    public function call(SocialAccount $account, Closure $call): mixed
    {
        try {
            return $call((string) $account->access_token, self::pds($account));
        } catch (BlueskyException $e) {
            if (! $e->isSessionError()) {
                throw $e;
            }
        }

        // Renovar Y guardar dentro del MISMO bloqueo: si se guardara fuera, otro proceso podria leer el refreshJwt viejo (ya rotado)
        // y renovar de mas.
        $this->locked($account, function () use ($account): void {
            app(SocialAccountService::class)->applyRefresh($account, $this->refreshing($account));
        });

        return $call((string) $account->access_token, self::pds($account));
    }

    private function renew(SocialAccount $account): RefreshResult
    {
        $pds = self::pds($account);

        if ($account->refresh_token) {
            try {
                return $this->fromSession($this->client->refreshSession($pds, (string) $account->refresh_token), $account);
            } catch (BlueskyException $e) {
                if (! $e->isSessionError() && ! in_array($e->errorCode, ['AccountTakedown', 'AccountDeactivated'], true)) {
                    throw $e; // red o 5xx: transitorio
                }
                Log::info('bluesky.refresh_session_rejected', ['account_id' => $account->id, 'error' => $e->errorCode]);
            }
        }

        $password = (string) $account->credential_secret;
        if ($password === '') {
            throw new ReconnectRequiredException('La sesion de Bluesky caduco y no hay app password guardada: vuelve a conectar la cuenta.');
        }

        try {
            return $this->fromSession($this->client->createSession($pds, (string) ($account->meta['did'] ?? $account->external_id), $password), $account);
        } catch (BlueskyException $e) {
            if ($e->isTransient()) {
                throw $e;
            }

            throw new ReconnectRequiredException('Bluesky rechazo la app password (puede haberse revocado): vuelve a conectar la cuenta con una nueva.');
        }
    }

    /** @param array<string, mixed> $session */
    private function fromSession(array $session, SocialAccount $account): RefreshResult
    {
        $access = (string) ($session['accessJwt'] ?? '');
        if ($access === '') {
            throw new BlueskyException('Bluesky no devolvio una sesion valida.', 'InvalidSession', 502);
        }

        $expected = (string) ($account->meta['did'] ?? $account->external_id);
        if (isset($session['did']) && $session['did'] !== $expected) {
            throw new BlueskyException('Bluesky devolvio una sesion que no corresponde a esa cuenta.', 'DidMismatch', 400);
        }

        return new RefreshResult(
            accessToken: $access,
            refreshToken: ($session['refreshJwt'] ?? null) ?: $account->refresh_token,
            tokenExpiresAt: self::jwtExpiry($access),
            // Nulo a proposito: `RefreshSocialAccount::permanentReason()` marcaria la reconexion cuando aun se puede recrear la sesion.
            refreshExpiresAt: null,
        );
    }

    private static function expiresSoon(SocialAccount $account): bool
    {
        return $account->token_expires_at === null || $account->token_expires_at->lte(now()->addMinutes(5));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(SocialAccount $account, Closure $callback): mixed
    {
        try {
            return Cache::lock("bsky-session:{$account->id}", 30)->block(10, $callback);
        } catch (LockTimeoutException) {
            throw new BlueskyException('La sesion de Bluesky esta siendo renovada por otro proceso; se reintentara.', 'SessionLocked', 503);
        }
    }
}
