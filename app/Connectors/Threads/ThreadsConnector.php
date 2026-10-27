<?php

namespace App\Connectors\Threads;

use App\Connectors\Contracts\OAuthConnector;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Conector de Threads (C-02, T1): OAuth propio en `threads.com` con el ID y el secreto DE THREADS (la app de Meta tiene dos pares).
 * Codigo -> token corto (1 h) -> token largo (60 dias, `th_exchange_token`), que va en `access_token` con su caducidad. No hay
 * `refresh_token`: el propio token largo se renueva con `th_refresh_token` (con >= 24 h de antiguedad y sin caducar). `external_id` es
 * el ID de usuario de Threads. No hereda de `MetaConnector`: un permiso `threads_*` no pedible no puede romper el login de Facebook.
 */
class ThreadsConnector implements OAuthConnector
{
    public function __construct(private readonly ThreadsClient $client = new ThreadsClient) {}

    public function provider(): string
    {
        return 'threads';
    }

    public function networks(): array
    {
        return [Network::Threads];
    }

    public function refreshMarginHours(): int
    {
        return (int) config('social.refresh_margin_hours.threads', 168);
    }

    public function authorizationUrl(string $state, string $redirectUri, bool $rerequest = false): string
    {
        return rtrim((string) config('social.threads.auth_host', 'https://threads.com'), '/').'/oauth/authorize?'.http_build_query([
            'client_id' => config('services.threads.client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(',', (array) config('social.threads.scopes')),
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        // Meta anade «#_» al final del codigo en la redireccion; no forma parte del codigo.
        $code = preg_replace('/\#_$/', '', $code) ?? $code;

        $short = $this->client->exchange('POST', 'oauth/access_token', [
            'client_id' => config('services.threads.client_id'),
            'client_secret' => config('services.threads.client_secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (empty($short['access_token'])) {
            throw new ConnectorException('Threads no devolvio un token de acceso. Vuelve a intentar la conexion.');
        }

        $long = $this->client->exchange('GET', 'access_token', [
            'grant_type' => 'th_exchange_token',
            'client_secret' => config('services.threads.client_secret'),
            'access_token' => $short['access_token'],
        ]);

        if (empty($long['access_token'])) {
            throw new ConnectorException('Threads no devolvio un token de larga duracion.');
        }

        $token = (string) $long['access_token'];

        try {
            $me = $this->client->get('me', ['fields' => 'id,username,name,threads_profile_picture_url'], $token, true);
        } catch (ReconnectRequiredException $e) {
            throw $e;
        } catch (ConnectorException) {
            throw new ConnectorException('No se pudo leer el perfil de Threads. Vuelve a intentar la conexion en unos minutos.');
        }

        $id = (string) ($me['id'] ?? $short['user_id'] ?? '');

        if ($id === '') {
            throw new ConnectorException('Threads no devolvio el identificador de la cuenta.');
        }

        $username = isset($me['username']) ? (string) $me['username'] : null;

        return [new ConnectionCandidate(
            network: Network::Threads,
            externalId: $id,
            name: (string) (($me['name'] ?? '') !== '' ? $me['name'] : ($username ?? 'Threads '.$id)),
            username: $username,
            accessToken: $token,
            refreshToken: null,
            tokenExpiresAt: $this->expiry($long['expires_in'] ?? null),
            // Threads no lista los permisos concedidos: se guardan los pedidos (⚠️ confirmar en el smoke, T-64).
            scopes: (array) config('social.threads.scopes'),
        )];
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        $token = (string) $account->access_token;

        if ($token === '') {
            throw new ReconnectRequiredException('La cuenta de Threads no tiene token: reconecta la cuenta.');
        }

        $data = $this->client->exchange('GET', 'refresh_access_token', [
            'grant_type' => 'th_refresh_token',
            'access_token' => $token,
        ]);

        if (empty($data['access_token'])) {
            throw new ConnectorException('Threads no devolvio el token renovado.');
        }

        return new RefreshResult(
            accessToken: (string) $data['access_token'],
            refreshToken: null,
            tokenExpiresAt: $this->expiry($data['expires_in'] ?? null) ?? $account->token_expires_at,
        );
    }

    private function expiry(mixed $seconds): ?CarbonImmutable
    {
        return is_numeric($seconds) && (int) $seconds > 0 ? CarbonImmutable::now()->addSeconds((int) $seconds) : null;
    }
}
