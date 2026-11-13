<?php

namespace App\Mcp\Tools;

use App\Connectors\Network;
use App\Mcp\Abilities;
use App\Models\Competitor;
use App\Models\User;
use App\Services\CompetitorService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_competitor')]
#[Description('Alta y baja de competidores de una marca (la comparativa la da compare_competitors). action: add (brand o brand_id, username publico de la cuenta, network instagram por defecto, bluesky o youtube; consulta el perfil publico en la red y respeta el maximo de competidores por marca) o remove (competitor_id, el id que devuelve compare_competitors: deja de seguirlo y BORRA su historico, es IRREVERSIBLE desde aqui: exige confirm: true y la confirmacion humana explicita antes de llamarla). Ambas requieren competitors:write (posts:write no basta).')]
class ManageCompetitor extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::COMPETITORS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'competitor';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['add', 'remove'])->required(),
            ...$this->brandSchema($schema, 'add: marca'),
            'username' => $schema->string()->description('add: @usuario, enlace del perfil o identificador de la cuenta publica'),
            'network' => $schema->string()->enum(array_map(fn ($n) => $n->value, app(CompetitorService::class)->networks()))->description('add: red del competidor (por defecto instagram)'),
            'competitor_id' => $schema->integer()->description('remove: id del competidor'),
            'confirm' => $schema->boolean()->description('remove: debe ser true; la baja borra el historico del competidor'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $competitors = app(CompetitorService::class);
        $data = $request->validate([
            'action' => ['required', 'in:add,remove'],
            'network' => ['nullable', 'in:'.implode(',', array_map(fn (Network $n) => $n->value, $competitors->networks()))],
        ]);

        if ($data['action'] === 'remove') {
            if ($request->get('confirm') !== true) {
                throw new InvalidArgumentException('La baja de un competidor borra su historico y no se puede deshacer: confirm debe ser true tras la confirmacion humana. No se ha borrado nada.');
            }
            /** @var Competitor $competitor */
            $competitor = $this->owned(Competitor::class, $user, $request->get('competitor_id'), 'Competidor', masculine: true);
            $competitors->remove($competitor);

            return ['id' => $competitor->id, 'removed' => true];
        }

        $username = $request->validate(['username' => ['required', 'string', 'max:300']])['username'];
        $competitor = $competitors->add($user, $this->brandFrom($request, $user), $username, Network::tryFrom((string) ($data['network'] ?? '')) ?? Network::Instagram);

        return ['id' => $competitor->id, 'brand_id' => $competitor->brand_id, 'network' => $competitor->network->value, 'username' => $competitor->username, 'name' => $competitor->name];
    }
}
