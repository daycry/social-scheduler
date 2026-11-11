<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\PostWorkflowActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('mark_post_published')]
#[Description('Marca como publicado un destino manual (targets[].manual=true) que ya publicaste a mano. url es opcional y debe empezar por https://. Idempotente. target_id sale en get_post.')]
class MarkPostPublished extends SchedulerTool
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
        return ['post_id' => $schema->integer()->min(1)->description('ID de la publicacion')->required(), 'target_id' => $schema->integer()->min(1)->description('ID del destino manual (targets[].target_id de get_post)')->required(), 'url' => $schema->string()->description('URL https de la publicacion (opcional)')];
    }

    protected function run(Request $request, User $user): array
    {
        $actions = app(PostWorkflowActions::class);

        return $actions->markPublished($user, (int) $request->get('post_id'), (int) $request->get('target_id'), $request->get('url'));
    }
}
