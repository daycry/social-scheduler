<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\PostWorkflowActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('reject_post')]
#[Description('Rechaza una publicacion pendiente con un comentario obligatorio: sigue en borrador y el propietario la edita y vuelve a pedir la aprobacion. Solo el propietario o un aprobador de la marca.')]
class RejectPost extends SchedulerTool
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
        return ['post_id' => $schema->integer()->min(1)->description('ID de la publicacion')->required(), 'comment' => $schema->string()->description('Motivo del rechazo (obligatorio, hasta 2000 caracteres)')->required(), 'signature' => $schema->string()->description('content_signature revisada; si el contenido cambio responde 409')->required()];
    }

    protected function run(Request $request, User $user): array
    {
        $actions = app(PostWorkflowActions::class);

        return $actions->reject($user, (int) $request->get('post_id'), $request->get('comment'), $request->get('signature'));
    }
}
