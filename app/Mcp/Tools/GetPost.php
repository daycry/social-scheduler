<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_post')]
#[Description('Consulta una publicacion: texto, fecha, estado por destino, errores y enlace publicado.')]
#[IsReadOnly]
class GetPost extends SchedulerTool
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
        return ['post_id' => $schema->integer()->description('ID de la publicacion')->required()];
    }

    protected function run(Request $request, User $user): array
    {
        return $this->presentPost($this->owned(Post::class, $user, $request->get('post_id'), 'Publicacion'));
    }
}
