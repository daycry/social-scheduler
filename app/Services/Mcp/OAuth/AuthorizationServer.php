<?php

namespace App\Services\Mcp\OAuth;

use App\Mcp\Abilities;
use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Servidor de autorizacion OAuth 2.1 minimo del MCP (ADR-004): cliente publico, code + PKCE S256, sin refresh.
 * Valida la peticion de authorize y emite el codigo (solo su hash SHA-256 va a BD).
 */
class AuthorizationServer
{
    public function __construct(private RedirectPolicy $redirects) {}

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

        $state = isset($q['state']) && is_string($q['state']) ? mb_substr($q['state'], 0, 500) : null;
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

        return new AuthorizeRequest($client, $redirectUri, $challenge, $state, self::resourceUrl());
    }

    /**
     * Emite un codigo de autorizacion de un solo uso. Devuelve el codigo en claro (solo existe en la respuesta).
     *
     * @param  list<string>  $abilities  ya validadas contra Abilities::keys()
     */
    public function issueCode(User $user, AuthorizeRequest $req, array $abilities): string
    {
        $abilities = array_values(array_intersect(Abilities::keys(), $abilities));
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        OAuthAuthCode::create([
            'code_hash' => hash('sha256', $code),
            'client_id' => $req->client->id,
            'user_id' => $user->id,
            'redirect_uri' => $req->redirectUri,
            'code_challenge' => $req->codeChallenge,
            'abilities' => $abilities,
            'resource' => $req->resource,
            'expires_at' => now()->addSeconds(max(30, (int) config('services.mcp.oauth.code_ttl', 300))),
        ]);

        return $code;
    }
}
