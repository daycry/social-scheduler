<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Models\Site;
use App\Services\Sites\SiteAccess;
use App\Services\Sites\SiteRenderer;
use App\Services\Sites\SiteService;
use App\Support\SiteView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Sitios publicados `/s/{slug}` (T-15). Sin login, sin sesion de Laravel ni CSRF (la ruta los quita en `routes/sites.php`).
 * Sirve SOLO la instantanea publicada; despublicar o revocar tiene efecto en la siguiente peticion (404 / acceso denegado).
 *
 * Mitigaciones: contenido de usuario saneado al guardar y escapado al pintar (sin HTML crudo salvo el marcado propio de `SiteRichText`);
 * CSP sin scripts; NINGUNA redireccion a destinos de la peticion (los botones son enlaces directos a URLs guardadas y los
 * redirects del propio sitio van siempre a `/s/{slug}/...`); IP solo como hash en limitadores de cache, nunca en base de datos.
 */
class PublicSiteController extends Controller
{
    public function __construct(private readonly SiteService $sites, private readonly SiteAccess $access, private readonly SiteRenderer $renderer) {}

    public function show(Request $request, string $slug, ?string $page = null): Response|RedirectResponse
    {
        $site = $this->lookup($slug);
        if (! $site) {
            return $this->moved($request, $slug, $page);
        }

        if ($site->access_mode === Site::ACCESS_TOKEN && is_string($request->query('t')) && $request->query('t') !== '') {
            $tokenId = $this->access->openWithToken($request, $site, $request->query('t'));
            if ($tokenId === null) {
                return $this->renderer->gate($site, 'token', null, 403);
            }
            // El token sale de la URL (historial, capturas): se canjea por una cookie firmada y se redirige a la misma pagina sin el.
            $clean = '/s/'.$site->publicSlug().($page ? '/'.$page : '');

            return redirect($clean)->withCookie($this->access->grant($request, $site, $tokenId))->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
        }

        if (! $this->access->allows($request, $site)) {
            return $site->access_mode === Site::ACCESS_PASSWORD ? $this->renderer->gate($site, 'password', null, 401) : $this->renderer->gate($site, 'token', null, 403);
        }

        $doc = $site->published;
        $response = $this->renderer->page($site, $doc, $page, false, $request->query());
        abort_unless($response, 404);

        $current = $page === null ? $doc['pages'][0] : collect($doc['pages'])->firstWhere('slug', $page);
        $this->sites->recordVisit($site, $current['key']);

        return $response;
    }

    /** Contrasena del sitio: limitada por (hash de IP + sitio); acierto = cookie firmada y vuelta a la portada. */
    public function unlock(Request $request, string $slug): Response|RedirectResponse
    {
        $site = $this->find($slug);
        abort_unless($site->access_mode === Site::ACCESS_PASSWORD, 404);

        // El intento se reserva ANTES de verificar (contador atomico) y hay un tope global por sitio.
        if (! $this->access->reserveAttempt($request, $site)) {
            return $this->renderer->gate($site, 'password', 'Demasiados intentos. Espera unos minutos.', 429)->header('Retry-After', '300');
        }
        if (! $this->access->checkPassword($site, mb_substr((string) $request->input('password', ''), 0, 128))) {
            $this->access->failedAttempt($site);

            return $this->renderer->gate($site, 'password', 'Contraseña incorrecta.', 401);
        }
        $this->access->clearPassword($request, $site);

        return redirect('/s/'.$site->publicSlug())->withCookie($this->access->grant($request, $site, 0))->header('Cache-Control', 'no-store');
    }

    public function media(Request $request, string $slug, int $media): StreamedResponse
    {
        $site = $this->find($slug);
        abort_unless($this->access->allows($request, $site) && in_array($media, $site->published['media'] ?? [], true), 404);
        $asset = MediaAsset::withoutUserScope()->where('user_id', $site->user_id)->where('kind', MediaAsset::KIND_IMAGE)->find($media);
        abort_unless($asset && $asset->isReady(), 404);

        return self::stream($asset);
    }

    public static function stream(MediaAsset $asset): StreamedResponse
    {
        try {
            $stream = Storage::disk($asset->disk)->readStream($asset->path);
        } catch (Throwable) {
            $stream = null;
        }
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => in_array($asset->mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true) ? $asset->mime : 'application/octet-stream',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-cache, must-revalidate',
            'Content-Disposition' => 'inline',
        ]);
    }

    /** Formulario de contacto: honeypot, marca de tiempo firmada, limite por (hash de IP + sitio) y tope de mensajes. Respuesta uniforme. */
    public function contact(Request $request, string $slug, string $pageKey, string $blockKey): RedirectResponse
    {
        $site = $this->find($slug);
        abort_unless($this->access->allows($request, $site), 404);
        [$page, $block] = $this->block($site, $pageKey, $blockKey, 'contact');
        $back = $this->pageUrl($site, $page);

        if ((string) $request->input('website', '') !== '') {
            return redirect($back.'?sent=1#contacto'); // trampa: igual que un envio correcto, sin guardar nada
        }
        // Reserva atomica: por cliente (5 cada 10 min; IPv6 por /64) y, ademas, 30 por hora para todo el sitio.
        $perClient = RateLimiter::hit($this->access->limiterKey($request, $site, 'contact'), 600);
        $perSite = RateLimiter::hit('site-contact-all:'.$site->id, 3600);
        if ($perClient > 5 || $perSite > 30) {
            return redirect($back.'?sent=0#contacto');
        }

        $age = SiteView::tokenAge($site, $block['key'], (string) $request->input('ts', ''));
        $ok = $age !== null && $age >= 2 && $age <= 7200
            && $this->sites->addMessage($site, $page['key'], $block['key'], (string) $request->input('name', ''), (string) $request->input('email', ''), (string) $request->input('message', ''));

        return redirect($back.'?sent='.($ok ? '1' : '0').'#contacto');
    }

    /** Voto de una encuesta: uno por bloque y hora para cada (hash de IP + sitio); luego se muestran los resultados agregados. */
    public function vote(Request $request, string $slug, string $pageKey, string $blockKey): RedirectResponse
    {
        $site = $this->find($slug);
        abort_unless($this->access->allows($request, $site), 404);
        [$page, $block] = $this->block($site, $pageKey, $blockKey, 'poll');

        // Un voto por bloque y hora y cliente (IPv6 por /64): `Cache::add` reserva de forma atomica y solo gana la primera peticion.
        $option = $request->input('option');
        if (is_numeric($option) && (int) $option >= 0 && (int) $option < count($block['options']) && Cache::add($this->access->limiterKey($request, $site, 'vote-'.$block['key']), 1, 3600)) {
            $this->sites->vote($site, $block, (int) $option);
        }

        return redirect($this->pageUrl($site, $page).'?voted='.$block['key'].'#p-'.$block['key']);
    }

    private function lookup(string $slug): ?Site
    {
        return Site::withoutUserScope()->where('public_slug', $slug)->where('is_published', true)->whereNotNull('published')->first();
    }

    private function find(string $slug): Site
    {
        return $this->lookup($slug) ?? abort(404);
    }

    /** Direccion antigua de un sitio publicado: 301 a la actual con la misma pagina y consulta (el token `?t=` se conserva). */
    private function moved(Request $request, string $slug, ?string $page): RedirectResponse
    {
        $siteId = DB::table('site_slug_history')->where('slug', $slug)->value('site_id');
        $site = $siteId ? Site::withoutUserScope()->where('is_published', true)->whereNotNull('published')->find($siteId) : null;
        abort_unless($site, 404);
        $query = $request->getQueryString();

        return redirect('/s/'.$site->publicSlug().($page ? '/'.$page : '').($query ? '?'.$query : ''), 301)->header('Referrer-Policy', 'no-referrer');
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function block(Site $site, string $pageKey, string $blockKey, string $type): array
    {
        $page = collect($site->published['pages'])->firstWhere('key', $pageKey);
        $block = $page ? collect($page['blocks'])->first(fn ($b) => $b['key'] === $blockKey && $b['type'] === $type) : null;
        abort_unless($page && $block, 404);

        return [$page, $block];
    }

    private function pageUrl(Site $site, array $page): string
    {
        $first = $site->published['pages'][0]['key'] === $page['key'];

        return '/s/'.$site->publicSlug().($first ? '' : '/'.$page['slug']);
    }
}
