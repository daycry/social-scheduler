<?php

namespace App\Http\Controllers\Mcp\OAuth;

use App\Http\Controllers\Controller;
use App\Models\OAuthClient;
use App\Services\AuditLog;
use App\Services\Mcp\OAuth\RedirectPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** POST /oauth/register: registro dinamico de clientes publicos (RFC 7591), sin secreto. */
class RegisterController extends Controller
{
    private const MAX_REDIRECTS = 10;

    public function __invoke(Request $request, RedirectPolicy $policy, AuditLog $audit): JsonResponse
    {
        // Tope global ademas del limite por IP (C2): quien rote de IP no puede inundar la tabla.
        $global = 'mcp-oauth-register:global';
        if (RateLimiter::tooManyAttempts($global, max(1, (int) config('services.mcp.oauth.register_global_per_hour', 200)))) {
            return response()->json(['error' => 'temporarily_unavailable', 'error_description' => 'Demasiados registros; reintenta mas tarde.'], 429, ['Cache-Control' => 'no-store', 'Retry-After' => (string) RateLimiter::availableIn($global)]);
        }
        RateLimiter::hit($global, 3600);

        $body = $request->json()->all();

        $uris = $body['redirect_uris'] ?? null;
        if (! is_array($uris) || $uris === [] || ! array_is_list($uris) || count($uris) > self::MAX_REDIRECTS || array_filter($uris, fn ($u) => ! is_string($u)) !== []) {
            return $this->error('invalid_redirect_uri', 'redirect_uris debe ser una lista de entre 1 y '.self::MAX_REDIRECTS.' URLs.');
        }

        foreach ($uris as $uri) {
            if (! $policy->isAllowed($uri)) {
                return $this->error('invalid_redirect_uri', 'redirect_uri no permitida.');
            }
        }

        $name = $body['client_name'] ?? 'Cliente MCP';
        if (! is_string($name) || mb_strlen($name) > 100 || trim(strip_tags($name)) === '') {
            return $this->error('invalid_client_metadata', 'client_name no es valido.');
        }

        $method = $body['token_endpoint_auth_method'] ?? 'none';
        $grants = $body['grant_types'] ?? ['authorization_code'];
        $responses = $body['response_types'] ?? ['code'];
        if ($method !== 'none'
            || ! is_array($grants) || array_filter($grants, fn ($g) => ! is_string($g)) !== [] || array_diff($grants, ['authorization_code', 'refresh_token']) !== [] || ! in_array('authorization_code', $grants, true)
            || ! is_array($responses) || array_filter($responses, fn ($g) => ! is_string($g)) !== [] || $responses !== ['code']) {
            return $this->error('invalid_client_metadata', 'Solo se admiten clientes publicos con authorization_code y response_type code.');
        }

        $softwareId = $body['software_id'] ?? null;
        $softwareVersion = $body['software_version'] ?? null;
        foreach ([$softwareId, $softwareVersion] as $meta) {
            if ($meta !== null && (! is_string($meta) || mb_strlen($meta) > 255)) {
                return $this->error('invalid_client_metadata', 'software_id y software_version deben ser texto corto.');
            }
        }

        $client = OAuthClient::create([
            'software_id' => $softwareId === null ? null : strip_tags($softwareId),
            'software_version' => $softwareVersion === null ? null : mb_substr(strip_tags($softwareVersion), 0, 100),
            'created_ip' => $request->ip(),
            'name' => trim(strip_tags($name)),
            'redirect_uris' => array_values(array_unique($uris)),
        ]);

        $audit->record(null, 'oauth.client.registered', AuditLog::OK, 'oauth_client', null, 'client_id='.$client->id, 'oauth');

        return response()->json([
            'client_id' => $client->id,
            'client_name' => $client->name,
            'client_id_issued_at' => $client->created_at->timestamp,
            'redirect_uris' => $client->redirect_uris,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
        ], 201, ['Cache-Control' => 'no-store']);
    }

    private function error(string $code, string $description): JsonResponse
    {
        return response()->json(['error' => $code, 'error_description' => $description], 400, ['Cache-Control' => 'no-store']);
    }
}
