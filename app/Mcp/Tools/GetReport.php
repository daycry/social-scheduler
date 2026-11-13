<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_report')]
#[Description('Estado de un informe (pending, processing, ready, failed) y, si esta listo, URL firmada de descarga que NO exige sesion y caduca en unos minutos (llama de nuevo a get_report para renovarla).')]
#[IsReadOnly]
class GetReport extends SchedulerTool
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
        return ['report_id' => $schema->integer()->description('ID del informe')->required()];
    }

    protected function run(Request $request, User $user): array
    {
        /** @var Report $report */
        $report = $this->owned(Report::class, $user, $request->get('report_id'), 'Informe', masculine: true);

        return app(ReportService::class)->status($report, $this->brandNames($user)[$report->brand_id] ?? null);
    }
}
