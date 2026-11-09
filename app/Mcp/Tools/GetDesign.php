<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Brand;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioCollab;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioService;
use App\Services\Studio\StudioSharedFiles;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_design')]
#[Description('Lee documento nativo completo, revision, recursos, kit y exportaciones con estado real. exports contiene 50 por página; exports_page opcional (desde 1), exports_pagination indica total/last_page. Para polling de export_design vuelve a consultar aquí. Recursos file_url requieren Bearer; download_url de artefactos es temporal. Requiere designs:read.')]
#[IsReadOnly]
class GetDesign extends SchedulerTool
{
    use SharedDesign;

    protected function ability(): string
    {
        return Abilities::DESIGNS_READ;
    }

    protected function resource(): ?string
    {
        return 'design';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['design_id' => $schema->integer()->required(), 'exports_page' => $schema->integer()->min(1)->description('Página del historial, 50 exportaciones por página')];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['design_id' => ['required', 'integer', 'min:1'], 'exports_page' => ['nullable', 'integer', 'min:1']]);
        $design = $this->accessibleDesign($user, $data['design_id']);
        $collab = app(StudioCollab::class);
        $role = $collab->role($user, $design);
        $owner = $collab->authorize($user, $design, 'view');
        $brand = $this->owned(Brand::class, $owner, $design->brand_id, 'Marca');
        $studio = app(StudioService::class);
        $assets = app(StudioAssets::class);
        $exports = app(StudioExports::class);
        $history = $exports->paginated($owner, $design, (int) ($data['exports_page'] ?? 1));
        $presented = $studio->present($user, $design, true);
        $files = $assets->list($owner, $brand)->map(fn ($asset) => $assets->present($brand, $asset)
            + ['file_url' => route('api.studio.assets.file', ['brand' => $brand, 'media' => $asset->id], false)])->all();
        $kit = $studio->kit($owner, $brand);
        $list = $exports->presentMany($owner, $history->getCollection());
        $pagination = $exports->pageMetadata($history);
        if ($role !== 'owner') {
            $routes = ['asset' => fn (int $id) => route('api.studio.shared.asset', ['design' => $design->id, 'media' => $id], false), 'font' => fn (int $id) => route('api.studio.shared.font', ['design' => $design->id, 'font' => $id], false)];
            $shared = app(StudioSharedFiles::class);
            $files = $shared->rewrite($design, $role, $files, [], $routes, true)['assets'];
            $presented['fonts'] = $shared->rewrite($design, $role, [], $presented['fonts'], $routes, true)['fonts'];
            $kit = ['palette' => $kit['palette'], 'fonts' => $kit['fonts'], 'logos' => [], 'guidelines' => '', 'voice' => ''];
            $list = [];
            $pagination = ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0, 'has_more' => false];
        }

        return $presented + ['kit' => $kit, 'assets' => $files, 'exports' => $list, 'exports_pagination' => $pagination];
    }
}
