<?php

namespace App\Mcp\Tools;

use App\Connectors\Network;
use App\Mcp\Abilities;
use App\Models\User;
use App\Services\BestTimesService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_best_times')]
#[Description('Mejores horas para publicar en una red: engagement medio por dia de la semana y hora (zona de la marca) a partir de las publicaciones propias. available=false si hay pocos datos.')]
#[IsReadOnly]
class GetBestTimes extends SchedulerTool
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
            'network' => $schema->string()->enum(Network::availableValues())->description(Network::listing())->required(),
            'days' => $schema->integer()->description('Ventana en dias (7-365; por defecto 90)'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $brand = $this->brandFrom($request, $user);
        $network = Network::tryFrom((string) $request->get('network')) ?? throw new InvalidArgumentException('Red no valida.');
        $days = $request->get('days');

        return app(BestTimesService::class)->heatmap($user, $brand, $network, is_numeric($days) ? (int) $days : null);
    }
}
