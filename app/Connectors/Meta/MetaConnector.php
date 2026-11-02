<?php

namespace App\Connectors\Meta;

use App\Connectors\Contracts\OAuthConnector;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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
class MetaConnector implements OAuthConnector
{
    private const PAGE_FIELDS = 'id,name,access_token,instagram_business_account{id,username}';

    /** Sin estos permisos no se puede publicar: se rechaza la conexion con un mensaje legible. */
    private const CRITICAL_SCOPES = ['pages_show_list', 'pages_manage_posts'];

    /** Permiso necesario para PUBLICAR en Instagram (conectar para leer funciona sin el). */
    private const IG_PUBLISH_SCOPES = ['instagram_content_publish'];

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
        $short = $this->exchange('oauth/access_token', [
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

        // Estricto: si debug_token falla no se puede saber que permisos se concedieron, y saltarse la
        // comprobacion dejaria conectar paginas sin poder publicar. Se pide reintentar.
        $debug = $this->debugToken($userToken, strict: true);
        $granted = $debug['scopes'] ?? [];
        $missing = array_values(array_diff(self::CRITICAL_SCOPES, $granted));

        if ($missing !== []) {
            throw new ConnectorException('Faltan permisos obligatorios de Meta: '.implode(', ', $missing).'. Vuelve a conectar y acepta todos los permisos.');
        }

        $dataAccess = $this->timestamp($debug['data_access_expires_at'] ?? null);
        $scopes = $granted;
        // Sin este permiso Instagram se puede conectar para leer, pero no ofrecer publicar (aviso en la UI).
        $igMissing = array_values(array_diff(self::IG_PUBLISH_SCOPES, $granted));

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
                    meta: array_merge($meta, ['missing_scopes' => $igMissing]),
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

        // FB e IG de la misma Pagina (y todas las Paginas del mismo login) comparten token de usuario: el
        // canje se hace una vez por token y los demas jobs reutilizan el resultado (cifrado, 10 min).
        [$long, $debug] = $this->renewUserToken($userToken);
        $newUserToken = $long['access_token'];

        if (($debug['is_valid'] ?? true) === false) {
            throw new ReconnectRequiredException('Meta ha invalidado el acceso (cambio de contrasena, permisos retirados o app eliminada): reconecta la cuenta.');
        }

        $pageId = $account->meta['page_id'] ?? $account->external_id;
        $pageToken = $account->access_token;

        try {
            $page = $this->get($pageId, ['fields' => 'access_token', 'access_token' => $newUserToken]);
            $pageToken = $page['access_token'] ?? $pageToken;
        } catch (ReconnectRequiredException $e) {
            throw $e; // 190 / acceso revocado a la Pagina: no se da por renovada con el token viejo
        } catch (ConnectorException $e) {
            // Fallo transitorio: el token de Pagina anterior sigue siendo valido mientras no se revoque.
            Log::notice('meta.page_token_refetch_failed', ['account_id' => $account->id]);
        }

        return new RefreshResult(
            accessToken: $pageToken ?? '',
            refreshToken: $newUserToken,
            tokenExpiresAt: isset($long['expires_in']) ? CarbonImmutable::now()->addSeconds((int) $long['expires_in']) : $account->token_expires_at,
            dataAccessExpiresAt: $this->timestamp($debug['data_access_expires_at'] ?? null),
        );
    }

    /** @return array{0: array{access_token: string, expires_in?: int}, 1: array<string, mixed>} */
    private function renewUserToken(string $userToken): array
    {
        $key = 'meta.renew.'.hash('sha256', $userToken);

        try {
            $cached = Cache::get($key);
            $payload = $cached ? json_decode(Crypt::decryptString($cached), true) : null;
        } catch (\Throwable) {
            $payload = null;
        }

        if (is_array($payload) && isset($payload['long']['access_token'])) {
            return [$payload['long'], $payload['debug'] ?? []];
        }

        $long = $this->exchangeForLongLived($userToken);
        $debug = $this->debugToken($long['access_token']);

        Cache::put($key, Crypt::encryptString(json_encode(['long' => $long, 'debug' => $debug])), 600);

        return [$long, $debug];
    }

    /** @return array{access_token: string, expires_in?: int} */
    private function exchangeForLongLived(string $token): array
    {
        $long = $this->exchange('oauth/access_token', [
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
    private function debugToken(string $token, bool $strict = false): array
    {
        try {
            // `debug_token` solo admite GET (un POST devuelve 100/33 «Unsupported post request», comprobado 2026-10-21): el
            // token de APP (`client_id|client_secret`) va en `Authorization: Bearer` y el inspeccionado (`input_token`) en la
            // query, que Graph exige ahi. Es una llamada servidor a servidor por TLS; el secreto de la app nunca va en la URL.
            $data = $this->fetch($this->graph('debug_token'), ['input_token' => $token], config('services.meta.client_id').'|'.config('services.meta.client_secret'));
        } catch (ConnectorException $e) {
            if ($e instanceof ReconnectRequiredException) {
                throw $e;
            }

            Log::notice('meta.debug_token_failed');

            if ($strict) {
                throw new ConnectorException('No se pudieron comprobar los permisos concedidos en Meta. Vuelve a intentar la conexion en unos minutos.');
            }

            // En el refresco no es critico: se conserva lo que ya hay y se reintenta en la siguiente pasada.
            return [];
        }

        $info = $data['data'] ?? [];

        if ($strict && $info === []) {
            throw new ConnectorException('Meta no devolvio informacion sobre los permisos concedidos. Vuelve a intentar la conexion.');
        }

        return $info;
    }

    /** @return list<array<string, mixed>> */
    private function fetchPages(string $userToken): array
    {
        $pages = [];

        foreach ($this->getAll('me/accounts', ['access_token' => $userToken, 'fields' => self::PAGE_FIELDS, 'limit' => 100]) as $page) {
            $pages[$page['id']] = $page;
        }

        // Paginas de portfolios empresariales (no salen en me/accounts).
        try {
            $businesses = $this->getAll('me/businesses', ['access_token' => $userToken, 'fields' => 'id,name', 'limit' => 100]);
        } catch (ConnectorException) {
            $businesses = [];
        }

        foreach ($businesses as $business) {
            foreach (['owned_pages', 'client_pages'] as $edge) {
                try {
                    $edgePages = $this->getAll($business['id'].'/'.$edge, ['access_token' => $userToken, 'fields' => self::PAGE_FIELDS, 'limit' => 100]);
                } catch (ConnectorException) {
                    continue;
                }

                foreach ($edgePages as $page) {
                    $page['business'] = $business['name'] ?? null;
                    $pages[$page['id']] ??= $page;
                }
            }
        }

        return array_values($pages);
    }

    /**
     * Recorre una lista de Graph siguiendo `paging.next` (con tope `social.meta.max_pages`) y devuelve
     * los elementos de todas las paginas. `paging.next` ya trae todos los parametros (incluido el token).
     *
     * @return list<array<string, mixed>>
     */
    private function getAll(string $path, array $query): array
    {
        $items = [];
        $max = max(1, (int) config('social.meta.max_pages', 10));
        $token = isset($query['access_token']) ? (string) $query['access_token'] : null;
        $page = $this->get($path, $query);

        for ($i = 1; ; $i++) {
            array_push($items, ...($page['data'] ?? []));
            $next = $page['paging']['next'] ?? null;

            if (! is_string($next) || $next === '' || $i >= $max || ! str_starts_with($next, 'https://graph.facebook.com/')) {
                return $items;
            }

            $page = $this->fetch($next, [], $token);
        }
    }

    /**
     * GET contra Graph API. Traduce errores a excepciones con mensaje legible:
     * credencial invalida -> ReconnectRequiredException; permisos -> mensaje claro; 5xx/red -> transitorio.
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        return $this->fetch($this->graph($path), $query);
    }

    /**
     * Seguridad (CWE-598): ningun token ni secreto viaja en la URL (acaba en logs de proxy/acceso). El `access_token` de la
     * consulta (de usuario, de Pagina o el de app `client_id|client_secret`) se envia SIEMPRE en `Authorization: Bearer`,
     * tambien el que Graph incrusta en los enlaces `paging.next`. Los parametros no secretos (fields, limit) siguen en la query.
     *
     * @return array<string, mixed>
     */
    private function fetch(string $url, array $query, ?string $token = null): array
    {
        [$url, $linkToken] = $this->withoutToken($url);
        $token = isset($query['access_token']) ? (string) $query['access_token'] : ($token ?? $linkToken);
        unset($query['access_token']);

        try {
            $http = $this->http();
            $http = $token !== null && $token !== '' ? $http->withToken($token) : $http;
            // Con $query vacio hay que pasar null: un array vacio pisaria la query string de `paging.next`.
            $response = $http->get($url, $query === [] ? null : $query);
        } catch (ConnectionException) {
            throw new ConnectorException('No se pudo contactar con Meta. Se reintentara mas tarde.');
        }

        return $this->parse($response);
    }

    /**
     * POST (formulario) a Graph para los intercambios con secretos (`client_secret`, `code`, `fb_exchange_token`): viajan en el
     * CUERPO, nunca en la query. Graph admite POST con parametros en el cuerpo en `oauth/access_token`; si un despliegue
     * necesitara GET, `META_OAUTH_METHOD=get` lo restablece (los secretos volverian a la query: solo como ultimo recurso).
     *
     * @return array<string, mixed>
     */
    private function exchange(string $path, array $params, ?string $bearer = null): array
    {
        if (strtolower((string) config('social.meta.oauth_method', 'post')) === 'get') {
            return $this->fetch($this->graph($path), $params, $bearer);
        }

        try {
            $http = $bearer !== null ? $this->http()->withToken($bearer) : $this->http();
            $response = $http->asForm()->post($this->graph($path), $params);
        } catch (ConnectionException) {
            throw new ConnectorException('No se pudo contactar con Meta. Se reintentara mas tarde.');
        }

        return $this->parse($response);
    }

    /** @return array{0: string, 1: ?string} URL sin `access_token` y el token que llevaba. */
    private function withoutToken(string $url): array
    {
        $parts = parse_url($url);
        if (! isset($parts['query'])) {
            return [$url, null];
        }

        parse_str($parts['query'], $params);
        $token = isset($params['access_token']) ? (string) $params['access_token'] : null;
        unset($params['access_token']);

        $clean = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '');

        return [$params === [] ? $clean : $clean.'?'.http_build_query($params), $token];
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
