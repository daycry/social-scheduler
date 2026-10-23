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

#[Name('update_post')]
#[Description('Edita una publicacion que aun no se ha publicado ni esta publicandose: sustituye texto, fecha, media y destinos (con su texto propio, targets[].text; omitirlo vuelve el destino a la plantilla) por los indicados.')]
class UpdatePost extends SchedulerTool
{
    use PostPayload;

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
        return ['post_id' => $schema->integer()->description('ID de la publicacion')->required()] + $this->payloadSchema($schema);
    }

    protected function run(Request $request, User $user): array
    {
        $post = $this->owned(Post::class, $user, $request->get('post_id'), 'Publicacion');
        $data = $this->validatedPayload($request, ['post_id' => ['required', 'integer']]);
        unset($data['post_id']);

        return $this->presentPost(app(PostService::class)->update($user, $post, $data));
    }
}
