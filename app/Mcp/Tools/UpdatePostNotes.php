<?php

namespace App\Mcp\Tools;

use App\Http\Requests\PostRequest;
use App\Mcp\Abilities;
use App\Models\Post;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_post_notes')]
#[Description('Edita las notas internas de una publicacion (texto plano, hasta 5000 caracteres; vacio = borrarlas). Funciona aunque la publicacion ya este publicada o en vuelo y no toca sus destinos. Las notas nunca se publican.')]
class UpdatePostNotes extends SchedulerTool
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
            'notes' => $schema->string()->description('Notas internas en texto plano (maximo 5000 caracteres); cadena vacia = borrarlas')->required(),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate([
            'post_id' => ['required', 'integer'],
            'notes' => ['present', 'nullable', 'string', 'max:'.PostRequest::NOTES_MAX],
        ]);
        $post = $this->owned(Post::class, $user, $data['post_id'], 'Publicacion');

        // La auditoria registra la herramienta y el post, nunca el contenido de las notas.
        return $this->presentPost(app(PostService::class)->updateNotes($user, $post, $data['notes'] ?? null));
    }
}
