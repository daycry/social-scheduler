<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Post;
use App\Models\User;
use App\Services\TrashService;
use App\Support\CursorPage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_trash')]
#[Description('Papelera de publicaciones (delete_post las envia aqui y se purgan a los 30 dias). action: list (las mas recientes primero, con los dias que les quedan; paginado por cursor con limit y cursor), restore (post_id: la saca de la papelera; si estaba programada y su hora ya paso vuelve a borrador, nunca se publica sola) o delete_forever (post_id: la elimina para siempre, es IRREVERSIBLE: exige confirm: true y la confirmacion humana explicita antes de llamarla; sin confirm no borra nada). list requiere posts:read; restore y delete_forever, posts:write.')]
class ManageTrash extends SchedulerTool
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
        return 'post';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'restore', 'delete_forever'])->required(),
            'post_id' => $schema->integer()->description('restore, delete_forever'),
            'confirm' => $schema->boolean()->description('delete_forever: debe ser true; el borrado es irreversible'),
            'limit' => $schema->integer()->description('list: cuantas devolver (1-100, por defecto 50)'),
            'cursor' => $schema->string()->description('list: el next_cursor de la pagina anterior'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $action = $request->validate(['action' => ['required', 'in:list,restore,delete_forever']])['action'];
        $trash = app(TrashService::class);

        if ($action === 'list') {
            $request->validate(CursorPage::RULES);
            [$rows, $page] = CursorPage::fetch($trash->query($user), $request->get('limit'), $request->get('cursor'), 'trash:u'.$user->id);

            return ['retention_days' => TrashService::RETENTION_DAYS, 'posts' => $rows->map(fn (Post $p) => $trash->present($p))->values()->all()] + $page;
        }

        if ($action === 'delete_forever' && $request->get('confirm') !== true) {
            throw new InvalidArgumentException('delete_forever elimina la publicacion para siempre y no se puede deshacer: confirm debe ser true tras la confirmacion humana. No se ha borrado nada.');
        }

        $id = is_numeric($request->get('post_id')) ? (int) $request->get('post_id') : null;
        $post = $id === null ? null : $trash->query($user)->find($id);
        if ($post === null) {
            $crossUser = $id !== null && Post::withoutUserScope()->onlyTrashed()->whereKey($id)->exists();

            throw new NotOwned('Publicacion no encontrada en la papelera.', 'post', $id, $crossUser);
        }

        if ($action === 'delete_forever') {
            $trash->forceDelete($user, $post);

            return ['id' => $post->id, 'deleted' => true];
        }

        return ['restored' => true] + $this->presentPost($trash->restore($user, $post));
    }
}
