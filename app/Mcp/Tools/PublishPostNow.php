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

#[Name('publish_post_now')]
#[Description('Publica ahora un borrador o una publicacion programada: se valida como al programar (sin exigir fecha futura) y se encola de inmediato. Una publicacion que se esta publicando o ya se publico se rechaza. El estado pasa a publicada cuando la red responde (consulta get_post).')]
class PublishPostNow extends SchedulerTool
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
        $data = $request->validate(['post_id' => ['required', 'integer']]);
        $post = $this->owned(Post::class, $user, $data['post_id'], 'Publicacion');

        return $this->presentPost(app(PostService::class)->publishNow($user, $post));
    }
}
