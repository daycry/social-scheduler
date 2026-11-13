<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Autolist;
use App\Models\User;
use App\Services\AutolistService;
use App\Support\CursorPage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_autolist')]
#[Description('Autolistas: colas de contenido con franjas semanales que generan publicaciones programadas. action: list (las del usuario, opcionalmente de una marca; paginado por cursor con limit y cursor), create (brand, name, targets [{account_id, content_type}], slots [{day 1-7, time HH:MM en la zona de la marca}], recycle), update (autolist_id; active y/o recycle), delete (autolist_id; las publicaciones ya generadas se conservan), add_item (autolist_id y post_template_id de una plantilla de la marca, o text y/o media ids propios de list_media), remove_item (autolist_id, item_id) y generate (autolist_id: crea ya las publicaciones de los proximos dias; idempotente). list requiere posts:read; el resto, posts:write.')]
class ManageAutolist extends SchedulerTool
{
    private const ACTIONS = ['list', 'create', 'update', 'delete', 'add_item', 'remove_item', 'generate'];

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
        return 'autolist';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(self::ACTIONS)->required(),
            ...$this->brandSchema($schema, 'Marca (create; filtro en list)'),
            'autolist_id' => $schema->integer()->description('update, delete, add_item, remove_item, generate'),
            'name' => $schema->string()->description('create: nombre (hasta 120 caracteres)'),
            'recycle' => $schema->boolean()->description('create/update: volver a empezar la cola al acabarla'),
            'active' => $schema->boolean()->description('update: activa o pausa la lista'),
            'targets' => $schema->array()->items($schema->object(['account_id' => $schema->integer()->required(), 'content_type' => $schema->string()->required()]))->description('create: destinos; los tipos validos salen de list_accounts'),
            'slots' => $schema->array()->items($schema->object(['day' => $schema->integer()->min(1)->max(7)->required(), 'time' => $schema->string()->description('HH:MM')->required()]))->description('create: franjas semanales (dia ISO 1=lunes)'),
            'post_template_id' => $schema->integer()->description('add_item: plantilla de la Biblioteca de la marca'),
            'text' => $schema->string()->description('add_item: texto del elemento (si no hay plantilla)'),
            'media' => $schema->array()->items($schema->integer())->description('add_item: ids de media propia lista'),
            'item_id' => $schema->integer()->description('remove_item'),
            'limit' => $schema->integer()->description('list: cuantas devolver (1-100, por defecto 50)'),
            'cursor' => $schema->string()->description('list: el next_cursor de la pagina anterior'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $action = $request->validate(['action' => ['required', 'in:'.implode(',', self::ACTIONS)]])['action'];
        $lists = app(AutolistService::class);

        if ($action === 'list') {
            $request->validate(CursorPage::RULES);
            $brand = $this->brandFrom($request, $user, required: false);
            [$rows, $page] = CursorPage::fetch($lists->query($user, $brand?->id)->reorder('id', 'desc'), $request->get('limit'), $request->get('cursor'));

            return ['autolists' => $rows->map(fn (Autolist $l) => $lists->present($l))->values()->all()] + $page;
        }

        if ($action === 'create') {
            $data = $request->validate(AutolistService::createRules());
            $list = $lists->create($user, $this->brandFrom($request, $user), $data['name'], $data['targets'], $data['slots'], (bool) ($data['recycle'] ?? false));

            return $lists->present($list->load('items.template'));
        }

        /** @var Autolist $list */
        $list = $this->owned(Autolist::class, $user, $request->get('autolist_id'), 'Autolista');

        switch ($action) {
            case 'update':
                $data = $request->validate(['active' => ['sometimes', 'boolean'], 'recycle' => ['sometimes', 'boolean']]);
                if ($data === []) {
                    throw new InvalidArgumentException('update necesita active y/o recycle.');
                }
                $lists->update($list, $data);
                break;
            case 'delete':
                $lists->delete($list);

                return ['id' => $list->id, 'deleted' => true];
            case 'add_item':
                $data = $request->validate(AutolistService::itemRules());
                $lists->addItem($user, $list, isset($data['post_template_id']) ? (int) $data['post_template_id'] : null, $data['text'] ?? null, $data['media'] ?? []);
                break;
            case 'remove_item':
                $lists->removeItem($list, (int) $request->validate(['item_id' => ['required', 'integer']])['item_id']);
                break;
            case 'generate':
                return ['id' => $list->id] + $lists->generate($list);
        }

        return $lists->present($list->fresh()->load('items.template'));
    }
}
