<?php

namespace App\Connectors\TikTok;

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
 * Conector de TikTok (Login Kit v2) con cliente HTTP propio: `socialiteproviders/tiktok`
 * no tiene releases desde 2024-04.
 *
 * Hallazgos del spike / decision G1:
 *  - access_token 24 h (86400 s) y refresh_token 365 dias (medido): se refresca el access antes de caducar.
 *  - `creator_info/query` exige cuerpo JSON objeto `{}` (con `[]` -> invalid_params).
 *  - Sin auditoria solo se puede publicar en privado: por defecto se sube como borrador a la bandeja
 *    (`meta.direct_post`, ausente = false, por cuenta; se activara tras la auditoria, en T-27). El conector
 *    no escribe esa clave para que reconectar nunca pise la eleccion del usuario.
 */
class TikTokConnector implements Connector
{
    private const AUTH_URL = 'https://www.tiktok.com/v2/auth/authorize/';

    private const API = 'https://open.tiktokapis.com';

    public function provider(): string
    {
        return 'tiktok';
    }

    public function networks(): array
    {
        return [Network::TikTok];
    }

    public function refreshMarginHours(): int
    {
        return (int) config('social.refresh_margin_hours.tiktok', 6);
    }

    public function authorizationUrl(string $state, string $redirectUri, bool $rerequest = false): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_key' => config('services.tiktok.client_key'),
            'scope' => implode(',', config('social.tiktok.scopes')),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $token = $this->token([
            'client_key' => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        $scopes = array_values(array_filter(explode(',', (string) ($token['scope'] ?? ''))));

        if ($scopes !== [] && ! in_array('video.upload', $scopes, true) && ! in_array('video.publish', $scopes, true)) {
            throw new ConnectorException('Faltan los permisos de subida de video de TikTok. Vuelve a conectar y acepta todos los permisos.');
        }

        $user = $this->userInfo($token['access_token']);
        $openId = (string) ($user['open_id'] ?? $token['open_id'] ?? '');

        if ($openId === '') {
            throw new ConnectorException('TikTok no devolvio el identificador de la cuenta.');
        }

        return [new ConnectionCandidate(
            network: Network::TikTok,
            externalId: $openId,
            name: $user['display_name'] ?? 'TikTok '.$openId,
            username: $user['display_name'] ?? null,
            accessToken: $token['access_token'],
            refreshToken: $token['refresh_token'] ?? null,
            tokenExpiresAt: $this->after($token['expires_in'] ?? null),
            refreshExpiresAt: $this->after($token['refresh_expires_in'] ?? null),
            scopes: $scopes,
            meta: $this->creatorInfo($token['access_token']),
        )];
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        if (! $account->refresh_token) {
            throw new ReconnectRequiredException('La cuenta de TikTok no tiene token de renovacion: reconecta la cuenta.');
        }

        if ($account->refresh_expires_at && $account->refresh_expires_at->isPast()) {
            throw new ReconnectRequiredException('El token de renovacion de TikTok (365 dias) ha caducado: reconecta la cuenta.');
        }

        $token = $this->token([
            'client_key' => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        return new RefreshResult(
            accessToken: $token['access_token'],
            refreshToken: $token['refresh_token'] ?? null,
            tokenExpiresAt: $this->after($token['expires_in'] ?? null),
            refreshExpiresAt: $this->after($token['refresh_expires_in'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    private function userInfo(string $accessToken): array
    {
        try {
            $response = Http::timeout($this->timeout())->acceptJson()->withToken($accessToken)
                ->get(self::API.'/v2/user/info/', ['fields' => 'open_id,display_name,avatar_url']);
        } catch (ConnectionException) {
            throw new ConnectorException('No se pudo contactar con TikTok. Vuelve a intentarlo.');
        }

        $code = $response->json('error.code');

        if (! $response->successful() || ($code !== null && $code !== 'ok')) {
            Log::warning('tiktok.user_info_error', ['status' => $response->status(), 'code' => $code]);

            throw new ConnectorException('TikTok rechazo la consulta del perfil ('.($code ?? $response->status()).'). Revisa el permiso user.info.basic.');
        }

        return $response->json('data.user') ?? [];
    }

    /**
     * Opciones de publicacion de la cuenta. No es critico para conectar: si falla, se omite
     * (se volvera a consultar al publicar). El cuerpo DEBE ser un objeto JSON `{}`, no `[]`.
     *
     * @return array<string, mixed>
     */
    private function creatorInfo(string $accessToken): array
    {
        try {
            $response = Http::timeout($this->timeout())->acceptJson()->withToken($accessToken)
                ->withBody('{}', 'application/json; charset=UTF-8')
                ->post(self::API.'/v2/post/publish/creator_info/query/');
        } catch (ConnectionException) {
            return [];
        }

        $data = $response->json('data');

        if (! $response->successful() || ! is_array($data) || ($response->json('error.code') ?? 'ok') !== 'ok') {
            Log::notice('tiktok.creator_info_unavailable', ['status' => $response->status(), 'code' => $response->json('error.code')]);

            return [];
        }

        return array_filter([
            'privacy_level_options' => $data['privacy_level_options'] ?? null,
            'max_video_post_duration_sec' => $data['max_video_post_duration_sec'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    private function token(array $form): array
    {
        try {
            $response = Http::timeout($this->timeout())->asForm()->acceptJson()
                ->withHeaders(['Cache-Control' => 'no-cache'])
                ->post(self::API.'/v2/oauth/token/', $form);
        } catch (ConnectionException) {
            throw new ConnectorException('No se pudo contactar con TikTok. Se reintentara mas tarde.');
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
        Log::warning('tiktok.token_error', ['status' => $response->status(), 'error' => $error]);

        if (in_array($error, ['invalid_grant', 'invalid_token', 'access_denied'], true)) {
            throw new ReconnectRequiredException('TikTok rechazo la renovacion ('.$error.'): el acceso caduco o fue revocado. Reconecta la cuenta.');
        }

        if ($response->serverError()) {
            throw new ConnectorException('TikTok no esta disponible temporalmente.');
        }

        throw new ConnectorException('TikTok devolvio un error al autorizar'.($error !== '' ? ' ('.$error.')' : '').'. Revisa las credenciales de la app y la URI de redireccion.');
    }

    private function after(mixed $seconds): ?CarbonImmutable
    {
        return is_numeric($seconds) ? CarbonImmutable::now()->addSeconds((int) $seconds) : null;
    }

    private function timeout(): int
    {
        return (int) config('social.http_timeout', 15);
    }
}
