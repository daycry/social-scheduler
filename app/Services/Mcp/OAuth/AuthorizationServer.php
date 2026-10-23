<?php

namespace App\Services\Mcp\OAuth;

use App\Mcp\Abilities;
use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Servidor de autorizacion OAuth 2.1 minimo del MCP (ADR-004): cliente publico, code + PKCE S256, sin refresh.
 * Valida la peticion de authorize y emite el codigo (solo su hash SHA-256 va a BD).
 */
class AuthorizationServer
{
    public function __construct(private RedirectPolicy $redirects, private AuditLog $audit) {}

    /** URL canonica del recurso protegido (la que debe llevar `resource`). */
    public static function resourceUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/mcp';
    }

    /** @param  array<string, scalar>  $params */
    public static function appendQuery(string $url, array $params): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Valida los parametros de /oauth/authorize. Orden: client_id y redirect_uri primero (errores FATALES, sin redirect);
     * despues el resto (errores que vuelven al cliente con `state`).
     *
     * @param  array<string, mixed>  $q
     *
     * @throws OAuthException
     */
    public function validateAuthorize(array $q): AuthorizeRequest
    {
        $clientId = $q['client_id'] ?? null;
        $client = is_string($clientId) && Str::isUuid($clientId) ? OAuthClient::find($clientId) : null;
        if ($client === null) {
            throw new OAuthException('invalid_request', 'El cliente no esta registrado.');
        }

        $redirectUri = $q['redirect_uri'] ?? null;
        if ($redirectUri === null && count($client->redirect_uris) === 1) {
            $redirectUri = $client->redirect_uris[0];
        }
        if (! is_string($redirectUri) || ! $this->redirects->matchesRegistered($client->redirect_uris, $redirectUri)) {
            throw new OAuthException('invalid_request', 'La redireccion no coincide con ninguna registrada para el cliente.');
        }

        $redirectSent = isset($q['redirect_uri']);
        $state = isset($q['state']) && is_string($q['state']) ? $q['state'] : null;
        if ($state !== null && mb_strlen($state) > 500) {
            throw new OAuthException('invalid_request', 'state supera los 500 caracteres.', $redirectUri);
        }
        $fail = fn (string $error, string $description) => new OAuthException($error, $description, $redirectUri, $state);

        if (($q['response_type'] ?? null) !== 'code') {
            throw $fail('invalid_request', 'Solo se admite response_type=code.');
        }

        $challenge = $q['code_challenge'] ?? null;
        if (! is_string($challenge) || ! preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
            throw $fail('invalid_request', 'Falta code_challenge (PKCE) o no es valido.');
        }
        if (($q['code_challenge_method'] ?? null) !== 'S256') {
            throw $fail('invalid_request', 'Solo se admite code_challenge_method=S256.');
        }

        if (isset($q['scope']) && (! is_string($q['scope']) || array_diff(preg_split('/\s+/', trim($q['scope']), -1, PREG_SPLIT_NO_EMPTY) ?: [], ['mcp:use']) !== [])) {
            throw $fail('invalid_scope', 'El unico scope admitido es mcp:use.');
        }

        if (isset($q['resource']) && $q['resource'] !== self::resourceUrl()) {
            throw $fail('invalid_target', 'El recurso solicitado no es este servidor MCP.');
        }

        return new AuthorizeRequest($client, $redirectUri, $challenge, $state, self::resourceUrl(), $redirectSent);
    }

    /**
     * Emite un codigo de autorizacion de un solo uso. Devuelve el codigo en claro (solo existe en la respuesta).
     *
     * @param  list<string>  $abilities  ya validadas contra Abilities::keys()
     */
    public function issueCode(User $user, AuthorizeRequest $req, array $abilities, ?int $tokenDays = null): string
    {
        $abilities = array_values(array_intersect(Abilities::keys(), $abilities));
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        OAuthAuthCode::create([
            'code_hash' => hash('sha256', $code),
            'client_id' => $req->client->id,
            'user_id' => $user->id,
            'redirect_uri' => $req->redirectUri,
            'redirect_uri_sent' => $req->redirectUriSent,
            'code_challenge' => $req->codeChallenge,
            'abilities' => $abilities,
            'token_days' => $tokenDays,
            'resource' => $req->resource,
            'expires_at' => now()->addSeconds(max(30, (int) config('services.mcp.oauth.code_ttl', 300))),
        ]);

        return $code;
    }

    /** challenge = BASE64URL(SHA256(verifier)) (RFC 7636 4.2). */
    public static function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** Comparacion en tiempo constante entre el challenge guardado y el derivado del verifier. */
    public static function pkceMatches(string $verifier, string $challenge): bool
    {
        return preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) === 1
            && hash_equals($challenge, self::pkceChallenge($verifier));
    }

    /**
     * Canjea un codigo por un token Sanctum `msk_` (grant authorization_code + PKCE S256). Un codigo es de UN solo uso:
     * se consume con un UPDATE atomico y su reutilizacion revoca el token que emitio (CA-21).
     *
     * @param  array<string, mixed>  $in  parametros de POST /oauth/token
     * @return array{access_token: string, token_type: string, expires_in: int, scope: string}
     *
     * @throws OAuthException
     */
    public function redeemCode(array $in): array
    {
        $str = fn (string $k) => isset($in[$k]) && is_string($in[$k]) && $in[$k] !== '' ? $in[$k] : null;

        if (($in['grant_type'] ?? null) !== 'authorization_code') {
            throw new OAuthException('unsupported_grant_type', 'Solo se admite grant_type=authorization_code.');
        }

        [$code, $redirectUri, $clientId, $verifier] = [$str('code'), $str('redirect_uri'), $str('client_id'), $str('code_verifier')];
        if ($code === null || $clientId === null || $verifier === null) {
            throw new OAuthException('invalid_request', 'Faltan code, client_id o code_verifier.');
        }

        // Un client_id desconocido responde igual que un codigo invalido (no permite enumerar clientes).
        $invalid = new OAuthException('invalid_grant', 'El codigo no es valido, ha caducado o ya se uso.');
        $client = Str::isUuid($clientId) ? OAuthClient::find($clientId) : null;
        if ($client === null) {
            throw $invalid;
        }

        if (isset($in['resource']) && $in['resource'] !== self::resourceUrl()) {
            throw new OAuthException('invalid_target', 'El recurso solicitado no es este servidor MCP.');
        }

        $row = OAuthAuthCode::where('code_hash', hash('sha256', $code))->first();
        if ($row === null) {
            throw $invalid;
        }

        // Reutilizacion de un codigo ya canjeado: se revoca el token que emitio.
        if ($row->used_at !== null) {
            $this->replayed($row);
            throw $invalid;
        }

        $mismatch = $row->client_id !== $client->id
            || ($redirectUri === null ? $row->redirect_uri_sent : ! hash_equals($row->redirect_uri, $redirectUri))
            || ! self::pkceMatches($verifier, $row->code_challenge);
        if ($mismatch) {
            // Un intento fallido con el codigo lo quema: quien lo intercepte solo tiene una oportunidad.
            OAuthAuthCode::whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]);
            throw $invalid;
        }

        if ($row->expires_at->isPast()) {
            throw $invalid;
        }

        $issued = DB::transaction(function () use ($row, $client) {
            // Un solo uso atomico: si otra peticion lo consumio entre medias, esta es una reutilizacion.
            if (OAuthAuthCode::whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]) !== 1) {
                return null;
            }

            // Duracion elegida en el consentimiento (guardada en el codigo), acotada al tope; sin valor, la por defecto.
            $max = max(1, (int) config('services.mcp.key_max_days', 730));
            $days = min($max, max(1, (int) ($row->token_days ?? config('services.mcp.oauth.token_days', 90))));
            $user = User::findOrFail($row->user_id);

            // Reautorizar sustituye (CA-26): se bloquean y revocan los tokens de ESTE (usuario, cliente); los de otros
            // clientes y las API keys manuales (oauth_client_id NULL) no se tocan. Dentro de la transaccion y con bloqueo
            // de filas para que dos canjes concurrentes del mismo par no dejen dos tokens vivos.
            $previous = PersonalAccessToken::where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)
                ->where('oauth_client_id', $client->id)->lockForUpdate()->pluck('id');
            PersonalAccessToken::whereIn('id', $previous)->delete();
            $new = $user->createToken('OAuth · '.$client->name, $row->abilities, now()->addDays($days));
            PersonalAccessToken::whereKey($new->accessToken->id)->update(['oauth_client_id' => $client->id, 'origin' => 'oauth']);
            // Si dos canjes de codigos distintos del mismo par se cruzaron, queda solo el token recien emitido.
            PersonalAccessToken::where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)
                ->where('oauth_client_id', $client->id)->whereKeyNot($new->accessToken->id)->delete();
            $row->forceFill(['token_id' => $new->accessToken->id])->save();
            $client->forceFill(['last_used_at' => now()])->save();

            $this->audit->record($user, 'oauth.token.issued', AuditLog::OK, 'api_key', $new->accessToken->id, 'client_id='.$client->id, 'oauth');

            return [
                'access_token' => $new->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => $days * 86400,
                'scope' => 'mcp:use',
            ];
        }, 3);

        if ($issued === null) {
            // Perdio la carrera: el codigo ya estaba canjeado. Fuera de la transaccion para que la revocacion no se deshaga.
            $this->replayed($row->fresh());
            throw $invalid;
        }

        return $issued;
    }

    private function replayed(?OAuthAuthCode $row): void
    {
        if ($row === null) {
            return;
        }

        if ($row->token_id !== null) {
            PersonalAccessToken::whereKey($row->token_id)->delete();
        }

        $this->audit->record($row->user, 'oauth.code.replayed', AuditLog::DENIED, 'oauth_client', null, 'client_id='.$row->client_id, 'oauth');
    }
}
