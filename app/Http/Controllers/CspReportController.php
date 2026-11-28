<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class CspReportController
{
    private const MAX_BODY_BYTES = 16_384;

    private const MAX_REPORTS = 10;

    public function __invoke(Request $request): Response
    {
        if (! app()->isProduction() || ! $request->isSecure() || ! config('security.csp_enabled')) {
            return response('', 404);
        }

        $mime = strtolower(trim(explode(';', $request->headers->get('Content-Type', ''), 2)[0]));
        if (! in_array($mime, ['application/csp-report', 'application/reports+json'], true)) {
            return response('', 415);
        }

        $content = $request->getContent();
        if (strlen($content) > self::MAX_BODY_BYTES) {
            return response('', 413);
        }
        $data = json_decode($content, true, 16);
        if (! is_array($data)) {
            return response('', 400);
        }

        $reports = $mime === 'application/csp-report'
            ? [['type' => 'csp-violation', 'body' => $data['csp-report'] ?? null]]
            : $data;
        if (! array_is_list($reports)) {
            return response('', 400);
        }

        foreach (array_slice($reports, 0, self::MAX_REPORTS) as $report) {
            if (is_array($report) && ($report['type'] ?? null) === 'csp-violation' && is_array($report['body'] ?? null)) {
                $this->record($report['body']);
            }
        }

        return response()->noContent();
    }

    private function record(array $body): void
    {
        $directive = $body['effectiveDirective'] ?? $body['effective-directive'] ?? null;
        if (! is_string($directive) || ! preg_match('/\A[a-z][a-z0-9-]{0,39}\z/', $directive)) {
            return;
        }

        Log::channel('csp')->notice('security.csp_violation', [
            'directive' => $directive,
            'blocked' => $this->blockedSource($body['blockedURL'] ?? $body['blocked-uri'] ?? null),
        ]);
    }

    private function blockedSource(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 2048) {
            return 'unknown';
        }
        if (in_array($value, ['inline', 'eval', 'wasm-eval', 'data', 'blob'], true)) {
            return $value;
        }

        $url = parse_url($value);
        $scheme = strtolower($url['scheme'] ?? '');
        if (in_array($scheme, ['data', 'blob'], true)) {
            return $scheme;
        }
        $host = strtolower($url['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || ! preg_match('/\A[a-z0-9.-]{1,253}\z/', $host)) {
            return 'unknown';
        }

        return $scheme.'://'.$host.(isset($url['port']) ? ':'.$url['port'] : '');
    }
}
