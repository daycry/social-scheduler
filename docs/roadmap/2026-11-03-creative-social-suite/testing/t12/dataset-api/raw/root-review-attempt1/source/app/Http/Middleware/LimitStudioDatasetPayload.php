<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Runs before input transforms; Content-Length is not a decoding bound. */
final class LimitStudioDatasetPayload
{
    public const MAX_JSON_BYTES = 53 * 1024 * 1024;

    public const MAX_MCP_JSON_BYTES = 160 * 1024 * 1024;

    public function handle(Request $request, Closure $next)
    {
        if (! $request->isJson() || ! $request->is('api/v1/brands/*/datasets', 'api/v1/brands/*/datasets/*', 'brands/*/studio/datasets', 'brands/*/studio/datasets/*', 'mcp')) {
            return $next($request);
        }
        $max = $request->is('mcp') ? self::MAX_MCP_JSON_BYTES : self::MAX_JSON_BYTES;
        $stream = $request->getContent(true);
        $bytes = stream_get_contents($stream, $max + 1);
        abort_if($bytes === false, 400, 'Unreadable request body.');
        abort_if(strlen($bytes) > $max, 413, 'Dataset JSON body too large.');
        // Supported Symfony content setter; Laravel's route/user/session resolvers
        // remain on the same request. Preserve headers modified by callers too.
        $headers = $request->headers->all();
        $request->initialize($request->query->all(), $request->request->all(), $request->attributes->all(), $request->cookies->all(), $request->files->all(), $request->server->all(), $bytes);
        $request->headers->replace($headers);

        return $next($request);
    }
}
