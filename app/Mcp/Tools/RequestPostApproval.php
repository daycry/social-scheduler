<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\PostWorkflowActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('request_post_approval')]
#[Description('Pide la aprobacion de una publicacion propia: pasa a pendiente (un post programado vuelve a borrador) y no se programa ni publica hasta que se apruebe. Idempotente.')]
class RequestPostApproval extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'post';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['post_id' => $schema->integer()->min(1)->description('ID de la publicacion')->required()];
    }

    protected function run(Request $request, User $user): array
    {
        $actions = app(PostWorkflowActions::class);

        return $actions->requestApproval($user, (int) $request->get('post_id'));
    }
}
