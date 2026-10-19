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

#[Name('cancel_post')]
#[Description('Cancela la programacion: la publicacion pendiente vuelve a borrador y no se publicara. No la borra (usa delete_post).')]
class CancelPost extends SchedulerTool
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

        return $this->presentPost(app(PostService::class)->unschedule($user, $post));
    }
}
