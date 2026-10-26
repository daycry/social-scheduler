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

#[Name('update_post_campaign')]
#[Description('Asigna o quita la campana de una publicacion (campaign_id de list_campaigns; null = quitarla). Funciona aunque la publicacion ya este publicada o en vuelo y no toca sus destinos. La campana debe ser de la misma marca y no estar archivada.')]
class UpdatePostCampaign extends SchedulerTool
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
            'campaign_id' => $schema->integer()->nullable()->description('ID de la campana (list_campaigns); null = quitar la campana')->required(),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate([
            'post_id' => ['required', 'integer'],
            'campaign_id' => ['present', 'nullable', 'integer'],
        ]);
        $post = $this->owned(Post::class, $user, $data['post_id'], 'Publicacion');

        return $this->presentPost(app(PostService::class)->updateCampaign($user, $post, $data['campaign_id'] === null ? null : (int) $data['campaign_id']));
    }
}
