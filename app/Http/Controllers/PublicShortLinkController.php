<?php

namespace App\Http\Controllers;

use App\Services\ShortLinkService;
use App\Support\SafeUrl;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redireccion publica `/s/{code}` (T-89). Sin sesion ni cookies (la ruta quita esos middleware) y sin guardar IP, agente ni
 * referer: solo el contador diario. Es un 302 con `no-store` (un 301 cacheado se saltaria el recuento y la desactivacion).
 * HEAD y la precarga del navegador (`Sec-Purpose`/`Purpose: prefetch`/`prerender`) redirigen pero NO cuentan: no son una persona.
 * El destino se revalida con `SafeUrl` aunque se validara al guardar; si su dominio es IDN (posible homografo) se enseña antes una
 * pagina intermedia con el dominio en punycode (el clic cuenta igual).
 */
class PublicShortLinkController extends Controller
{
    public function __construct(private readonly ShortLinkService $links) {}

    public function go(Request $request, string $code): Response
    {
        $link = $this->links->find($code);
        abort_if($link === null, 404);

        if (! $link->active) {
            return response()->view('shortlinks.notice', ['title' => 'Este enlace está desactivado', 'message' => 'Quien lo creó lo ha desactivado, así que ya no lleva a ningún sitio. Si te lo enviaron, pídele uno nuevo.'], 410)->withHeaders($this->headers());
        }
        abort_unless(SafeUrl::isSafe($link->url), 404);

        if (! $request->isMethod('HEAD') && ! $this->isPrefetch($request)) {
            $this->links->recordClick($link);
        }

        // Un destino con dominio internacionalizado (IDN) puede ser un homografo: se muestra el dominio real antes de seguir.
        if (SafeUrl::hasIdnHost($link->url)) {
            return response()->view('shortlinks.notice', [
                'title' => 'Vas a salir hacia otro sitio',
                'message' => 'Este enlace lleva a una dirección con caracteres especiales. Comprueba que el dominio es el que esperas:',
                'host' => SafeUrl::displayHost($link->url),
                'continue' => $link->url,
            ])->withHeaders($this->headers());
        }

        return redirect()->away($link->url, 302)->withHeaders($this->headers());
    }

    private function isPrefetch(Request $request): bool
    {
        foreach (['Sec-Purpose', 'Purpose'] as $header) {
            if (preg_match('/prefetch|prerender/i', (string) $request->header($header)) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'];
    }
}
