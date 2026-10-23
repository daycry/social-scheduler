<?php

namespace App\Http\Controllers;

use App\Models\SmartLink;
use App\Models\SmartLinkBlock;
use App\Services\SmartLinkService;
use App\Support\SafeUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Throwable;

/**
 * Pagina publica `/l/{slug}` y redireccion de clics. Sin login, sin sesion ni cookies (la ruta quita esos middleware
 * en `routes/parity.php`) y sin guardar IP ni agente: solo contadores diarios agregados.
 */
class PublicSmartLinkController extends Controller
{
    public function __construct(private readonly SmartLinkService $links) {}

    public function show(string $slug): Response
    {
        $link = $this->find($slug);
        $link->load(['blocks', 'avatar']);
        $this->links->recordView($link);

        $avatar = null;
        if ($link->avatar && $link->avatar->isReady()) {
            try {
                $avatar = $link->avatar->publicUrl();
            } catch (Throwable) {
                $avatar = null; // disco sin URLs temporales (p. ej. local): la pagina sale sin avatar
            }
        }

        $blocks = $link->blocks->filter(fn (SmartLinkBlock $b) => $b->type === SmartLinkBlock::TYPE_HEADER || SafeUrl::isSafe($b->url))->values();

        return response()->view('smartlinks.show', ['link' => $link, 'blocks' => $blocks, 'avatar' => $avatar])
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function go(string $slug, int $block): RedirectResponse
    {
        $link = $this->find($slug);
        $target = $link->blocks()->whereKey($block)->where('type', SmartLinkBlock::TYPE_LINK)->first();

        // Se revalida el destino aunque se validara al guardar: nunca se redirige a un esquema que no sea http/https.
        if (! $target || ! SafeUrl::isSafe($target->url)) {
            abort(404);
        }

        $this->links->recordClick($link, $target);

        return redirect()->away($target->url)->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    private function find(string $slug): SmartLink
    {
        return SmartLink::withoutUserScope()->where('slug', $slug)->where('published', true)->firstOrFail();
    }
}
