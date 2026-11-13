<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\SavedText;
use App\Models\User;
use App\Services\SavedTextService;
use App\Support\CursorPage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_saved_texts')]
#[Description('Textos guardados del usuario: fragmentos y grupos de hashtags que el compositor inserta en una publicacion. action: list (con brand o brand_id, los de todas las marcas mas los de esa marca; paginado por cursor con limit y cursor), create (kind fragmento|hashtags, title, body y brand opcional; sin marca vale para todas), update (saved_text_id y los mismos campos: reemplaza el texto) o delete (saved_text_id). list requiere posts:read; el resto, posts:write.')]
class ManageSavedTexts extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_READ;
    }

    protected function abilityFor(Request $request): string
    {
        return $request->get('action') === 'list' ? Abilities::POSTS_READ : Abilities::POSTS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'saved_text';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'create', 'update', 'delete'])->required(),
            ...$this->brandSchema($schema, 'Marca a la que pertenece el texto (create/update; sin ella, todas) o filtro de list'),
            'saved_text_id' => $schema->integer()->description('update, delete'),
            'kind' => $schema->string()->enum(SavedText::KINDS)->description('create/update'),
            'title' => $schema->string()->description('create/update: hasta 120 caracteres'),
            'body' => $schema->string()->description('create/update: hasta 5000 caracteres'),
            'limit' => $schema->integer()->description('list: cuantos devolver (1-100, por defecto 50)'),
            'cursor' => $schema->string()->description('list: el next_cursor de la pagina anterior'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $action = $request->validate(['action' => ['required', 'in:list,create,update,delete']])['action'];
        $texts = app(SavedTextService::class);
        $brand = $this->brandFrom($request, $user, required: false);

        if ($action === 'list') {
            $request->validate(CursorPage::RULES);
            [$rows, $page] = CursorPage::fetch($texts->query($user, $brand?->id), $request->get('limit'), $request->get('cursor'), 'saved-texts:u'.$user->id.':b'.($brand?->id ?? 'all'));

            return ['saved_texts' => $rows->map(fn (SavedText $t) => $texts->toArray($t))->values()->all()] + $page;
        }

        if ($action === 'create') {
            return $texts->toArray($texts->create($user, $this->fields($request, $user, $brand?->id))->load('brand:id,name'));
        }

        /** @var SavedText $text */
        $text = $this->owned(SavedText::class, $user, $request->get('saved_text_id'), 'Entrada de texto guardado');

        if ($action === 'delete') {
            $texts->delete($text);

            return ['id' => $text->id, 'deleted' => true];
        }

        return $texts->toArray($texts->update($text, $this->fields($request, $user, $brand?->id))->load('brand:id,name'));
    }

    /** @return array{kind: string, title: string, body: string, brand_id: ?int} */
    private function fields(Request $request, User $user, ?int $brandId): array
    {
        $data = $request->validate(Arr::except(SavedTextService::rules($user->id), 'brand_id'));

        return $data + ['brand_id' => $brandId];
    }
}
