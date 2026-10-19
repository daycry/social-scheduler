<?php

namespace App\Mcp\Tools;

use App\Analytics\Period;
use App\Mcp\Abilities;
use App\Models\Brand;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('generate_report')]
#[Description('Encola un informe PDF o CSV de una marca y un rango de fechas y devuelve su id. Consulta el estado con get_report.')]
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
            'brand_id' => $schema->integer()->description('ID de la marca')->required(),
            'format' => $schema->string()->enum(Report::FORMATS)->description('pdf o csv')->required(),
            'from' => $schema->string()->description('Inicio YYYY-MM-DD (por defecto, 30 dias hasta ayer)'),
            'to' => $schema->string()->description('Fin YYYY-MM-DD'),
            'compare' => $schema->string()->enum(Period::COMPARE_MODES)->description('none (por defecto), previous o year'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $brand = $this->owned(Brand::class, $user, $request->get('brand_id'), 'Marca');
        $period = Period::make($request->get('from'), $request->get('to'));

        $compare = (string) ($request->get('compare') ?: 'none');
        $period->comparison($compare); // rango de comparacion invalido: error de validacion, no informe fallido

        // Limite de informes en curso atomico (transaccion + bloqueo del usuario) dentro del servicio.
        [$report] = app(ReportService::class)->enqueue($user, $brand, [(string) $request->get('format')], $period, $compare);

        return [
            'id' => $report->id, 'status' => $report->status, 'brand_id' => $brand->id, 'format' => $report->format,
            'from' => $period->toArray()['from'], 'to' => $period->toArray()['to'],
        ];
    }
}
