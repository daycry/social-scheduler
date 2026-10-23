<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta del endpoint /mcp. Es middleware GLOBAL (solo actua en /mcp) para ejecutarse ANTES de la
 * autenticacion: Laravel reordena los de ruta por prioridad y `auth` pasaria siempre por delante.
 *  - Valida `Origin` (spec MCP, anti DNS-rebinding): si viene y no esta permitido -> 403. Sin Origin se permite.
 *  - Limita por IP (contra adivinacion de keys): cuenta todas las peticiones, incluidas las de key falsa.
 *  - Registra los 401 (`mcp.auth_failed`) con la IP y sin el token.
 */
class McpGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('mcp')) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null && ! $this->originAllowed($origin)) {
            Log::warning('mcp.origin_rejected', ['ip' => $request->ip(), 'origin' => mb_substr($origin, 0, 200)]);

            return response()->json(['message' => 'Origin no permitido.'], 403);
        }

        $key = 'mcp-ip:'.$request->ip();
        $max = (int) config('services.mcp.per_minute_ip', 120);
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return response()->json(['message' => 'Demasiadas peticiones.'], 429, ['Retry-After' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);

        $response = $next($request);

        if ($response->getStatusCode() === 401) {
            Log::warning('mcp.auth_failed', ['ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 120)]);
        }

        return $response;
    }

    private function originAllowed(string $origin): bool
    {
        $allowed = array_filter([
            ...(array) config('services.mcp.allowed_origins', []),
            (string) config('app.url'),
        ]);

        return in_array($this->normalize($origin), array_map($this->normalize(...), $allowed), true);
    }

    private function normalize(string $url): string
    {
        $p = parse_url(trim($url));
        if (! isset($p['scheme'], $p['host'])) {
            return strtolower(trim($url));
        }

        return strtolower($p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : ''));
    }
}
