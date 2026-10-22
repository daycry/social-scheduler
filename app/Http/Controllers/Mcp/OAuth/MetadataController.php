<?php

namespace App\Http\Controllers\Mcp\OAuth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Laravel\Mcp\Server\Registrar;

/** Metadatos de descubrimiento OAuth del MCP: recurso protegido (RFC 9728) y servidor de autorizacion (RFC 8414). */
class MetadataController extends Controller
{
    /** GET /.well-known/oauth-protected-resource[/{path}] */
    public function protectedResource(?string $path = null): JsonResponse
    {
        // El unico recurso protegido es /mcp; cualquier otra variante anidada no existe.
        if ($path !== null && trim($path, '/') !== 'mcp') {
            abort(404);
        }

        return response()->json([
            'resource' => self::base().'/mcp',
            'authorization_servers' => [self::base()],
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    /** GET /.well-known/oauth-authorization-server */
    public function authorizationServer(): JsonResponse
    {
        $base = self::base();

        return response()->json([
            'issuer' => $base,
            'authorization_endpoint' => $base.'/oauth/authorize',
            'token_endpoint' => $base.'/oauth/token',
            'registration_endpoint' => $base.'/oauth/register',
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
    }

    private static function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
