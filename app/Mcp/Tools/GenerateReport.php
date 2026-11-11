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
use InvalidArgumentException;
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
        $period = Period::make($request->get('from'), $request->get('to'), $brand->timezone);

        $compare = (string) ($request->get('compare') ?: 'none');
        $period->comparison($compare); // rango de comparacion invalido: error de validacion, no informe fallido

        if ($request->get('template_id') !== null && $request->get('blocks') !== null) {
            throw new InvalidArgumentException('Usa template_id o blocks, no ambos.');
        }

        $blocks = null;

        if ($request->get('template_id') !== null) {
            /** @var ReportTemplate $template */
            $template = $this->owned(ReportTemplate::class, $user, $request->get('template_id'), 'Plantilla');

            if ((int) $template->brand_id !== (int) $brand->id) {
                throw new InvalidArgumentException('La plantilla no es de esta marca.');
            }

            $blocks = ReportBlocks::normalize($template->blocks);
        } elseif ($request->get('blocks') !== null) {
            $blocks = ReportBlocks::normalize($request->get('blocks'));
        }

        // Limite de informes en curso atomico (transaccion + bloqueo del usuario) dentro del servicio.
        [$report] = app(ReportService::class)->enqueue($user, $brand, [(string) $request->get('format')], $period, $compare, $blocks);

        return [
            'id' => $report->id, 'status' => $report->status, 'brand_id' => $brand->id, 'brand_name' => $brand->name, 'format' => $report->format,
            'from' => $period->toArray()['from'], 'to' => $period->toArray()['to'],
            'blocks' => $report->blocks === null ? null : array_column($report->blocks, 'type'),
        ];
    }
}
