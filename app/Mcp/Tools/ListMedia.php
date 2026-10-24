<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_media')]
#[Description('Lista la media ya subida y validada del usuario (paginado: limit hasta 100 y cursor = before_id; has_more/next_cursor indican si hay mas). Se usa por id en create_post/update_post; el agente no sube binarios por MCP.')]
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
            'limit' => $schema->integer()->description('Cuantas devolver (1-100, por defecto 50)'),
            'before_id' => $schema->integer()->description('Cursor: devuelve las de id menor (usa next_cursor de la pagina anterior)'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $limit = min(100, max(1, (int) ($request->get('limit') ?? 50)));
        $query = MediaAsset::withoutUserScope()->where('user_id', $user->id)->where('status', MediaAsset::STATUS_READY);
        if (is_numeric($before = $request->get('before_id'))) {
            $query->where('id', '<', (int) $before);
        }
        $rows = $query->latest('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        return [
            // preview_url es de la web (necesita la sesion del navegador): no sirve a un cliente MCP.
            'media' => $page->map(fn (MediaAsset $m) => array_diff_key($m->toPublicArray(), ['preview_url' => true]))->values()->all(),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $page->last()->id : null,
        ];
    }
}
