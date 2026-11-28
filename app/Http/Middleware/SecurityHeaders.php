<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isProduction() || ! $request->isSecure()) {
            return $next($request);
        }

        $response = config('security.csp_enabled')
            ? $this->withContentSecurityPolicy($request, $next)
            : $next($request);

        $age = config('security.hsts_max_age');
        $maxAge = is_int($age) || is_string($age)
            ? filter_var($age, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 63_072_000]])
            : false;
        if ($maxAge !== false) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.$maxAge);
        }
        if (! $response->headers->has('Permissions-Policy')) {
            $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        }

        return $response;
    }

    private function withContentSecurityPolicy(Request $request, Closure $next): Response
    {
        $previous = Vite::getFacadeRoot();
        $vite = clone $previous;
        $nonce = $vite->useCspNonce();
        Vite::swap($vite);
        try {
            $response = $next($request);
        } finally {
            Vite::swap($previous);
        }

        // Keep purpose-built policies on public sites, downloads and OAuth screens.
        if (! str_starts_with(strtolower($response->headers->get('Content-Type', '')), 'text/html')
            || $response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only')) {
            return $response;
        }

        $report = route('security.csp-report', absolute: false);
        $header = config('security.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        $response->headers->set($header, implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://accounts.google.com/gsi/client https://apis.google.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' https: data: blob:",
            "media-src 'self' https: blob:",
            "connect-src 'self' https:",
            "frame-src 'self' https://www.youtube-nocookie.com https://www.youtube.com https://docs.google.com https://accounts.google.com/gsi/",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            'report-uri '.$report,
            'report-to metricool-csp',
        ]));
        $response->headers->set('Reporting-Endpoints', 'metricool-csp="'.$request->getSchemeAndHttpHost().$report.'"');

        return $response;
    }
}
