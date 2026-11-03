<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\Studio\StudioService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_designs')]
#[Description('Lista diseños nativos de una marca, 24 por página. Devuelve resúmenes sin documento, total/current_page/last_page; usa get_design para editar. query es búsqueda literal; templates/archived/folder_id filtran. Requiere designs:read.')]
#[IsReadOnly]
class ListDesigns extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::DESIGNS_READ;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->brandSchema($schema) + ['query' => $schema->string(), 'folder_id' => $schema->integer(), 'templates' => $schema->boolean(),
            'archived' => $schema->boolean(), 'page' => $schema->integer()->min(1)];
    }

    protected function run(Request $request, User $user): array
    {
        $filters = $request->validate(['query' => ['nullable', 'string', 'max:120'], 'folder_id' => ['nullable', 'integer', 'min:1'],
            'templates' => ['nullable', 'boolean'], 'archived' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']]);

        return app(StudioService::class)->designs($user, $this->brandFrom($request, $user), $filters)->toArray();
    }
}
