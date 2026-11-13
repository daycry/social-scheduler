<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignFolder;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioAutomation;
use App\Services\Studio\StudioAvControl;
use App\Services\Studio\StudioCollab;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioFrames;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use App\Services\Studio\StudioSharedFiles;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudioApiController extends Controller
{
    public function __construct(private readonly StudioService $studio, private readonly StudioAssets $assets, private readonly StudioExports $exports, private readonly StudioCollab $collab, private readonly StudioSharedFiles $shared) {}

    public function index(Request $request, Brand $brand): JsonResponse
    {
        foreach (['templates', 'archived'] as $key) {
            if (in_array($request->query($key), ['true', 'false'], true)) {
                $request->merge([$key => $request->query($key) === 'true']);
            }
        }
        $filters = $request->validate(['query' => ['nullable', 'string', 'max:120'], 'folder_id' => ['nullable', 'integer', 'min:1'],
            'templates' => ['nullable', 'boolean'], 'archived' => ['nullable', 'boolean']] + CursorPage::RULES);

        return response()->json($this->studio->designs($request->user(), $brand, $filters));
    }

    public function store(Request $request, Brand $brand): JsonResponse
    {
        return response()->json($this->studio->present($request->user(), $this->studio->create($request->user(), $brand, $request->all()), true), 201);
    }

    public function show(Request $request, Design $design): JsonResponse
    {
        $data = $request->validate(['exports_page' => ['nullable', 'integer', 'min:1']]);
        $role = $this->collab->role($request->user(), $design);
        $owner = $this->collab->authorize($request->user(), $design, 'view');
        $design = $this->studio->owned($owner, $design);
        $brand = Brand::withoutUserScope()->where('user_id', $owner->id)->findOrFail($design->brand_id);
        $history = $this->exports->paginated($owner, $design, (int) ($data['exports_page'] ?? 1));
        $presented = $this->studio->present($request->user(), $design, true);
        $assets = $this->assetData($request, $brand, $owner);
        $kit = $this->studio->kit($owner, $brand);
        $exports = $this->exports->presentMany($owner, $history->getCollection());
        $pagination = $this->exports->pageMetadata($history);
        if ($role !== 'owner') {
            $routes = ['asset' => fn (int $id) => route('api.studio.shared.asset', ['design' => $design->id, 'media' => $id], false), 'font' => fn (int $id) => route('api.studio.shared.font', ['design' => $design->id, 'font' => $id], false)];
            $assets = $this->shared->rewrite($design, $role, $assets, [], $routes, true)['assets'];
            $presented['fonts'] = $this->shared->rewrite($design, $role, [], $presented['fonts'], $routes, true)['fonts'];
            $kit = ['palette' => $role === 'editor' ? $kit['palette'] : [], 'fonts' => $role === 'editor' ? $kit['fonts'] : [], 'logos' => [], 'guidelines' => '', 'voice' => ''];
            $exports = [];
            $pagination = ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0, 'has_more' => false];
        }

        return response()->json($presented + ['kit' => $kit, 'assets' => $assets, 'exports' => $exports, 'exports_pagination' => $pagination]);
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

    public function update(Request $request, Design $design): JsonResponse
    {
        return response()->json($this->studio->present($request->user(), $this->studio->update($request->user(), $design, $request->all()), true));
    }

    public function destroy(Request $request, Design $design): JsonResponse
    {
        $this->studio->archive($request->user(), $design);

        return response()->json(['archived' => true]);
    }

    public function duplicate(Request $request, Design $design): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->studio->present($request->user(), $this->studio->duplicate($request->user(), $design, $data['name'] ?? null), true), 201);
    }

    public function restore(Request $request, Design $design): JsonResponse
    {
        return response()->json($this->studio->present($request->user(), $this->studio->restore($request->user(), $design), true));
    }

    public function automation(Request $request, Design $design, string $operation): JsonResponse
    {
        return response()->json(app(StudioAutomation::class)->perform($request->user(), $design, $operation, $request->all(), true),
            in_array($operation, ['resize', 'apply-kit'], true) ? 200 : 201);
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

    public function updateFolder(Request $request, DesignFolder $folder): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        return response()->json(['folder' => $this->studio->renameFolder($request->user(), $folder, $data['name'])]);
    }

    public function destroyFolder(Request $request, DesignFolder $folder): JsonResponse
    {
        return response()->json(['deleted' => true, 'designs_moved' => $this->studio->deleteFolder($request->user(), $folder)]);
    }

    public function moveDesign(Request $request, Design $design): JsonResponse
    {
        $data = $request->validate(['folder_id' => ['present', 'nullable', 'integer', 'min:1']]);
        $moved = $this->studio->moveDesign($request->user(), $design, $data['folder_id']);

        return response()->json(['design' => ['id' => $moved->id, 'folder_id' => $moved->folder_id]]);
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
        app(StudioFrames::class)->assertJsonList($request);
        $export = app(StudioRenderer::class)->export($request->user(), $design, $request->all());

        return response()->json($this->exports->present($request->user(), $export), 202);
    }

    public function showExport(Request $request, DesignExport $export): JsonResponse
    {
        return response()->json($this->exports->present($request->user(), $export));
    }

    public function cancelExport(Request $request, DesignExport $export): JsonResponse
    {
        $current = app(StudioAvControl::class)->cancel($request->user(), $export);

        return response()->json($this->exports->present($request->user(), $current));
    }

    public function retryExport(Request $request, DesignExport $export): JsonResponse
    {
        $current = app(StudioAvControl::class)->retry($request->user(), $export);

        return response()->json($this->exports->present($request->user(), $current), 202);
    }

    public function createPost(Request $request, DesignExport $export): JsonResponse
    {
        validator(['payload' => $request->all()], ['payload' => ['array:text,targets']])->validate();
        $post = $this->exports->createPost($request->user(), $export, $request->all());

        return response()->json(['post' => ['id' => $post->id, 'status' => $post->status->value, 'edit_url' => route('posts.edit', $post, absolute: false)]], 201);
    }

    private function assetData(Request $request, Brand $brand, ?User $user = null): array
    {
        return $this->assets->list($user ?? $request->user(), $brand)->map(fn ($asset) => $this->assets->present($brand, $asset)
            + ['file_url' => route('api.studio.assets.file', ['brand' => $brand, 'media' => $asset->id], false)])->all();
    }
}
