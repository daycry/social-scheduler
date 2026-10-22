<?php

namespace App\Http\Controllers\Mcp\OAuth;

use App\Http\Controllers\Controller;
use App\Models\OAuthClient;
use App\Services\AuditLog;
use App\Services\Mcp\OAuth\RedirectPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /oauth/register: registro dinamico de clientes publicos (RFC 7591), sin secreto. */
class RegisterController extends Controller
{
    private const MAX_REDIRECTS = 10;

    public function __invoke(Request $request, RedirectPolicy $policy, AuditLog $audit): JsonResponse
    {
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
            || ! is_array($grants) || array_diff($grants, ['authorization_code', 'refresh_token']) !== [] || ! in_array('authorization_code', $grants, true)
            || ! is_array($responses) || $responses !== ['code']) {
            return $this->error('invalid_client_metadata', 'Solo se admiten clientes publicos con authorization_code y response_type code.');
        }

        $client = OAuthClient::create([
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
