<?php

namespace App\Http\Controllers\Mcp\OAuth;

use App\Http\Controllers\Controller;
use App\Services\Mcp\OAuth\AuthorizationServer;
use App\Services\Mcp\OAuth\OAuthException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /oauth/token: canje del codigo (PKCE S256) por un token Sanctum `msk_`. Cliente publico, sin refresh. */
class TokenController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];

    public function __invoke(Request $request, AuthorizationServer $server): JsonResponse
    {
        try {
            return response()->json($server->redeemCode($request->all()), 200, self::HEADERS);
        } catch (OAuthException $e) {
            return response()->json(['error' => $e->error, 'error_description' => $e->description], $e->status, self::HEADERS);
        }
    }
}
