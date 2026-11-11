<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Models\SmartLink;
use App\Models\SmartLinkBlock;
use App\Services\SmartLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Pagina publica `/l/{slug}` y redireccion de clics. Sin login, sin sesion ni cookies (la ruta quita esos middleware
 * en `routes/parity.php`) y sin guardar IP ni agente: solo contadores diarios agregados.
 *
 * Mitigaciones (T-20): el contenido de usuario se sanea al guardar y se escapa al pintar (Blade `{{ }}`, sin HTML crudo);
 * la politica CSP no admite scripts y solo deja embeber YouTube (nocookie); `go` redirige UNICAMENTE a una URL guardada en el
 * propio bloque (nunca a un parametro de la peticion); el formulario de email tiene limite de ritmo y trampa antispam.
 */
class PublicSmartLinkController extends Controller
{
    public function __construct(private readonly SmartLinkService $links) {}

    public function show(Request $request, string $slug): Response
    {
        $link = $this->find($slug);
        $link->load(['blocks.media', 'avatar']);
        $this->links->recordView($link);

        $avatar = $this->urlOf($link->avatar);
        $blocks = $this->links->publicBlocks($link);
        $images = $blocks->where('type', SmartLinkBlock::TYPE_IMAGE)->mapWithKeys(fn (SmartLinkBlock $b) => [$b->id => $this->urlOf($b->media)])->filter()->all();
        $posts = $blocks->where('type', SmartLinkBlock::TYPE_POSTS)->mapWithKeys(fn (SmartLinkBlock $b) => [$b->id => $this->links->recentPosts($link, (int) ($b->data['count'] ?? 3))])->all();
        // Los bloques de imagen sin imagen disponible no se muestran (nunca una caja rota).
        $blocks = $blocks->reject(fn (SmartLinkBlock $b) => $b->type === SmartLinkBlock::TYPE_IMAGE && ! isset($images[$b->id]))->values();

        return response()->view('smartlinks.show', [
            'link' => $link, 'blocks' => $blocks, 'avatar' => $avatar, 'images' => $images, 'posts' => $posts, 'subscribed' => in_array($request->query('subscribed'), ['0', '1'], true) ? $request->query('subscribed') : null,
        ])
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline'; frame-src https://www.youtube-nocookie.com; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function go(string $slug, int $block, Request $request): RedirectResponse
    {
        $link = $this->find($slug);
        $target = $link->blocks()->whereKey($block)->visibleNow()->first();
        $index = max(0, min(7, (int) $request->query('i', 0)));
        $url = $target ? $this->links->clickTarget($target, $index) : null;

        // Se revalida el destino aunque se validara al guardar: nunca se redirige a un esquema que no sea http/https, ni a
        // algo que no este guardado en el bloque (el indice solo elige entre las URL del propio bloque).
        if (! $url) {
            abort(404);
        }

        $this->links->recordClick($link, $target);

        return redirect()->away($url)->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /** Alta en el formulario de email: respuesta identica para nuevo, repetido o invalido; el antispam responde igual. */
    public function subscribe(Request $request, string $slug, int $block): RedirectResponse
    {
        $link = $this->find($slug);
        $target = $link->blocks()->whereKey($block)->where('type', SmartLinkBlock::TYPE_EMAIL)->visibleNow()->first();
        if (! $target) {
            abort(404);
        }
        // Sin sesion no hay `back()` con errores: se valida a mano y la respuesta es siempre una redireccion simple.
        $data = ['email' => mb_substr((string) $request->input('email', ''), 0, 255), 'website' => (string) $request->input('website', '')];

        // Trampa antispam: un humano no ve el campo `website`; si viene relleno se responde igual sin guardar.
        if (($data['website'] ?? '') === '') {
            if (! $this->links->subscribe($link, $target, $data['email'])) {
                return redirect('/l/'.$link->slug.'?subscribed=0#suscripcion');
            }
        }

        return redirect('/l/'.$link->slug.'?subscribed=1#suscripcion');
    }

    private function find(string $slug): SmartLink
    {
        return SmartLink::withoutUserScope()->where('slug', $slug)->where('published', true)->firstOrFail();
    }

    private function urlOf(?MediaAsset $media): ?string
    {
        if (! $media || ! $media->isReady()) {
            return null;
        }

        try {
            return $media->publicUrl();
        } catch (Throwable) {
            return null; // disco sin URLs temporales (p. ej. local): la pagina sale sin la imagen
        }
    }
}
