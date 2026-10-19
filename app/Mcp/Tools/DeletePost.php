<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Post;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_post')]
#[Description('Borra una publicacion que aun no se ha publicado ni esta publicandose. Es irreversible.')]
#[IsDestructive]
class DeletePost extends SchedulerTool
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
        return ['post_id' => $schema->integer()->description('ID de la publicacion')->required()];
    }

    protected function run(Request $request, User $user): array
    {
        $post = $this->owned(Post::class, $user, $request->get('post_id'), 'Publicacion');
        $id = $post->id;
        app(PostService::class)->delete($user, $post);

        return ['id' => $id, 'deleted' => true];
    }
}
