<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignFolder;
use App\Models\User;
use App\Services\Studio\Ai\AiProviders;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioAutomation;
use App\Services\Studio\StudioAvControl;
use App\Services\Studio\StudioCollab;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioFonts;
use App\Services\Studio\StudioFrames;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use App\Services\Studio\StudioSharedFiles;
use App\Support\CurrentBrand;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudioController extends Controller
{
    public function __construct(private readonly StudioService $studio, private readonly StudioAssets $assets, private readonly StudioExports $exports, private readonly StudioCollab $collab, private readonly StudioSharedFiles $shared) {}

    public function index(Request $request): Response|JsonResponse
    {
        $brand = CurrentBrand::resolve($request);
        if ($brand === null) {
            return Inertia::render('Studio/Index', ['brand' => null, 'designs' => ['designs' => [], 'has_more' => false, 'next_cursor' => null], 'kit' => null, 'assets' => [], 'folders' => [], 'filters' => [], 'shared' => $this->collab->sharedWith($request->user())]);
        }

        return $this->indexBrand($request, $brand);
    }

    public function indexBrand(Request $request, Brand $brand): Response|JsonResponse
    {
        $filters = $request->validate(['query' => ['nullable', 'string', 'max:120'], 'folder_id' => ['nullable', 'integer', 'min:1'],
            'templates' => ['nullable', 'boolean'], 'archived' => ['nullable', 'boolean']] + CursorPage::RULES);
        $designs = $this->studio->designs($request->user(), $brand, $filters);
        $props = $this->brandProps($request, $brand) + ['designs' => $designs, 'filters' => $filters, 'shared' => $this->collab->sharedWith($request->user())];

        return $request->expectsJson() ? response()->json($props) : Inertia::render('Studio/Index', $props);
    }

    public function store(Request $request, Brand $brand): JsonResponse|RedirectResponse
    {
        $design = $this->studio->create($request->user(), $brand, $request->all());

        return $request->expectsJson() ? response()->json(['design' => $this->studio->present($request->user(), $design)], 201)
            : redirect()->route('studio.designs.edit', $design)->with('success', 'Diseño creado.');
    }

    public function show(Request $request, Design $design): JsonResponse
    {
        return response()->json($this->editorProps($request, $design));
    }

    public function edit(Request $request, Design $design): Response
    {
        $props = $this->editorProps($request, $design);

        // Lectores y comentaristas revisan el diseño sin el editor: no pueden modificar nada, y el servidor lo impide igualmente.
        return Inertia::render($props['access']['can_edit'] ? 'Studio/Editor' : 'Studio/Review', $props);
    }

    public function sharedAsset(Request $request, Design $design, int $media): StreamedResponse
    {
        $role = $this->collab->role($request->user(), $design);
        $owner = $this->collab->authorize($request->user(), $design, 'view');

        return $this->shared->asset($owner, $design, $role, $media, $request->header('Range'));
    }

    public function sharedFont(Request $request, Design $design, int $font): StreamedResponse
    {
        $role = $this->collab->role($request->user(), $design);
        $owner = $this->collab->authorize($request->user(), $design, 'view');

        return $this->shared->font($owner, $design, $role, $font);
    }

    public function update(Request $request, Design $design): JsonResponse|RedirectResponse
    {
        $design = $this->studio->update($request->user(), $design, $request->all());

        return $request->expectsJson() ? response()->json(['design' => $this->studio->present($request->user(), $design)])
            : redirect()->route('studio.designs.edit', $design)->with('success', 'Diseño guardado.');
    }

    public function destroy(Request $request, Design $design): JsonResponse|RedirectResponse
    {
        $this->studio->archive($request->user(), $design);

        return $request->expectsJson() ? response()->json(['archived' => true]) : redirect()->route('studio.index', ['brand_id' => $design->brand_id]);
    }

    public function duplicate(Request $request, Design $design): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255']]);
        $copy = $this->studio->duplicate($request->user(), $design, $data['name'] ?? null);

        return $request->expectsJson() ? response()->json(['design' => $this->studio->present($request->user(), $copy)], 201)
            : redirect()->route('studio.designs.edit', $copy);
    }

    public function restore(Request $request, Design $design): JsonResponse|RedirectResponse
    {
        $restored = $this->studio->restore($request->user(), $design);

        return $request->expectsJson() ? response()->json(['design' => $this->studio->present($request->user(), $restored)])
            : redirect()->route('studio.index', ['brand_id' => $design->brand_id]);
    }

    public function automation(Request $request, Design $design, string $operation): JsonResponse
    {
        return response()->json(app(StudioAutomation::class)->perform($request->user(), $design, $operation, $request->all()),
            in_array($operation, ['resize', 'apply-kit'], true) ? 200 : 201);
    }

    public function kit(Request $request, Brand $brand): JsonResponse
    {
        return response()->json(['kit' => $this->studio->kit($request->user(), $brand)]);
    }

    public function updateKit(Request $request, Brand $brand): JsonResponse
    {
        return response()->json(['kit' => $this->studio->updateKit($request->user(), $brand, $request->all())]);
    }

    public function assets(Request $request, Brand $brand): JsonResponse
    {
        return response()->json(['assets' => $this->assets->list($request->user(), $brand)->map(fn ($asset) => $this->assets->present($brand, $asset))->all()]);
    }

    public function attachAsset(Request $request, Brand $brand): JsonResponse
    {
        $data = $request->validate(['media_id' => ['required', 'integer', 'min:1']]);
        $asset = $this->assets->attach($request->user(), $brand, (int) $data['media_id']);

        return response()->json(['asset' => $this->assets->present($brand, $asset)], 201);
    }

    public function assetFile(Request $request, Brand $brand, int $media): StreamedResponse
    {
        return $this->assets->file($request->user(), $brand, $media, $request->header('Range'));
    }

    public function folders(Request $request, Brand $brand): JsonResponse
    {
        return response()->json(['folders' => $this->studio->folders($request->user(), $brand)]);
    }

    public function storeFolder(Request $request, Brand $brand): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        return response()->json(['folder' => $this->studio->createFolder($request->user(), $brand, $data['name'])], 201);
    }

    public function destroyFolder(Request $request, DesignFolder $folder): JsonResponse
    {
        $this->studio->deleteFolder($request->user(), $folder);

        return response()->json(['deleted' => true]);
    }

    public function exports(Request $request, Design $design): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $history = $this->exports->paginated($request->user(), $design, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 50));

        return response()->json(['exports' => $this->exports->presentMany($request->user(), $history->getCollection()), 'pagination' => $this->exports->pageMetadata($history)]);
    }

    public function registerExport(Request $request, Design $design): JsonResponse
    {
        app(StudioFrames::class)->assertJsonList($request);
        $export = $this->exports->register($request->user(), $design, $request->all());

        return response()->json(['export' => $this->exports->present($request->user(), $export)], 201);
    }

    public function showExport(Request $request, DesignExport $export): JsonResponse
    {
        return response()->json(['export' => $this->exports->present($request->user(), $export)]);
    }

    public function renderExport(Request $request, Design $design): JsonResponse
    {
        app(StudioFrames::class)->assertJsonList($request);
        $export = app(StudioRenderer::class)->export($request->user(), $design, $request->all());

        return response()->json(['export' => $this->exports->present($request->user(), $export)], 202);
    }

    public function cancelExport(Request $request, DesignExport $export): JsonResponse
    {
        $current = app(StudioAvControl::class)->cancel($request->user(), $export);

        return response()->json(['export' => $this->exports->present($request->user(), $current)]);
    }

    public function retryExport(Request $request, DesignExport $export): JsonResponse
    {
        $current = app(StudioAvControl::class)->retry($request->user(), $export);

        return response()->json(['export' => $this->exports->present($request->user(), $current)], 202);
    }

    public function createPost(Request $request, DesignExport $export): JsonResponse|RedirectResponse
    {
        $post = $this->exports->createPost($request->user(), $export, $request->all());

        return $request->expectsJson() ? response()->json(['post' => ['id' => $post->id, 'status' => $post->status->value, 'edit_url' => route('posts.edit', $post, absolute: false)]], 201)
            : redirect()->route('posts.edit', $post);
    }

    private function brandProps(Request $request, Brand $brand, ?User $user = null): array
    {
        $user ??= $request->user();
        $this->assets->assertBrand($user, $brand);
        $kit = $this->studio->kit($user, $brand);
        $fonts = app(StudioFonts::class);
        $kitFonts = $fonts->many($user, $brand, array_values(array_filter($kit['font_ids'] ?? [])))
            ->map(fn ($font) => $fonts->present($font))->values()->all();

        return ['brand' => $brand->only(['id', 'name', 'timezone']), 'kit' => $kit,
            'assets' => $this->assets->list($user, $brand)->map(fn ($asset) => $this->assets->present($brand, $asset))->all(),
            'folders' => $this->studio->folders($user, $brand),
            'fonts' => collect(array_merge($fonts->listing($user, $brand)['fonts'], $kitFonts))->unique('id')->values()->all()];
    }

    private function editorProps(Request $request, Design $design): array
    {
        $data = $request->validate(['exports_page' => ['nullable', 'integer', 'min:1']]);
        $actor = $request->user();
        $role = $this->collab->role($actor, $design);
        $user = $this->collab->authorize($actor, $design, 'view');
        $design = $this->studio->owned($user, $design);
        $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($design->brand_id);
        $history = $this->exports->paginated($user, $design, (int) ($data['exports_page'] ?? 1));

        $props = $this->brandProps($request, $brand, $user);
        $fonts = app(StudioFonts::class);
        $ids = array_values(array_filter($this->studio->kit($user, $brand)['font_ids'] ?? []));
        $needed = array_merge($fonts->neededFor($user, $brand, $design->document), $fonts->many($user, $brand, $ids)->map(fn ($font) => $fonts->present($font))->values()->all());
        $props['fonts'] = collect(array_merge($props['fonts'], $needed))->unique('id')->values()->all();
        $presented = $this->studio->present($actor, $design);
        $access = $this->collab->summary($role, $design);

        if ($role !== 'owner') {
            $routes = ['asset' => fn (int $id) => route('studio.shared.asset', ['design' => $design->id, 'media' => $id], false), 'font' => fn (int $id) => route('studio.shared.font', ['design' => $design->id, 'font' => $id], false)];
            $rewritten = $this->shared->rewrite($design, $role, $props['assets'], $props['fonts'], $routes);
            $props['assets'] = $rewritten['assets'];
            $props['fonts'] = $role === 'editor' ? $rewritten['fonts'] : array_values(array_filter($rewritten['fonts'], fn (array $font) => in_array($font['id'], StudioFonts::idsFromDocument($design->document), true)));
            $presented['fonts'] = $this->shared->rewrite($design, $role, [], $presented['fonts'], $routes)['fonts'];
            $props['folders'] = [];
            // Lectores y comentaristas no necesitan la paleta ni las tipografías del kit para ver el diseño.
            $props['kit'] = ['palette' => $role === 'editor' ? $props['kit']['palette'] : [], 'fonts' => $role === 'editor' ? $props['kit']['fonts'] : [], 'font_ids' => $role === 'editor' ? ($props['kit']['font_ids'] ?? []) : [], 'logos' => [], 'guidelines' => '', 'voice' => ''];
            $history = null;
        }

        return $props + ['design' => $presented, 'access' => $access, 'ai_configured' => $role === 'owner' && app(AiProviders::class)->anyConfigured(),
            'exports' => $history ? $this->exports->presentMany($user, $history->getCollection()) : [],
            'exports_pagination' => $history ? $this->exports->pageMetadata($history) : ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0, 'has_more' => false]];
    }
}
