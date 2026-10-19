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

#[Name('reschedule_post')]
#[Description('Cambia la fecha de una publicacion pendiente. Una ya publicada no se reprograma.')]
class ReschedulePost extends SchedulerTool
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
        return [
            'post_id' => $schema->integer()->description('ID de la publicacion')->required(),
            'scheduled_at' => $schema->string()->description('Nueva fecha futura YYYY-MM-DD HH:MM (zona de la marca si no lleva zona)')->required(),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['post_id' => ['required', 'integer'], 'scheduled_at' => ['required', 'string', 'max:40']]);
        $post = $this->owned(Post::class, $user, $data['post_id'], 'Publicacion');

        return $this->presentPost(app(PostService::class)->reschedule($user, $post, $data['scheduled_at']));
    }
}
