<?php

namespace App\Mcp\Tools;

use App\Analytics\Period;
use App\Mcp\Abilities;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Services\Reports\ReportBlocks;
use App\Services\ReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('generate_report')]
#[Description('Encola un informe PDF o CSV de una marca y un rango de fechas y devuelve su id. El PDF admite bloques (kpis, evolution, top_posts, competitors, ads, text) directos o los de una plantilla guardada (template_id). Consulta el estado con get_report.')]
class GenerateReport extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::REPORTS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'report';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->brandSchema($schema, 'Marca del informe (obligatoria)'),
            'format' => $schema->string()->enum(Report::FORMATS)->description('pdf o csv')->required(),
            'from' => $schema->string()->description('Inicio YYYY-MM-DD (por defecto, 30 dias hasta ayer)'),
            'to' => $schema->string()->description('Fin YYYY-MM-DD'),
            'compare' => $schema->string()->enum(Period::COMPARE_MODES)->description('none (por defecto), previous o year'),
            'template_id' => $schema->integer()->description('Plantilla de informe de ESA marca: usa sus bloques en el PDF (excluyente con blocks)'),
            'blocks' => $schema->array()->items($schema->object([
                'type' => $schema->string()->enum(ReportBlocks::TYPES)->required(),
                'title' => $schema->string()->description('Titulo opcional (80 caracteres)'),
                'text' => $schema->string()->description('Solo type=text: nota (2000 caracteres, texto plano)'),
            ]))->description('Bloques ordenados del PDF (maximo '.ReportBlocks::MAX_BLOCKS.')'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $brand = $this->brandFrom($request, $user);
        /** @var ReportTemplate|null $template */
        $template = $request->get('template_id') !== null ? $this->owned(ReportTemplate::class, $user, $request->get('template_id'), 'Plantilla') : null;

        return app(ReportService::class)->request($user, $brand, [
            'format' => $request->get('format'), 'from' => $request->get('from'), 'to' => $request->get('to'),
            'compare' => $request->get('compare'), 'blocks' => $request->get('blocks'),
        ], $template);
    }
}
