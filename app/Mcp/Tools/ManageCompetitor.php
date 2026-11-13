<?php

namespace App\Mcp\Tools;

use App\Connectors\Network;
use App\Mcp\Abilities;
use App\Models\Competitor;
use App\Models\User;
use App\Services\CompetitorService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_competitor')]
#[Description('Alta y baja de competidores de una marca (la comparativa la da compare_competitors). action: add (brand o brand_id, username publico de la cuenta, network instagram por defecto, bluesky o youtube; consulta el perfil publico en la red y respeta el maximo de competidores por marca) o remove (competitor_id, el id que devuelve compare_competitors: deja de seguirlo y borra su historico). Ambas requieren posts:write.')]
class ManageCompetitor extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_WRITE;
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
            /** @var Competitor $competitor */
            $competitor = $this->owned(Competitor::class, $user, $request->get('competitor_id'), 'Cuenta de competidor');
            $competitors->remove($competitor);

            return ['id' => $competitor->id, 'removed' => true];
        }

        $username = $request->validate(['username' => ['required', 'string', 'max:300']])['username'];
        $competitor = $competitors->add($user, $this->brandFrom($request, $user), $username, Network::tryFrom((string) ($data['network'] ?? '')) ?? Network::Instagram);

        return ['id' => $competitor->id, 'brand_id' => $competitor->brand_id, 'network' => $competitor->network->value, 'username' => $competitor->username, 'name' => $competitor->name];
    }
}
