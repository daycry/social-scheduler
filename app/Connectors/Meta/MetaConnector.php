<?php

namespace App\Connectors\Meta;

use App\Connectors\Contracts\Connector;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Conector de Meta (Facebook Paginas + Instagram profesional) contra Graph API con version fija.
 *
 * Hallazgos del spike (spike-report.md) que respeta:
 *  - `/dialog/oauth` con `scope` (cada permiso activado en su caso de uso de la app).
 *  - Token largo via `fb_exchange_token`.
 *  - Las Paginas de portfolios empresariales no salen en `me/accounts`: se leen tambien
 *    `me/businesses` -> `owned_pages` / `client_pages` (requiere business_management).
 *  - IG solo si la Pagina tiene `instagram_business_account`.
 *  - `data_access_expires_at` (90 dias) -> al caducar hay que reconectar.
 */
class MetaConnector implements Connector
{
    private const PAGE_FIELDS = 'id,name,access_token,instagram_business_account{id,username}';

    /** Sin estos permisos no se puede publicar: se rechaza la conexion con un mensaje legible. */
    private const CRITICAL_SCOPES = ['pages_show_list', 'pages_manage_posts'];

    /** Codigos de error de Graph que significan "credencial invalida/revocada/caducada". */
    private const REAUTH_CODES = [102, 190, 458, 459, 460, 463, 464, 467];

    public function provider(): string
    {
        return 'meta';
    }

    public function networks(): array
    {
        return [Network::Facebook, Network::Instagram];
    }

    public function refreshMarginHours(): int
    {
        return (int) config('social.refresh_margin_hours.meta', 168);
    }

    public function authorizationUrl(string $state, string $redirectUri, bool $rerequest = false): string
    {
        $query = [
            'client_id' => config('services.meta.client_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(',', config('social.meta.scopes')),
            'response_type' => 'code',
            'state' => $state,
        ];

        // Fuerza a Meta a volver a mostrar la seleccion de Paginas/cuentas IG.
        if ($rerequest) {
            $query['auth_type'] = 'rerequest';
        }

        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query($query);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $short = $this->get('oauth/access_token', [
            'client_id' => config('services.meta.client_id'),
            'client_secret' => config('services.meta.client_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (empty($short['access_token'])) {
            throw new ConnectorException('Meta no devolvio un token de acceso. Vuelve a intentar la conexion.');
        }

        $long = $this->exchangeForLongLived($short['access_token']);
        $userToken = $long['access_token'];
        $expiresAt = isset($long['expires_in']) ? CarbonImmutable::now()->addSeconds((int) $long['expires_in']) : null;

        $debug = $this->debugToken($userToken);
        $granted = $debug['scopes'] ?? [];
        $missing = $granted ? array_values(array_diff(self::CRITICAL_SCOPES, $granted)) : [];

        if ($missing !== []) {
            throw new ConnectorException('Faltan permisos obligatorios de Meta: '.implode(', ', $missing).'. Vuelve a conectar y acepta todos los permisos.');
        }

        $dataAccess = $this->timestamp($debug['data_access_expires_at'] ?? null);
        $scopes = $granted ?: config('social.meta.scopes');

        $candidates = [];
        foreach ($this->fetchPages($userToken) as $page) {
            $meta = ['page_id' => $page['id'], 'page_name' => $page['name'] ?? null, 'business' => $page['business'] ?? null];
            $pageToken = $page['access_token'] ?? null;

            if (! $pageToken) {
                continue;
            }

            $candidates[] = new ConnectionCandidate(
                network: Network::Facebook,
                externalId: (string) $page['id'],
                name: $page['name'] ?? 'Pagina '.$page['id'],
                username: null,
                accessToken: $pageToken,
                refreshToken: $userToken,
                tokenExpiresAt: $expiresAt,
                dataAccessExpiresAt: $dataAccess,
                scopes: $scopes,
                meta: $meta,
            );

            if (! empty($page['instagram_business_account']['id'])) {
                $ig = $page['instagram_business_account'];
                $candidates[] = new ConnectionCandidate(
                    network: Network::Instagram,
                    externalId: (string) $ig['id'],
                    name: $ig['username'] ?? 'Instagram '.$ig['id'],
                    username: $ig['username'] ?? null,
                    accessToken: $pageToken,
                    refreshToken: $userToken,
                    tokenExpiresAt: $expiresAt,
                    dataAccessExpiresAt: $dataAccess,
                    scopes: $scopes,
                    meta: $meta,
                );
            }
        }

        if ($candidates === []) {
            throw new ConnectorException('No se encontro ninguna Pagina de Facebook que administres. Revisa que tu usuario tenga rol en la Pagina y que hayas marcado la Pagina durante la autorizacion.');
        }

        return $candidates;
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        $userToken = $account->refresh_token;

        if (! $userToken) {
            throw new ReconnectRequiredException('La cuenta no tiene token de renovacion: reconecta Meta.');
        }

        if ($account->data_access_expires_at && $account->data_access_expires_at->isPast()) {
            throw new ReconnectRequiredException('El acceso a datos de Meta (90 dias) ha caducado: vuelve a iniciar sesion para renovarlo.');
        }

        $long = $this->exchangeForLongLived($userToken);
        $newUserToken = $long['access_token'];
        $debug = $this->debugToken($newUserToken);

        if (($debug['is_valid'] ?? true) === false) {
            throw new ReconnectRequiredException('Meta ha invalidado el acceso (cambio de contrasena, permisos retirados o app eliminada): reconecta la cuenta.');
        }

        $pageId = $account->meta['page_id'] ?? $account->external_id;
        $pageToken = $account->access_token;

        try {
            $page = $this->get($pageId, ['fields' => 'access_token', 'access_token' => $newUserToken]);
            $pageToken = $page['access_token'] ?? $pageToken;
        } catch (ConnectorException $e) {
            // El token de Pagina anterior sigue siendo valido mientras no se revoque el acceso.
            Log::notice('meta.page_token_refetch_failed', ['account_id' => $account->id]);
        }

        return new RefreshResult(
            accessToken: $pageToken ?? '',
            refreshToken: $newUserToken,
            tokenExpiresAt: isset($long['expires_in']) ? CarbonImmutable::now()->addSeconds((int) $long['expires_in']) : $account->token_expires_at,
            dataAccessExpiresAt: $this->timestamp($debug['data_access_expires_at'] ?? null),
        );
    }

    /** @return array{access_token: string, expires_in?: int} */
    private function exchangeForLongLived(string $token): array
    {
        $long = $this->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.meta.client_id'),
            'client_secret' => config('services.meta.client_secret'),
            'fb_exchange_token' => $token,
        ]);

        if (empty($long['access_token'])) {
            throw new ConnectorException('Meta no devolvio un token de larga duracion.');
        }

        return $long;
    }

    /** @return array<string, mixed> */
    private function debugToken(string $token): array
    {
        try {
            $data = $this->get('debug_token', [
                'input_token' => $token,
                'access_token' => config('services.meta.client_id').'|'.config('services.meta.client_secret'),
            ]);
        } catch (ConnectorException $e) {
            if ($e instanceof ReconnectRequiredException) {
                throw $e;
            }

            Log::notice('meta.debug_token_failed');

            return [];
        }

        return $data['data'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function fetchPages(string $userToken): array
    {
        $pages = [];

        $accounts = $this->get('me/accounts', ['access_token' => $userToken, 'fields' => self::PAGE_FIELDS, 'limit' => 100]);
        foreach ($accounts['data'] ?? [] as $page) {
            $pages[$page['id']] = $page;
        }

        // Paginas de portfolios empresariales (no salen en me/accounts).
        try {
            $businesses = $this->get('me/businesses', ['access_token' => $userToken, 'fields' => 'id,name', 'limit' => 100]);
        } catch (ConnectorException) {
            $businesses = [];
        }

        foreach ($businesses['data'] ?? [] as $business) {
            foreach (['owned_pages', 'client_pages'] as $edge) {
                try {
                    $edgePages = $this->get($business['id'].'/'.$edge, ['access_token' => $userToken, 'fields' => self::PAGE_FIELDS, 'limit' => 100]);
                } catch (ConnectorException) {
                    continue;
                }

                foreach ($edgePages['data'] ?? [] as $page) {
                    $page['business'] = $business['name'] ?? null;
                    $pages[$page['id']] ??= $page;
                }
            }
        }

        return array_values($pages);
    }

    /**
     * GET contra Graph API. Traduce errores a excepciones con mensaje legible:
     * credencial invalida -> ReconnectRequiredException; permisos -> mensaje claro; 5xx/red -> transitorio.
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        try {
            $response = $this->http()->get($this->graph($path), $query);
        } catch (ConnectionException) {
            throw new ConnectorException('No se pudo contactar con Meta. Se reintentara mas tarde.');
        }

        return $this->parse($response);
    }

    /** @return array<string, mixed> */
    private function parse(Response $response): array
    {
        $json = $response->json() ?? [];

        if ($response->successful() && ! isset($json['error'])) {
            return $json;
        }

        $error = $json['error'] ?? [];
        $code = (int) ($error['code'] ?? 0);
        $message = (string) ($error['message'] ?? 'respuesta inesperada');

        Log::warning('meta.api_error', ['status' => $response->status(), 'code' => $code, 'subcode' => $error['error_subcode'] ?? null]);

        if (in_array($code, self::REAUTH_CODES, true)) {
            throw new ReconnectRequiredException('Meta rechazo las credenciales ('.$message.'). Reconecta la cuenta.');
        }

        if (in_array($code, [10, 200, 299], true) || str_starts_with($message, '(#200)') || str_starts_with($message, '(#10)')) {
            throw new ConnectorException('Permisos insuficientes en Meta: '.$message.'. Revisa los permisos concedidos y vuelve a conectar.');
        }

        if ($response->serverError() || in_array($code, [1, 2, 4, 17, 32, 341, 613], true)) {
            throw new ConnectorException('Meta no esta disponible temporalmente ('.$message.').');
        }

        throw new ConnectorException('Meta devolvio un error: '.$message);
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('social.http_timeout', 15))->acceptJson();
    }

    private function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version().'/'.ltrim($path, '/');
    }

    private function version(): string
    {
        return config('social.meta.graph_version', 'v23.0');
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_numeric($value) && (int) $value > 0 ? CarbonImmutable::createFromTimestampUTC((int) $value) : null;
    }
}
