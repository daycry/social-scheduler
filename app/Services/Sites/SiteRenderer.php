<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Support\SiteView;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Render UNICO de un sitio (T-15): lo usan la ruta publica (instantanea publicada) y la vista previa del editor (borrador), asi lo
 * que se ve en el editor es lo que se publica. Aqui tambien viven la CSP y las cabeceras de seguridad de las dos.
 */
class SiteRenderer
{
    public function __construct(private readonly SiteService $sites) {}

    /**
     * @param  array<string, mixed>  $doc  instantanea (publica) o borrador + tema (vista previa)
     * @param  array<string, mixed>  $query  `sent`, `voted`, `results` de la URL
     */
    public function page(Site $site, array $doc, ?string $pageSlug, bool $preview, array $query): ?Response
    {
        $pages = $doc['pages'];
        $page = $pageSlug === null ? ($pages[0] ?? null) : collect($pages)->firstWhere('slug', $pageSlug);
        if (! $page) {
            return null;
        }

        $nonce = base64_encode(random_bytes(16));
        $view = new SiteView($site, $doc, $preview, $doc['media']);
        $polls = [];
        foreach ($page['blocks'] as $b) {
            if ($b['type'] === 'poll') {
                $polls[$b['key']] = $this->sites->pollResults($site, $b);
            }
        }
        $state = [
            'sent' => in_array($query['sent'] ?? null, ['0', '1'], true) ? $query['sent'] : null,
            'voted' => is_string($query['voted'] ?? null) && preg_match('/^[a-f0-9]{8}$/', $query['voted']) === 1 ? $query['voted'] : null,
        ];

        $response = response()->view('sites.show', [
            'doc' => $doc, 'page' => $page, 'view' => $view, 'preview' => $preview, 'nonce' => $nonce, 'polls' => $polls, 'state' => $state,
            'showResults' => ($query['results'] ?? null) === '1', 'noindex' => $site->noindex || $preview || $site->access_mode !== Site::ACCESS_PUBLIC,
        ]);

        return $this->secure($response, $nonce, $preview, $site->access_mode !== Site::ACCESS_PUBLIC || $site->noindex);
    }

    public function gate(Site $site, string $mode, ?string $error, int $status): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $response = response()->view('sites.gate', ['mode' => $mode, 'slug' => $site->publicSlug(), 'error' => $error, 'nonce' => $nonce], $status);

        return $this->secure($response, $nonce, false, true);
    }

    /** CSP sin scripts ni estilos en linea (solo el `<style>` con nonce), solo YouTube nocookie como iframe y solo imagenes propias. */
    public function secure(Response $response, string $nonce, bool $preview, bool $private): Response
    {
        return $response
            ->header('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'nonce-{$nonce}'; frame-src https://www.youtube-nocookie.com; form-action 'self'; base-uri 'none'; frame-ancestors ".($preview ? "'self'" : "'none'"))
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', $private ? 'no-referrer' : 'strict-origin-when-cross-origin')
            ->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->header('Cross-Origin-Opener-Policy', 'same-origin')
            ->header('Cache-Control', 'private, no-cache, no-store, must-revalidate')
            ->header('X-Robots-Tag', $private || $preview ? 'noindex, nofollow' : 'all');
    }

    public static function nonce(): string
    {
        return Str::random(22);
    }
}
