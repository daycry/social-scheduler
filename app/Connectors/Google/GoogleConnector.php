<?php

namespace App\Connectors\Google;

use App\Connectors\Contracts\Connector;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Conector de Google para YouTube (Data + Analytics) con cliente HTTP propio.
 *
 * Decision G1 (spike-report.md): consentimiento de Google «En produccion» sin verificar, de modo que
 * el refresh token NO caduca a los 7 dias (el aviso de app no verificada se acepta para uso propio).
 * Si el proyecto siguiera en «Testing», el refresco devolveria `invalid_grant` a los 7 dias y la
 * cuenta pasaria a `requiere_reconexion` (mismo camino que una revocacion).
 *
 * `access_type=offline` + `prompt=consent` son imprescindibles para recibir siempre un refresh token.
 */
class GoogleConnector implements Connector
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const CHANNELS_URL = 'https://www.googleapis.com/youtube/v3/channels';

    private const SCOPE_YOUTUBE = 'https://www.googleapis.com/auth/youtube';

    public function provider(): string
    {
        return 'google';
    }

    public function networks(): array
    {
        return [Network::YouTube];
    }

    public function refreshMarginHours(): int
    {
        return (int) config('social.refresh_margin_hours.google', 1);
    }

    public function authorizationUrl(string $state, string $redirectUri, bool $rerequest = false): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', config('social.google.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $token = $this->token([
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $scopes = preg_split('/\s+/', trim((string) ($token['scope'] ?? ''))) ?: [];

        if ($scopes !== [''] && $scopes !== [] && ! in_array(self::SCOPE_YOUTUBE, $scopes, true)) {
            throw new ConnectorException('Falta el permiso de gestion de YouTube. Vuelve a conectar y acepta todos los permisos solicitados.');
        }

        if (empty($token['refresh_token'])) {
            throw new ConnectorException('Google no devolvio un token de renovacion. Revoca el acceso de la aplicacion en tu cuenta de Google y vuelve a conectar.');
        }

        $channels = $this->channels($token['access_token']);

        if ($channels === []) {
            throw new ConnectorException('La cuenta de Google no tiene ningun canal de YouTube. Crea un canal y vuelve a conectar.');
        }

        $expiresAt = $this->expiry($token);

        return array_map(fn (array $channel) => new ConnectionCandidate(
            network: Network::YouTube,
            externalId: (string) $channel['id'],
            name: $channel['snippet']['title'] ?? 'Canal '.$channel['id'],
            username: $channel['snippet']['customUrl'] ?? null,
            accessToken: $token['access_token'],
            refreshToken: $token['refresh_token'],
            tokenExpiresAt: $expiresAt,
            scopes: $scopes === [''] ? [] : $scopes,
        ), $channels);
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        if (! $account->refresh_token) {
            throw new ReconnectRequiredException('La cuenta de YouTube no tiene token de renovacion: reconecta el canal.');
        }

        $token = $this->token([
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        return new RefreshResult(
            accessToken: $token['access_token'],
            refreshToken: $token['refresh_token'] ?? null,
            tokenExpiresAt: $this->expiry($token),
        );
    }

    /** @return list<array<string, mixed>> */
    private function channels(string $accessToken): array
    {
        try {
            $response = Http::timeout($this->timeout())->acceptJson()->withToken($accessToken)
                ->get(self::CHANNELS_URL, ['part' => 'snippet', 'mine' => 'true']);
        } catch (ConnectionException) {
            throw new ConnectorException('No se pudo contactar con YouTube. Vuelve a intentarlo.');
        }

        if (! $response->successful()) {
            $reason = $response->json('error.errors.0.reason') ?? $response->json('error.status') ?? (string) $response->status();
            Log::warning('google.channels_error', ['status' => $response->status(), 'reason' => $reason]);

            throw new ConnectorException('YouTube rechazo la consulta del canal ('.$reason.'). Revisa que la API de YouTube Data este habilitada en el proyecto de Google.');
        }

        return $response->json('items') ?? [];
    }

    /**
     * POST al endpoint de tokens. `invalid_grant` (revocado, caducado o proyecto en Testing) exige reconexion;
     * 5xx/red son transitorios.
     *
     * @return array<string, mixed>
     */
    private function token(array $form): array
    {
        try {
            $response = Http::timeout($this->timeout())->asForm()->acceptJson()->post(self::TOKEN_URL, $form);
        } catch (ConnectionException) {
            throw new ConnectorException('No se pudo contactar con Google. Se reintentara mas tarde.');
        }

        return $this->parse($response);
    }

    /** @return array<string, mixed> */
    private function parse(Response $response): array
    {
        $json = $response->json() ?? [];

        if ($response->successful() && ! empty($json['access_token'])) {
            return $json;
        }

        $error = (string) ($json['error'] ?? '');
        Log::warning('google.token_error', ['status' => $response->status(), 'error' => $error]);

        if ($error === 'invalid_grant' || $error === 'invalid_token' || $error === 'unauthorized_client') {
            throw new ReconnectRequiredException('Google rechazo la renovacion ('.$error.'): el acceso fue revocado o caduco. Reconecta el canal.');
        }

        if ($response->serverError()) {
            throw new ConnectorException('Google no esta disponible temporalmente.');
        }

        throw new ConnectorException('Google devolvio un error al autorizar'.($error !== '' ? ' ('.$error.')' : '').'. Revisa la configuracion de credenciales y la URI de redireccion.');
    }

    private function expiry(array $token): ?CarbonImmutable
    {
        return isset($token['expires_in']) ? CarbonImmutable::now()->addSeconds((int) $token['expires_in']) : null;
    }

    private function timeout(): int
    {
        return (int) config('social.http_timeout', 15);
    }
}
