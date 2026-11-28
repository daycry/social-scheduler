<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\ShortLink;
use App\Services\ShortLinkService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Acortador propio (T-89) · gestion: alta, desactivar/reactivar y borrado de los enlaces `/s/{code}` del usuario. */
class ShortLinkController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ShortLinkService $links) {}

    /** Con `Accept: application/json` (compositor) devuelve el enlace corto; desde la pagina, vuelve a ella. */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(ShortLinkService::rules());

        try {
            $link = $this->links->create($request->user(), $data['url'], $data['label'] ?? null);
        } catch (PostValidationException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->errors()['url'][0], 'errors' => $e->errors()], 422)
                : back()->withErrors($e->errors())->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json(['id' => $link->id, 'code' => $link->code, 'short_url' => $link->shortUrl()], 201);
        }

        return back()->with('success', 'Enlace corto creado: '.$link->shortUrl());
    }

    public function update(Request $request, ShortLink $shortLink): RedirectResponse
    {
        $this->authorize('update', $shortLink);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $shortLink->update(['active' => $data['active']]);

        return back()->with('success', $shortLink->active ? 'Enlace corto reactivado.' : 'Enlace corto desactivado: ya no redirige.');
    }

    public function destroy(ShortLink $shortLink): RedirectResponse
    {
        $this->authorize('delete', $shortLink);
        $shortLink->delete();

        return back()->with('success', 'Enlace corto eliminado.');
    }
}
