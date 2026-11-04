<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('attach_design_asset')]
#[Description('Registra media propia ya lista en una marca para usar su id como asset_id de imágenes/vídeos en documentos. Flujo import_media_from_url -> list_media hasta listo -> attach_design_asset -> create_design/update_design. No acepta URLs ni archivos ajenos. Requiere designs:write.')]
class AttachDesignAsset extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::DESIGNS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->brandSchema($schema) + ['media_id' => $schema->integer()->min(1)->required()];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['media_id' => ['required', 'integer', 'min:1']]);
        $brand = $this->brandFrom($request, $user);
        $assets = app(StudioAssets::class);
        $asset = $assets->attach($user, $brand, (int) $data['media_id']);

        return $assets->present($brand, $asset) + ['file_url' => route('api.studio.assets.file', ['brand' => $brand, 'media' => $asset->id], false)];
    }
}
