<?php

namespace App\Connectors\Pinterest;

use App\Connectors\Contracts\OAuthConnector;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Conector de Pinterest (T-18, API v5): OAuth 2.0 con codigo (`/oauth/` + `POST /v5/oauth/token` con Basic). El acceso dura ~30 dias y el
 * refresco ~60 (renovacion continua: cada refresco devuelve un `refresh_token` nuevo, que se guarda). `external_id` es el id de la cuenta
 * (o su usuario). Al conectar se leen hasta 25 tableros en `meta.boards` ([{id, name}]) para elegir el destino de cada pin; un tablero que
 * no aparezca (creado despues) se puede indicar por su id.
 */
class PinterestConnector implements OAuthConnector
{
    private const BOARDS_PAGE = 25;

    public function __construct(private readonly PinterestClient $client = new PinterestClient) {}

    public function provider(): string
    {
        return 'pinterest';
    }

    public function networks(): array
    {
        return [Network::Pinterest];
    }

    public function refreshMarginHours(): int
    {
        return (int) config('social.refresh_margin_hours.pinterest', 72);
    }

    public function authorizationUrl(string $state, string $redirectUri, bool $rerequest = false): string
    {
        return rtrim((string) config('social.pinterest.auth_host'), '/').'/oauth/?'.http_build_query([
            'client_id' => config('services.pinterest.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(',', (array) config('social.pinterest.scopes')),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $token = $this->client->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
        $access = (string) $token['access_token'];

        try {
            $me = $this->client->api('GET', 'v5/user_account', $access, read: true)->json() ?? [];
        } catch (ReconnectRequiredException $e) {
            throw $e;
        } catch (ConnectorException) {
            throw new ConnectorException('No se pudo leer el perfil de Pinterest. Vuelve a intentar la conexion en unos minutos.');
        }

        $username = isset($me['username']) ? (string) $me['username'] : null;
        $id = (string) ($me['id'] ?? $username ?? '');
        if ($id === '') {
            throw new ConnectorException('Pinterest no devolvio el identificador de la cuenta.');
        }

        $scopes = array_values(array_filter(preg_split('/[\s,]+/', (string) ($token['scope'] ?? '')) ?: [])) ?: (array) config('social.pinterest.scopes');

        return [new ConnectionCandidate(
            network: Network::Pinterest,
            externalId: $id,
            name: $username ?? 'Pinterest '.$id,
            username: $username,
            accessToken: $access,
            refreshToken: ! empty($token['refresh_token']) ? (string) $token['refresh_token'] : null,
            tokenExpiresAt: $this->expiry($token['expires_in'] ?? null),
            refreshExpiresAt: $this->expiry($token['refresh_token_expires_in'] ?? null),
            scopes: $scopes,
            meta: ['boards' => $this->boards($access)],
        )];
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        $refresh = (string) $account->refresh_token;
        if ($refresh === '') {
            throw new ReconnectRequiredException('La cuenta de Pinterest no tiene token de renovacion: reconecta la cuenta.');
        }

        $data = $this->client->token(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);

        return new RefreshResult(
            accessToken: (string) $data['access_token'],
            refreshToken: ! empty($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            tokenExpiresAt: $this->expiry($data['expires_in'] ?? null) ?? $account->token_expires_at,
            refreshExpiresAt: $this->expiry($data['refresh_token_expires_in'] ?? null),
        );
    }

    /**
     * Tableros del usuario; sin `boards:read` (403) la cuenta se conecta igualmente y el tablero se indica por id.
     *
     * @return list<array{id: string, name: string}>
     */
    private function boards(#[\SensitiveParameter] string $access): array
    {
        try {
            $items = (array) ($this->client->api('GET', 'v5/boards', $access, query: ['page_size' => self::BOARDS_PAGE], read: true)->json('items') ?? []);
        } catch (ReconnectRequiredException $e) {
            throw $e;
        } catch (\Throwable) {
            return [];
        }

        $boards = [];
        foreach ($items as $board) {
            if (isset($board['id']) && preg_match('/^\d{1,30}$/D', (string) $board['id'])) {
                $boards[] = ['id' => (string) $board['id'], 'name' => mb_substr((string) ($board['name'] ?? 'Tablero '.$board['id']), 0, 100)];
            }
        }

        return array_slice($boards, 0, self::BOARDS_PAGE);
    }

    private function expiry(mixed $seconds): ?CarbonImmutable
    {
        return is_numeric($seconds) && (int) $seconds > 0 ? CarbonImmutable::now()->addSeconds((int) $seconds) : null;
    }
}
