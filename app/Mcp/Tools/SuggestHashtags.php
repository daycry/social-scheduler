<?php

namespace App\Mcp\Tools;

use App\Connectors\Network;
use App\Mcp\Abilities;
use App\Models\User;
use App\Services\HashtagStats;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('suggest_hashtags')]
#[Description('Hashtags sugeridos para una marca a partir de su historico propio (sin IA): los usados al menos 2 veces en lo publicado, ordenados por la mejora de interacciones sobre la media. Opcional: red. available=false si aun no hay datos suficientes.')]
#[IsReadOnly]
class SuggestHashtags extends SchedulerTool
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
            'network' => $schema->string()->enum(Network::availableValues())->description('Solo hashtags de esa red (omitida = todas). '.Network::listing()),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $brand = $this->brandFrom($request, $user);
        $raw = $request->get('network');
        $network = filled($raw) ? (Network::tryFrom((string) $raw) ?? throw new InvalidArgumentException('Red no valida.')) : null;

        return ['brand_id' => $brand->id, 'brand_name' => $brand->name] + app(HashtagStats::class)->suggest($user, $brand, $network);
    }
}
