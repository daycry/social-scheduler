<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioService;
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
        $design = $this->owned(Design::class, $user, $data['design_id'], 'Diseño');
        $brand = $this->owned(Brand::class, $user, $design->brand_id, 'Marca');
        $studio = app(StudioService::class);
        $assets = app(StudioAssets::class);
        $exports = app(StudioExports::class);
        $history = $exports->paginated($user, $design, (int) ($data['exports_page'] ?? 1));

        return $studio->present($user, $design) + ['kit' => $studio->kit($user, $brand),
            'assets' => $assets->list($user, $brand)->map(fn ($asset) => $assets->present($brand, $asset)
                + ['file_url' => route('api.studio.assets.file', ['brand' => $brand, 'media' => $asset->id], false)])->all(),
            'exports' => $exports->presentMany($user, $history->getCollection()), 'exports_pagination' => $exports->pageMetadata($history)];
    }
}
