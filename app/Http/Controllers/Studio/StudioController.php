<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Studio\AttachAssetRequest;
use App\Http\Requests\Studio\DuplicateDesignRequest;
use App\Http\Requests\Studio\EditorRequest;
use App\Http\Requests\Studio\FolderNameRequest;
use App\Http\Requests\Studio\ListDesignsRequest;
use App\Http\Requests\Studio\ListExportsRequest;
use App\Http\Requests\Studio\MoveDesignRequest;
use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignFolder;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioAutomation;
use App\Services\Studio\StudioAvControl;
use App\Services\Studio\StudioCollab;
use App\Services\Studio\StudioEditor;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioFrames;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use App\Services\Studio\StudioSharedFiles;
use App\Services\Studio\StudioThumbnails;
use App\Support\CurrentBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudioController extends Controller
{
    public function __construct(private readonly StudioService $studio, private readonly StudioAssets $assets, private readonly StudioExports $exports, private readonly StudioCollab $collab, private readonly StudioSharedFiles $shared, private readonly StudioEditor $editor) {}

    public function index(ListDesignsRequest $request): Response|JsonResponse
    {
        $brand = CurrentBrand::resolve($request);
        if ($brand === null) {
            return Inertia::render('Studio/Index', ['brand' => null, 'designs' => ['designs' => [], 'has_more' => false, 'next_cursor' => null], 'kit' => null, 'assets' => [], 'folders' => [], 'filters' => [], 'shared' => $this->collab->sharedWith($request->user())]);
        }

        return $this->indexBrand($request, $brand);
    }

    public function indexBrand(ListDesignsRequest $request, Brand $brand): Response|JsonResponse
    {
        $filters = $request->validated();
        $designs = $this->studio->designs($request->user(), $brand, $filters);
        $designs['designs'] = app(StudioThumbnails::class)->decorate($request->user(), $designs['designs']);
        $props = $this->editor->brandProps($request->user(), $brand) + ['designs' => $designs, 'filters' => $filters, 'shared' => $this->collab->sharedWith($request->user())];

        return $request->expectsJson() ? response()->json($props) : Inertia::render('Studio/Index', $props);
    }

    public function store(Request $request, Brand $brand): JsonResponse|RedirectResponse
    {
        $design = $this->studio->create($request->user(), $brand, $request->all());

        return $request->expectsJson() ? response()->json(['design' => $this->studio->present($request->user(), $design)], 201)
            : redirect()->route('studio.designs.edit', $design)->with('success', 'Diseño creado.');
    }

    public function show(EditorRequest $request, Design $design): JsonResponse
    {
        return response()->json($this->editor->web($request->user(), $design, $request->exportsPage()));
    }

    public function edit(EditorRequest $request, Design $design): Response
    {
        $props = $this->editor->web($request->user(), $design, $request->exportsPage());

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

    public function duplicate(DuplicateDesignRequest $request, Design $design): JsonResponse|RedirectResponse
    {
        $copy = $this->studio->duplicate($request->user(), $design, $request->validated('name'));

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

    public function attachAsset(AttachAssetRequest $request, Brand $brand): JsonResponse
    {
        $asset = $this->assets->attach($request->user(), $brand, (int) $request->validated('media_id'));

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

    public function storeFolder(FolderNameRequest $request, Brand $brand): JsonResponse
    {
        return response()->json(['folder' => $this->studio->createFolder($request->user(), $brand, $request->validated('name'))], 201);
    }

    public function updateFolder(FolderNameRequest $request, DesignFolder $folder): JsonResponse
    {
        return response()->json(['folder' => $this->studio->renameFolder($request->user(), $folder, $request->validated('name'))]);
    }

    public function destroyFolder(Request $request, DesignFolder $folder): JsonResponse
    {
        return response()->json(['deleted' => true, 'designs_moved' => $this->studio->deleteFolder($request->user(), $folder)]);
    }

    public function moveDesign(MoveDesignRequest $request, Design $design): JsonResponse
    {
        $moved = $this->studio->moveDesign($request->user(), $design, $request->validated('folder_id'));

        return response()->json(['design' => ['id' => $moved->id, 'folder_id' => $moved->folder_id]]);
    }

    public function exports(ListExportsRequest $request, Design $design): JsonResponse
    {
        $data = $request->validated();
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
}
