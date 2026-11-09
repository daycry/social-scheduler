<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioFrames;
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
            'format' => $schema->string()->enum(['png', 'jpeg', 'webp', 'svg', 'pdf', 'mp4', 'gif'])->required(),
            'pages' => $schema->array()->items($schema->string())->required(), 'scale' => $schema->number()->min(0.1)->max(4),
            'frame_times' => $schema->array()->items($schema->object(['page_id' => $schema->string()->required(), 'time' => $schema->number()->min(0)->max(3600)->required()]))
                ->description('Solo documento v2: segundos locales por página seleccionada, sin repetir; time menor que su duración. Páginas omitidas usan0.'),
            'transparent' => $schema->boolean(), 'quality' => $schema->number()->min(0.1)->max(1)];
    }

    protected function run(Request $request, User $user): array
    {
        app(StudioFrames::class)->assertJsonList(request(), 'params.arguments.frame_times');
        $data = $request->validate(['design_id' => ['required', 'integer', 'min:1']]);
        $export = app(StudioRenderer::class)->export($user, $this->owned(Design::class, $user, $data['design_id'], 'Diseño'), $request->all());

        return app(StudioExports::class)->present($user, $export);
    }
}
