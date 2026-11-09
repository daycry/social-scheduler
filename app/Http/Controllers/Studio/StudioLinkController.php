<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioCollab;
use App\Services\Studio\StudioFonts;
use App\Services\Studio\StudioSharedFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Acceso anónimo por enlace de solo lectura o comentario. El token se comprueba contra su hash en cada petición, así que
 * revocarlo o dejarlo caducar corta el acceso al instante. Cualquier fallo responde un 404 uniforme.
 */
class StudioLinkController extends Controller
{
    public function __construct(private readonly StudioCollab $collab, private readonly StudioSharedFiles $shared, private readonly StudioAssets $assets, private readonly StudioFonts $fonts) {}

    public function show(Request $request, string $token): Response
    {
        [$link, $design, $owner] = $this->collab->resolveLink($token);
        $link->forceFill(['last_used_at' => now()])->save();
        $brand = Brand::withoutUserScope()->where('user_id', $owner->id)->findOrFail($design->brand_id);
        $routes = ['asset' => fn (int $id) => route('studio.link.asset', ['token' => $token, 'media' => $id], false), 'font' => fn (int $id) => route('studio.link.font', ['token' => $token, 'font' => $id], false)];
        $assets = $this->assets->list($owner, $brand)->map(fn ($asset) => $this->assets->present($brand, $asset))->all();
        $rewritten = $this->shared->rewrite($design, $link->role, $assets, $this->fonts->neededFor($owner, $brand, $design->document), $routes);

        return Inertia::render('Studio/Review', ['design' => ['id' => $design->id, 'name' => $design->name, 'document' => $design->document, 'revision' => $design->revision, 'fonts' => $rewritten['fonts']],
            'brand' => ['id' => $brand->id, 'name' => $brand->name], 'assets' => $rewritten['assets'], 'fonts' => $rewritten['fonts'],
            'access' => $this->collab->summary($link->role, $design) + ['is_guest' => true], 'link' => ['token' => $token]]);
    }

    public function comments(Request $request, string $token): JsonResponse
    {
        [, $design] = $this->collab->resolveLink($token);

        return response()->json(['comments' => $this->guestRows($design)]);
    }

    public function addComment(Request $request, string $token): JsonResponse
    {
        [$link, $design] = $this->collab->resolveLink($token);
        abort_unless($link->role === 'commenter', 403, 'Este enlace es de solo lectura.');
        $comment = $this->collab->addComment($design, null, $link, $request->only(['body', 'page_id', 'element_id', 'parent_id', 'guest_name']));

        return response()->json(['comment' => collect($this->guestRows($design))->firstWhere('id', $comment->id)], 201);
    }

    public function asset(Request $request, string $token, int $media): StreamedResponse
    {
        [$link, $design, $owner] = $this->collab->resolveLink($token);

        return $this->shared->asset($owner, $design, $link->role, $media, $request->header('Range'));
    }

    public function font(Request $request, string $token, int $font): StreamedResponse
    {
        [$link, $design, $owner] = $this->collab->resolveLink($token);

        return $this->shared->font($owner, $design, $link->role, $font);
    }

    private function guestRows(object $design): array
    {
        return array_map(fn (array $comment) => ['author_id' => null, 'can_resolve' => false, 'can_delete' => false] + $comment, $this->collab->comments($design));
    }
}
