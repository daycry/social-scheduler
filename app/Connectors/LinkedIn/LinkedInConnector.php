<?php

namespace App\Connectors\LinkedIn;

use App\Connectors\Contracts\OAuthConnector;
use App\Connectors\Contracts\PerAccountRefreshMargin;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Conector de LinkedIn (T-18): OAuth 2.0 de tres patas (`/oauth/v2/authorization` + `/oauth/v2/accessToken`). Un mismo login ofrece el
 * PERFIL personal (OpenID `userinfo`, publica con `w_member_social`) y las PAGINAS que el usuario administra (`organizationAcls`, con los
 * permisos de Community Management, que LinkedIn aprueba aparte: sin ellos solo sale el perfil). El token dura ~60 dias; solo las apps con
 * programa de partner reciben `refresh_token`: sin el, la renovacion no es posible y se pide reconectar antes de que caduque.
 * `meta.author_urn` es el autor de las publicaciones (`urn:li:person:…` u `urn:li:organization:…`); `meta.kind` = profile | page.
 */
class LinkedInConnector implements OAuthConnector, PerAccountRefreshMargin
{
    /** Maximo de paginas que se ofrecen en una conexion. */
    private const MAX_PAGES = 20;

    public function __construct(private readonly LinkedInClient $client = new LinkedInClient) {}

    public function provider(): string
    {
        return 'linkedin';
    }

    public function networks(): array
    {
        return [Network::LinkedIn];
    }

    public function refreshMarginHours(): int
    {
        return (int) config('social.refresh_margin_hours.linkedin', 168);
    }

    /**
     * Sin `refresh_token` no hay forma de renovar: la cuenta sigue publicando hasta que el token caduque de verdad (o LinkedIn responda 401)
     * y solo entonces se marca `requiere_reconexion`. La UI y `connections:notify-expiry` avisan «reconecta antes de» unos dias antes (`social.expiry_notice_days`, ver `ConnectionExpiry`).
     */
    public function refreshMarginHoursFor(SocialAccount $account): int
    {
        return (string) $account->refresh_token === '' ? 0 : $this->refreshMarginHours();
    }

    /** Permisos que se piden: el perfil siempre; `r_member_social` solo si la app lo tiene; los de paginas solo con Community Management. */
    public static function requestedScopes(): array
    {
        return array_values(array_merge(
            (array) config('social.linkedin.scopes'),
            config('social.linkedin.member_read') ? ['r_member_social'] : [],
            config('social.linkedin.community_management') ? (array) config('social.linkedin.page_scopes') : [],
        ));
    }

    public function authorizationUrl(string $state, string $redirectUri, bool $rerequest = false): string
    {
        return rtrim((string) config('social.linkedin.auth_host'), '/').'/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.linkedin.client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(' ', self::requestedScopes()),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $token = $this->client->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
        ]);

        $access = (string) $token['access_token'];
        $scopes = array_values(array_filter(preg_split('/[\s,]+/', (string) ($token['scope'] ?? '')) ?: [])) ?: self::requestedScopes();
        $common = [
            'accessToken' => $access,
            'refreshToken' => ! empty($token['refresh_token']) ? (string) $token['refresh_token'] : null,
            'tokenExpiresAt' => $this->expiry($token['expires_in'] ?? null),
            'refreshExpiresAt' => $this->expiry($token['refresh_token_expires_in'] ?? null),
            'scopes' => $scopes,
        ];

        try {
            $me = $this->client->api('GET', 'v2/userinfo', $access, read: true)->json() ?? [];
        } catch (ReconnectRequiredException $e) {
            throw $e;
        } catch (ConnectorException) {
            throw new ConnectorException('No se pudo leer el perfil de LinkedIn. Vuelve a intentar la conexion en unos minutos.');
        }

        $sub = (string) ($me['sub'] ?? '');
        if ($sub === '') {
            throw new ConnectorException('LinkedIn no devolvio el identificador del perfil.');
        }
        $name = trim((string) ($me['name'] ?? trim(($me['given_name'] ?? '').' '.($me['family_name'] ?? ''))));

        $candidates = [new ConnectionCandidate(
            network: Network::LinkedIn,
            externalId: $sub,
            name: $name !== '' ? $name : 'LinkedIn '.$sub,
            username: null,
            meta: ['kind' => 'profile', 'author_urn' => 'urn:li:person:'.$sub],
            accessToken: $common['accessToken'], refreshToken: $common['refreshToken'], tokenExpiresAt: $common['tokenExpiresAt'],
            refreshExpiresAt: $common['refreshExpiresAt'], scopes: $common['scopes'],
        )];

        foreach (config('social.linkedin.community_management') ? $this->pages($access) : [] as $page) {
            $candidates[] = new ConnectionCandidate(
                network: Network::LinkedIn,
                externalId: 'org-'.$page['id'],
                name: $page['name'],
                username: $page['vanity'],
                meta: ['kind' => 'page', 'author_urn' => 'urn:li:organization:'.$page['id']],
                accessToken: $common['accessToken'], refreshToken: $common['refreshToken'], tokenExpiresAt: $common['tokenExpiresAt'],
                refreshExpiresAt: $common['refreshExpiresAt'], scopes: $common['scopes'],
            );
        }

        return $candidates;
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        $refresh = (string) $account->refresh_token;
        if ($refresh === '') {
            throw new ReconnectRequiredException('LinkedIn no permite renovar el acceso de esta cuenta sin que vuelvas a autorizar: reconecta la cuenta antes de que caduque.');
        }

        $data = $this->client->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id' => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
        ]);

        return new RefreshResult(
            accessToken: (string) $data['access_token'],
            refreshToken: ! empty($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            tokenExpiresAt: $this->expiry($data['expires_in'] ?? null) ?? $account->token_expires_at,
            refreshExpiresAt: $this->expiry($data['refresh_token_expires_in'] ?? null),
        );
    }

    /**
     * Paginas administradas por el usuario. Sin los permisos de Community Management LinkedIn responde 403: no es un error de la conexion,
     * solo no hay paginas que ofrecer.
     *
     * @return list<array{id: string, name: string, vanity: ?string}>
     */
    private function pages(#[\SensitiveParameter] string $access): array
    {
        try {
            $acls = $this->client->api('GET', 'rest/organizationAcls', $access, query: ['q' => 'roleAssignee', 'role' => 'ADMINISTRATOR', 'state' => 'APPROVED'], read: true)->json() ?? [];
        } catch (ReconnectRequiredException $e) {
            throw $e;
        } catch (\Throwable) {
            return [];
        }

        $pages = [];
        foreach ((array) ($acls['elements'] ?? []) as $element) {
            if (count($pages) >= self::MAX_PAGES) {
                break;
            }
            if (! preg_match('/^urn:li:organization:(\d{1,20})$/D', (string) ($element['organization'] ?? ''), $m)) {
                continue;
            }
            $name = 'Pagina '.$m[1];
            $vanity = null;
            try {
                $org = $this->client->api('GET', 'rest/organizations/'.$m[1], $access, read: true)->json() ?? [];
                $name = (string) (($org['localizedName'] ?? '') !== '' ? $org['localizedName'] : $name);
                $vanity = isset($org['vanityName']) ? (string) $org['vanityName'] : null;
            } catch (ReconnectRequiredException $e) {
                throw $e;
            } catch (\Throwable) {
                // Sin nombre legible la pagina se ofrece igualmente por su identificador.
            }
            $pages[] = ['id' => $m[1], 'name' => $name, 'vanity' => $vanity];
        }

        return $pages;
    }

    private function expiry(mixed $seconds): ?CarbonImmutable
    {
        return is_numeric($seconds) && (int) $seconds > 0 ? CarbonImmutable::now()->addSeconds((int) $seconds) : null;
    }
}
