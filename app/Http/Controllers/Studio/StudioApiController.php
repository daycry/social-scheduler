<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignFolder;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudioApiController extends Controller
{
    public function __construct(private readonly StudioService $studio, private readonly StudioAssets $assets, private readonly StudioExports $exports) {}

    public function index(Request $request, Brand $brand): JsonResponse
    {
        foreach (['templates', 'archived'] as $key) {
            if (in_array($request->query($key), ['true', 'false'], true)) {
                $request->merge([$key => $request->query($key) === 'true']);
            }
        }
        $filters = $request->validate(['query' => ['nullable', 'string', 'max:120'], 'folder_id' => ['nullable', 'integer', 'min:1'],
            'templates' => ['nullable', 'boolean'], 'archived' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->studio->designs($request->user(), $brand, $filters));
    }

    public function store(Request $request, Brand $brand): JsonResponse
    {
        return response()->json($this->studio->present($request->user(), $this->studio->create($request->user(), $brand, $request->all())), 201);
    }

    public function show(Request $request, Design $design): JsonResponse
    {
        $data = $request->validate(['exports_page' => ['nullable', 'integer', 'min:1']]);
        $design = $this->studio->owned($request->user(), $design);
        $brand = Brand::withoutUserScope()->where('user_id', $request->user()->id)->findOrFail($design->brand_id);
        $history = $this->exports->paginated($request->user(), $design, (int) ($data['exports_page'] ?? 1));

        return response()->json($this->studio->present($request->user(), $design) + ['kit' => $this->studio->kit($request->user(), $brand),
            'assets' => $this->assetData($request, $brand), 'exports' => $this->exports->presentMany($request->user(), $history->getCollection()),
            'exports_pagination' => $this->exports->pageMetadata($history)]);
    }

    public function update(Request $request, Design $design): JsonResponse
    {
        return response()->json($this->studio->present($request->user(), $this->studio->update($request->user(), $design, $request->all())));
    }

    public function destroy(Request $request, Design $design): JsonResponse
    {
        $this->studio->archive($request->user(), $design);

        return response()->json(['archived' => true]);
    }

    public function duplicate(Request $request, Design $design): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->studio->present($request->user(), $this->studio->duplicate($request->user(), $design, $data['name'] ?? null)), 201);
    }

    public function restore(Request $request, Design $design): JsonResponse
    {
        return response()->json($this->studio->present($request->user(), $this->studio->restore($request->user(), $design)));
    }

    public function kit(Request $request, Brand $brand): JsonResponse
    {
        return response()->json($this->studio->kit($request->user(), $brand));
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

    public function updateKit(Request $request, Brand $brand): JsonResponse
    {
        return response()->json($this->studio->updateKit($request->user(), $brand, $request->all()));
    }

    public function assets(Request $request, Brand $brand): JsonResponse
    {
        return response()->json(['assets' => $this->assetData($request, $brand)]);
    }

    public function attachAsset(Request $request, Brand $brand): JsonResponse
    {
        $data = $request->validate(['media_id' => ['required', 'integer', 'min:1']]);
        $asset = $this->assets->attach($request->user(), $brand, (int) $data['media_id']);

        return response()->json(['asset' => $this->assets->present($brand, $asset) + ['file_url' => route('api.studio.assets.file', ['brand' => $brand, 'media' => $asset->id], false)]], 201);
    }

    public function assetFile(Request $request, Brand $brand, int $media): StreamedResponse
    {
        return $this->assets->file($request->user(), $brand, $media, $request->header('Range'));
    }

    public function exports(Request $request, Design $design): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $history = $this->exports->paginated($request->user(), $design, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 50));

        return response()->json(['exports' => $this->exports->presentMany($request->user(), $history->getCollection()), 'pagination' => $this->exports->pageMetadata($history)]);
    }

    public function export(Request $request, Design $design): JsonResponse
    {
        $export = app(StudioRenderer::class)->export($request->user(), $design, $request->all());

        return response()->json($this->exports->present($request->user(), $export), 202);
    }

    public function showExport(Request $request, DesignExport $export): JsonResponse
    {
        return response()->json($this->exports->present($request->user(), $export));
    }

    private function assetData(Request $request, Brand $brand): array
    {
        return $this->assets->list($request->user(), $brand)->map(fn ($asset) => $this->assets->present($brand, $asset)
            + ['file_url' => route('api.studio.assets.file', ['brand' => $brand, 'media' => $asset->id], false)])->all();
    }
}
