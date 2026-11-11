<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\Studio\StudioCollab;
use App\Services\Studio\StudioService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_design_collaboration')]
#[Description('Colaboración en un diseño. Lectura (designs:read): list_shares, list_links, list_comments, list_versions. Escritura (designs:write): share(email, role reader|commenter|editor; solo el propietario), update_share(share_id, role), revoke_share(share_id), create_link(link_role reader|commenter, expires_at opcional; devuelve el token una sola vez), revoke_link(link_id), comment(body, page_id/element_id/parent_id opcionales; exige rol comentarista o superior), resolve_comment/reopen_comment/delete_comment(comment_id), restore_version(version, revision: la revisión actual de get_design; 409 si está obsoleta). Sin acceso 404; con acceso insuficiente 403.')]
class ManageDesignCollaboration extends SchedulerTool
{
    use SharedDesign;

    private const READ = ['list_shares', 'list_links', 'list_comments', 'list_versions'];

    protected function ability(): string
    {
        return Abilities::DESIGNS_WRITE;
    }

    protected function abilityFor(Request $request): string
    {
        return in_array($request->get('action'), self::READ, true) ? Abilities::DESIGNS_READ : Abilities::DESIGNS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'design';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['design_id' => $schema->integer()->min(1)->required(),
            'action' => $schema->string()->enum([...self::READ, 'share', 'update_share', 'revoke_share', 'create_link', 'revoke_link', 'comment', 'resolve_comment', 'reopen_comment', 'delete_comment', 'restore_version'])->required(),
            'email' => $schema->string()->description('share: email de un usuario registrado'),
            'role' => $schema->string()->enum(StudioCollab::ROLES)->description('share/update_share'),
            'share_id' => $schema->integer()->min(1), 'link_id' => $schema->integer()->min(1), 'comment_id' => $schema->integer()->min(1),
            'link_role' => $schema->string()->enum(StudioCollab::LINK_ROLES)->description('create_link'),
            'expires_at' => $schema->string()->description('create_link: fecha ISO futura (máximo un año), opcional'),
            'body' => $schema->string()->description('comment: hasta 2000 caracteres'),
            'page_id' => $schema->string(), 'element_id' => $schema->string(), 'parent_id' => $schema->integer()->min(1),
            'version' => $schema->integer()->min(1)->description('restore_version: número de la versión'),
            'revision' => $schema->integer()->min(1)->description('restore_version: revisión actual del diseño')];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['design_id' => ['required', 'integer', 'min:1'], 'action' => ['required', 'string']]);
        $design = $this->accessibleDesign($user, $data['design_id']);
        $collab = app(StudioCollab::class);
        $studio = app(StudioService::class);

        return match ($data['action']) {
            'list_shares' => ['shares' => $collab->shares($user, $design)],
            'list_links' => ['links' => $collab->links($user, $design)],
            'list_comments' => ['comments' => $collab->comments($design)],
            'list_versions' => ['versions' => $studio->versions($user, $design), 'revision' => $design->revision],
            'share' => (function () use ($collab, $user, $design, $request): array {
                $collab->share($user, $design, $request->get('email'), $request->get('role'));

                return ['message' => StudioCollab::SHARE_MESSAGE];
            })(),
            'update_share' => ['share' => $collab->presentShare($collab->updateShare($user, $design, (int) $request->get('share_id'), $request->get('role')))],
            'revoke_share' => ['revoked' => $collab->revokeShare($user, $design, (int) $request->get('share_id')) ?? true],
            'create_link' => (function () use ($collab, $user, $design, $request): array {
                [$link, $token] = $collab->createLink($user, $design, $request->get('link_role'), $request->get('expires_at'));

                return ['link' => $collab->presentLink($link, $token)];
            })(),
            'revoke_link' => ['link' => $collab->presentLink($collab->revokeLink($user, $design, (int) $request->get('link_id')))],
            'comment' => (function () use ($collab, $user, $design, $request): array {
                $collab->authorize($user, $design, 'comment');
                $comment = $collab->addComment($design, $user, null, array_filter($request->all(), fn ($value, $key) => in_array($key, ['body', 'page_id', 'element_id', 'parent_id'], true) && $value !== null, ARRAY_FILTER_USE_BOTH));

                return ['comment' => collect($collab->comments($design))->firstWhere('id', $comment->id)];
            })(),
            'resolve_comment' => ['resolved' => $collab->setResolved($user, $design, (int) $request->get('comment_id'), true)->resolved_at !== null],
            'reopen_comment' => ['resolved' => $collab->setResolved($user, $design, (int) $request->get('comment_id'), false)->resolved_at !== null],
            'delete_comment' => ['deleted' => $collab->deleteComment($user, $design, (int) $request->get('comment_id')) ?? true],
            'restore_version' => $studio->present($user, $studio->restoreVersion($user, $design, (int) $request->get('version'), $request->get('revision')), true),
            default => throw new \InvalidArgumentException('Acción no válida.'),
        };
    }
}
