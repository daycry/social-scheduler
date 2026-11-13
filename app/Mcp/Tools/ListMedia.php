<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\MediaListing;
use App\Support\CursorPage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_media')]
#[Description('Lista la media del usuario (por defecto solo la ya validada, status=listo; paginado por cursor: limit hasta 100 y cursor = el next_cursor de la pagina anterior; has_more/next_cursor indican si hay mas). Con status=pendiente se ven las importaciones en curso (bytes y total = progreso), con status=invalido las fallidas (error = motivo) y con status=todos cualquiera. Solo la media «listo» se usa por id en create_post/update_post; el agente no sube binarios por MCP.')]
#[IsReadOnly]
class ListMedia extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::BRANDS_READ;
    }

    protected function resource(): ?string
    {
        return 'media';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['listo', 'pendiente', 'invalido', 'todos'])->description('Estado a listar (por defecto listo). pendiente = importaciones en curso con su progreso; invalido = fallidas con su motivo'),
            'kind' => $schema->string()->enum(MediaListing::KINDS)->description('Tipo a listar; por defecto imagen y video (lo publicable). pdf = documentos de la biblioteca (no se publican)'),
            'limit' => $schema->integer()->description('Cuantas devolver (1-100, por defecto 50)'),
            'cursor' => $schema->string()->description('Cursor opaco: el next_cursor de la pagina anterior'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $request->validate(CursorPage::RULES);

        return app(MediaListing::class)->page($user, $request->get('status'), $request->get('limit'), $request->get('cursor'), kind: $request->get('kind'));
    }
}
