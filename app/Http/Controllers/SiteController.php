<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Site;
use App\Models\SiteToken;
use App\Models\Studio\DesignExport;
use App\Services\Sites\SiteRenderer;
use App\Services\Sites\SiteService;
use App\Support\CurrentBrand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Sitios · gestion (T-15): lista, constructor del borrador, vista previa, publicacion, acceso y analitica. Solo el propietario. */
class SiteController extends Controller
{
    public function __construct(private readonly SiteService $sites, private readonly SiteRenderer $renderer) {}

    public function index(Request $request): InertiaResponse
    {
        $brand = CurrentBrand::resolve($request);
        $sites = $brand ? Site::query()->where('brand_id', $brand->id)->orderByDesc('id')->get() : collect();

        return Inertia::render('Sites/Index', [
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'sites' => $sites->map(fn (Site $s) => $this->summary($s))->values(),
            'max_sites' => SiteService::MAX_SITES_PER_BRAND,
            'flash' => ['success' => $request->session()->get('success')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['brand_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:100'], 'slug' => ['required', 'string', 'max:40']]);
        $brand = Brand::query()->findOrFail((int) $data['brand_id']);

        try {
            $site = $this->sites->create($request->user(), $brand, $data['title'], $data['slug']);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('sites.edit', $site)->with('success', 'Sitio creado. Edita las páginas y publícalo cuando esté listo.');
    }

    public function edit(Request $request, Site $site): InertiaResponse
    {
        $brand = $site->brand()->firstOrFail();
        $user = $request->user();

        return Inertia::render('Sites/Edit', [
            'site' => $this->summary($site) + [
                'description' => $site->description, 'theme_source' => $site->theme_source, 'theme_color' => $site->theme_color ?? '#4f46e5',
                'theme_mode' => $site->theme_mode, 'access_mode' => $site->access_mode, 'has_password' => (bool) $site->password_hash,
                'pages' => $site->draft['pages'],
            ],
            'theme' => $this->sites->theme($user, $site),
            'brand' => ['id' => $brand->id, 'name' => $brand->name],
            'tokens' => SiteToken::query()->where('site_id', $site->id)->latest('id')->limit(50)->get()->map(fn (SiteToken $t) => [
                'id' => $t->id, 'label' => $t->label, 'expires_at' => $t->expires_at?->toIso8601String(), 'revoked_at' => $t->revoked_at?->toIso8601String(),
                'last_used_at' => $t->last_used_at?->toIso8601String(), 'state' => $t->revoked_at ? 'revoked' : ($t->expires_at && $t->expires_at->isPast() ? 'expired' : 'active'),
            ])->values(),
            'new_token' => $request->session()->get('site_token'),
            'stats' => $this->sites->stats($site),
            'messages' => DB::table('site_messages')->where('site_id', $site->id)->orderByDesc('id')->limit(50)->get(['id', 'name', 'email', 'message', 'created_at'])->values(),
            'images' => MediaAsset::query()->where('kind', MediaAsset::KIND_IMAGE)->where('status', MediaAsset::STATUS_READY)->latest()->limit(100)->get()
                ->map(fn (MediaAsset $m) => ['id' => $m->id, 'name' => $m->original_name, 'url' => route('sites.preview-media', ['site' => $site->id, 'media' => $m->id], false)])->values(),
            'exports' => DesignExport::query()->where('brand_id', $brand->id)->where('status', 'ready')->latest('id')->limit(50)->with('design')->get()
                ->map(fn (DesignExport $x) => ['id' => $x->id, 'label' => ($x->design?->name ?? 'Diseño').' · revisión '.$x->revision, 'pages' => count((array) $x->media_ids)])->values(),
            'limits' => ['pages' => SiteService::MAX_PAGES, 'blocks' => SiteService::MAX_BLOCKS],
            'flash' => ['success' => $request->session()->get('success')],
        ]);
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $request->validate(['title' => ['required', 'string', 'max:100'], 'slug' => ['required', 'string', 'max:40'], 'pages' => ['required', 'array', 'max:'.SiteService::MAX_PAGES]]);

        try {
            $this->sites->saveDraft($request->user(), $site, $request->all());
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('success', 'Borrador guardado. Lo publicado no cambia hasta que vuelvas a publicar.');
    }

    public function publish(Request $request, Site $site): RedirectResponse
    {
        try {
            $this->sites->publish($request->user(), $site);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Sitio publicado: '.$site->url());
    }

    public function unpublish(Request $request, Site $site): RedirectResponse
    {
        $this->sites->unpublish($request->user(), $site);

        return back()->with('success', 'Sitio despublicado: ya no es accesible.');
    }

    public function access(Request $request, Site $site): RedirectResponse
    {
        $data = $request->validate(['access_mode' => ['required', 'in:'.implode(',', Site::ACCESS_MODES)], 'password' => ['nullable', 'string', 'max:128'], 'noindex' => ['nullable', 'boolean']]);

        try {
            $this->sites->updateAccess($request->user(), $site, $data['access_mode'], $data['password'] ?? null, (bool) ($data['noindex'] ?? false));
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Acceso actualizado. Las sesiones anteriores se han cerrado si cambió el modo o la contraseña.');
    }

    public function storeToken(Request $request, Site $site): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:60'], 'days' => ['nullable', 'integer', 'min:1', 'max:365']]);

        try {
            [, $plain] = $this->sites->createToken($request->user(), $site, $data['label'], isset($data['days']) ? (int) $data['days'] : null);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('site_token', ['label' => $data['label'], 'url' => $site->url().'?t='.$plain])->with('success', 'Enlace creado. Cópialo ahora: no se vuelve a mostrar.');
    }

    public function destroyToken(Request $request, Site $site, SiteToken $token): RedirectResponse
    {
        $this->sites->revokeToken($request->user(), $site, $token);

        return back()->with('success', 'Enlace revocado: deja de funcionar al instante.');
    }

    public function revokeAll(Request $request, Site $site): RedirectResponse
    {
        $this->sites->revokeAllAccess($request->user(), $site);

        return back()->with('success', 'Acceso revocado: se cerraron todas las sesiones y se revocaron todos los enlaces.');
    }

    public function destroyMessage(Site $site, int $message): RedirectResponse
    {
        DB::table('site_messages')->where('site_id', $site->id)->where('id', $message)->delete();

        return back()->with('success', 'Mensaje eliminado.');
    }

    public function destroy(Request $request, Site $site): RedirectResponse
    {
        $brandId = $site->brand_id;
        $this->sites->delete($request->user(), $site);

        return redirect()->route('sites.index', ['brand_id' => $brandId])->with('success', 'Sitio eliminado.');
    }

    /** Vista previa del BORRADOR con el mismo render que lo publicado (se enmarca en un iframe del propio origen). */
    public function preview(Request $request, Site $site, ?string $page = null): Response
    {
        $draft = $site->draft['pages'];
        $doc = ['title' => $site->title, 'description' => $site->description, 'theme' => $this->sites->theme($request->user(), $site), 'noindex' => true,
            'pages' => $draft, 'media' => $this->sites->mediaIds($draft)];
        $response = $this->renderer->page($site, $doc, $page, true, $request->query());
        abort_unless($response, 404);

        return $response;
    }

    public function previewMedia(Site $site, int $media): StreamedResponse
    {
        $asset = MediaAsset::query()->where('kind', MediaAsset::KIND_IMAGE)->whereKey($media)->firstOrFail();
        abort_unless($asset->isReady(), 404);

        return PublicSiteController::stream($asset);
    }

    /** @return array<string, mixed> */
    private function summary(Site $s): array
    {
        return [
            'id' => $s->id, 'slug' => $s->slug, 'title' => $s->title, 'is_published' => $s->is_published, 'public_url' => $s->url(),
            'published_at' => $s->published_at?->toIso8601String(), 'revision' => $s->revision, 'published_revision' => $s->published_revision,
            'dirty' => $s->is_published && $s->published_revision !== $s->revision, 'noindex' => $s->noindex, 'access_mode' => $s->access_mode,
        ];
    }
}
