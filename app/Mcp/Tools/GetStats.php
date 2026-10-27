<?php

namespace App\Mcp\Tools;

use App\Analytics\Period;
use App\Connectors\Network;
use App\Mcp\Abilities;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_stats')]
#[Description('Estadisticas de una marca (resumen por red) o de una red concreta en un periodo, con comparacion opcional. null = no disponible (nunca 0).')]
#[IsReadOnly]
class GetStats extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::STATS_READ;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->brandSchema($schema, 'Marca (obligatoria)'),
            'network' => $schema->string()->description(Network::listing().'; sin valor = resumen de la marca'),
            'from' => $schema->string()->description('Inicio YYYY-MM-DD (por defecto, 30 dias hasta ayer)'),
            'to' => $schema->string()->description('Fin YYYY-MM-DD'),
            'compare' => $schema->string()->enum(Period::COMPARE_MODES)->description('none (por defecto), previous o year'),
            'include_series' => $schema->boolean()->description('Incluir las series diarias (por defecto no)'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $brand = $this->brandFrom($request, $user);
        $period = Period::make($request->get('from'), $request->get('to'), $brand->timezone);
        $mode = (string) ($request->get('compare') ?: 'none');

        if (! in_array($mode, Period::COMPARE_MODES, true)) {
            throw new InvalidArgumentException('Modo de comparacion no valido.');
        }

        $compare = $period->comparison($mode);
        $analytics = app(AnalyticsService::class);
        $withSeries = (bool) $request->get('include_series');

        if ($request->get('network')) {
            $network = Network::tryFrom((string) $request->get('network')) ?? throw new InvalidArgumentException('Red no valida.');
            $data = $analytics->network($user, $brand, $network, $period, $compare);
            $data['metrics'] = array_map(fn (array $m) => $withSeries ? $m : array_diff_key($m, ['series' => 1]), $data['metrics']);

            return ['brand_id' => $brand->id, 'brand_name' => $brand->name] + $data;
        }

        $data = $analytics->summary($user, $brand, $period, $compare);
        $data['cards'] = array_map(fn (array $c) => $withSeries ? $c : array_diff_key($c, ['series' => 1]), $data['cards']);

        return ['brand_id' => $brand->id, 'brand_name' => $brand->name] + $data;
    }
}
