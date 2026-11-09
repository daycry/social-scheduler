<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\PostWorkflowActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_brand_collaborators')]
#[Description('Aprobadores invitados de una marca (solo el propietario): list, invite(email, role=aprobador) y revoke(collaborator_id). invite responde siempre igual, exista o no una cuenta con ese email.')]
class ManageBrandCollaborators extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_WRITE;
    }

    protected function abilityFor(Request $request): string
    {
        return in_array($request->get('action'), ['list'], true) ? Abilities::POSTS_READ : Abilities::POSTS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'post';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['action' => $schema->string()->enum(['list', 'invite', 'revoke'])->required(), 'brand_id' => $schema->integer()->min(1)->required(), 'email' => $schema->string(), 'role' => $schema->string()->enum(['aprobador']), 'collaborator_id' => $schema->integer()->min(1)];
    }

    protected function run(Request $request, User $user): array
    {
        $actions = app(PostWorkflowActions::class);

        $action = (string) $request->get('action');
        $brand = (int) $request->get('brand_id');

        return match ($action) {
            'list' => $actions->collaborators($user, $brand),
            'invite' => $actions->invite($user, $brand, $request->get('email'), $request->get('role')),
            'revoke' => $actions->revoke($user, $brand, (int) $request->get('collaborator_id')),
            default => throw new \InvalidArgumentException('action debe ser list, invite o revoke.'),
        };
    }
}
