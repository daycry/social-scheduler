<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioRenderer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('export_design')]
#[Description('Renderiza en servidor una revisión existente y páginas elegidas en orden. Devuelve trabajo con estado real; consulta get_design para polling y descarga temporal cuando esté ready. No inventa archivos ni publica. Requiere designs:write.')]
class ExportDesign extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::DESIGNS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'design';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['design_id' => $schema->integer()->required(), 'revision' => $schema->integer()->min(1)->required(),
            'format' => $schema->string()->enum(['png', 'jpeg', 'webp', 'svg', 'pdf'])->required(),
            'pages' => $schema->array()->items($schema->string())->required(), 'scale' => $schema->number()->min(0.1)->max(4),
            'transparent' => $schema->boolean(), 'quality' => $schema->number()->min(0.1)->max(1)];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['design_id' => ['required', 'integer', 'min:1']]);
        $export = app(StudioRenderer::class)->export($user, $this->owned(Design::class, $user, $data['design_id'], 'Diseño'), $request->all());

        return app(StudioExports::class)->present($user, $export);
    }
}
