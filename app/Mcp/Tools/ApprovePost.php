<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\PostWorkflowActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('approve_post')]
#[Description('Aprueba una publicacion pendiente. Solo el propietario o un aprobador invitado de la marca; otra persona recibe 404. Idempotente. Tras aprobar, el propietario la programa con update_post.')]
class ApprovePost extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_APPROVE;
    }

    protected function resource(): ?string
    {
        return 'post';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['post_id' => $schema->integer()->min(1)->description('ID de la publicacion')->required(), 'signature' => $schema->string()->description('content_signature de la publicacion tal como la revisaste (list_pending_approvals); si el contenido cambio responde 409')->required()];
    }

    protected function run(Request $request, User $user): array
    {
        $actions = app(PostWorkflowActions::class);

        return $actions->approve($user, (int) $request->get('post_id'), $request->get('signature'));
    }
}
