<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\PostWorkflowActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_pending_approvals')]
#[Description('Publicaciones pendientes de aprobacion que puedes decidir: las tuyas y las de las marcas donde eres aprobador invitado.')]
class ListPendingApprovals extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_READ;
    }

    protected function resource(): ?string
    {
        return 'post';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): array
    {
        $actions = app(PostWorkflowActions::class);

        return $actions->pending($user);
    }
}
