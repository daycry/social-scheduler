<?php

namespace App\Mcp\Tools;

use App\Analytics\Period;
use App\Jobs\GenerateReport as GenerateReportJob;
use App\Mcp\Abilities;
use App\Models\Brand;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('generate_report')]
#[Description('Encola un informe PDF o CSV de una marca y un rango de fechas y devuelve su id. Consulta el estado con get_report.')]
class GenerateReport extends SchedulerTool
{
    private const MAX_ACTIVE = 5;

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

        $active = Report::withoutUserScope()->where('user_id', $user->id)
            ->whereIn('status', [Report::STATUS_PENDING, Report::STATUS_PROCESSING])->count();

        if ($active >= self::MAX_ACTIVE) {
            throw new InvalidArgumentException('Ya hay varios informes en curso. Espera a que terminen.');
        }

        $report = app(ReportService::class)->create($user, $brand, (string) $request->get('format'), $period, (string) ($request->get('compare') ?: 'none'));
        GenerateReportJob::dispatch($report->id);

        return [
            'id' => $report->id, 'status' => $report->status, 'brand_id' => $brand->id, 'format' => $report->format,
            'from' => $period->toArray()['from'], 'to' => $period->toArray()['to'],
        ];
    }
}
