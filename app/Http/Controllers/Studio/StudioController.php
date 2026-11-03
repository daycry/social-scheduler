<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignFolder;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioService;
use App\Support\CurrentBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudioController extends Controller
{
    public function __construct(private readonly StudioService $studio, private readonly StudioAssets $assets, private readonly StudioExports $exports) {}

    public function index(Request $request): Response|JsonResponse
    {
        $brand = CurrentBrand::resolve($request);
        if ($brand === null) {
            return Inertia::render('Studio/Index', ['brand' => null, 'designs' => ['data' => [], 'total' => 0, 'links' => []], 'kit' => null, 'assets' => [], 'folders' => [], 'filters' => []]);
        }

        return $this->indexBrand($request, $brand);
    }

    public function indexBrand(Request $request, Brand $brand): Response|JsonResponse
    {
        $filters = $request->validate(['query' => ['nullable', 'string', 'max:120'], 'folder_id' => ['nullable', 'integer', 'min:1'],
            'templates' => ['nullable', 'boolean'], 'archived' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']]);
        $designs = $this->studio->designs($request->user(), $brand, $filters)->toArray();
        $props = $this->brandProps($request, $brand) + ['designs' => $designs, 'filters' => $filters];

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
        return Inertia::render('Studio/Editor', $this->editorProps($request, $design));
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
        $export = $this->exports->register($request->user(), $design, $request->all());

        return response()->json(['export' => $this->exports->present($request->user(), $export)], 201);
    }

    public function showExport(Request $request, DesignExport $export): JsonResponse
    {
        return response()->json(['export' => $this->exports->present($request->user(), $export)]);
    }

    public function createPost(Request $request, DesignExport $export): JsonResponse|RedirectResponse
    {
        $post = $this->exports->createPost($request->user(), $export, $request->all());

        return $request->expectsJson() ? response()->json(['post' => ['id' => $post->id, 'status' => $post->status->value, 'edit_url' => route('posts.edit', $post, absolute: false)]], 201)
            : redirect()->route('posts.edit', $post);
    }

    private function brandProps(Request $request, Brand $brand): array
    {
        $this->assets->assertBrand($request->user(), $brand);

        return ['brand' => $brand->only(['id', 'name', 'timezone']), 'kit' => $this->studio->kit($request->user(), $brand),
            'assets' => $this->assets->list($request->user(), $brand)->map(fn ($asset) => $this->assets->present($brand, $asset))->all(),
            'folders' => $this->studio->folders($request->user(), $brand)];
    }

    private function editorProps(Request $request, Design $design): array
    {
        $data = $request->validate(['exports_page' => ['nullable', 'integer', 'min:1']]);
        $design = $this->studio->owned($request->user(), $design);
        $brand = Brand::withoutUserScope()->where('user_id', $request->user()->id)->findOrFail($design->brand_id);
        $history = $this->exports->paginated($request->user(), $design, (int) ($data['exports_page'] ?? 1));

        return $this->brandProps($request, $brand) + ['design' => $this->studio->present($request->user(), $design),
            'exports' => $this->exports->presentMany($request->user(), $history->getCollection()), 'exports_pagination' => $this->exports->pageMetadata($history)];
    }
}
