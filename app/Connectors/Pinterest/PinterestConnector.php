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
 * (o su usuario). Al conectar se leen los tableros (paginando con `bookmark`, hasta `BOARDS_MAX_PAGES` paginas de `BOARDS_PAGE_SIZE`; si queda incompleta, `meta.boards_partial`) en
 * `meta.boards` ([{id, name}]) para elegir el destino de cada pin; «Actualizar tableros» en Conexiones los vuelve a leer (`refreshBoards`) y un
 * tablero que aun no aparezca se puede indicar por su id.
 */
class PinterestConnector implements OAuthConnector
{
    /** `GET /v5/boards`: `page_size` 1-250 (25 por defecto); `bookmark` pide la pagina siguiente. */
    private const BOARDS_PAGE_SIZE = 100;

    private const BOARDS_MAX_PAGES = 5;

    /** Segundos tras los que no se pide otra pagina (una pagina puede tardar `social.http_timeout`, 15 s: 10 + 15 queda bajo los 30 s de `max_execution_time`). */
    private const BOARDS_BUDGET_SECONDS = 10;

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
            meta: $this->boardsMeta($access),
        )];
    }

    /** @return array{boards: list<array{id: string, name: string}>, boards_partial: bool} */
    private function boardsMeta(#[\SensitiveParameter] string $access): array
    {
        ['boards' => $boards, 'partial' => $partial] = $this->boards($access, strict: false);

        return ['boards' => $boards, 'boards_partial' => $partial];
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
     * Vuelve a leer los tableros de la cuenta (paginados) y guarda la lista en `meta.boards` (y `boards_partial` si quedo incompleta). Si Pinterest
     * falla se propaga la excepcion y la lista anterior se conserva.
     *
     * @return list<array{id: string, name: string}>
     */
    public function refreshBoards(SocialAccount $account): array
    {
        ['boards' => $boards, 'partial' => $partial] = $this->boards((string) $account->access_token, strict: true);

        $account->forceFill(['meta' => array_merge($account->meta ?? [], ['boards' => $boards, 'boards_partial' => $partial, 'boards_refreshed_at' => now()->toIso8601String()])])->save();

        return $boards;
    }

    /**
     * Tableros del usuario, siguiendo el `bookmark` hasta la ultima pagina. La lectura es PARCIAL (`partial`) si se alcanza el tope de paginas con
     * `bookmark` pendiente, si se agota el presupuesto de tiempo (`BOARDS_BUDGET_SECONDS`: la peticion web debe acabar dentro de
     * `max_execution_time`, y una pagina puede tardar hasta `social.http_timeout`) o, al conectar, si una pagina falla. Al conectar
     * (`strict: false`) un rechazo (p. ej. sin `boards:read`, 403) deja la lista vacia y la cuenta se conecta igualmente; al refrescar se propaga.
     *
     * @return array{boards: list<array{id: string, name: string}>, partial: bool}
     */
    private function boards(#[\SensitiveParameter] string $access, bool $strict): array
    {
        $boards = [];
        $bookmark = null;
        $seen = [];
        $partial = false;
        $started = (float) now()->format('U.u');

        for ($page = 0; $page < self::BOARDS_MAX_PAGES; $page++) {
            if ($page > 0 && (float) now()->format('U.u') - $started >= self::BOARDS_BUDGET_SECONDS) {
                $partial = true;
                break;
            }

            $query = ['page_size' => self::BOARDS_PAGE_SIZE] + ($bookmark !== null ? ['bookmark' => $bookmark] : []);

            try {
                $json = $this->client->api('GET', 'v5/boards', $access, query: $query, read: true)->json() ?? [];
            } catch (ReconnectRequiredException $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($strict) {
                    throw $e instanceof ConnectorException ? $e : new ConnectorException('No se pudieron leer los tableros de Pinterest.');
                }

                return ['boards' => array_values($boards), 'partial' => true];
            }

            foreach ((array) ($json['items'] ?? []) as $board) {
                if (isset($board['id']) && preg_match('/^\d{1,30}$/D', (string) $board['id'])) {
                    $boards[(string) $board['id']] = ['id' => (string) $board['id'], 'name' => mb_substr((string) ($board['name'] ?? 'Tablero '.$board['id']), 0, 100)];
                }
            }

            $next = $json['bookmark'] ?? null;
            if (! is_string($next) || $next === '' || isset($seen[$next])) {
                break;
            }
            $seen[$next] = true;
            $bookmark = $next;

            if ($page === self::BOARDS_MAX_PAGES - 1) {
                $partial = true; // tope alcanzado con bookmark pendiente
            }
        }

        return ['boards' => array_values($boards), 'partial' => $partial];
    }

    private function expiry(mixed $seconds): ?CarbonImmutable
    {
        return is_numeric($seconds) && (int) $seconds > 0 ? CarbonImmutable::now()->addSeconds((int) $seconds) : null;
    }
}
