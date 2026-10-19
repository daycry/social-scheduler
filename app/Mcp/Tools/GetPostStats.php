<?php

namespace App\Mcp\Tools;

use App\Analytics\Calculator;
use App\Analytics\Metric;
use App\Mcp\Abilities;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_post_stats')]
#[Description('Ultimas metricas conocidas de una publicacion por destino (red). null = no disponible (nunca 0).')]
#[IsReadOnly]
class GetPostStats extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::STATS_READ;
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
        /** @var Post $post */
        $post = $this->owned(Post::class, $user, $request->get('post_id'), 'Publicacion');
        $post->loadMissing('targets');

        $latest = PostMetricSnapshot::withoutUserScope()
            ->where('user_id', $user->id)->whereIn('post_target_id', $post->targets->pluck('id'))
            ->orderBy('date')->get()->keyBy('post_target_id'); // el ultimo dia sobrescribe

        return [
            'id' => $post->id,
            'targets' => $post->targets->map(function ($t) use ($latest) {
                $metrics = Metric::clean($latest[$t->id]->metrics ?? [], Metric::POST);

                return [
                    'target_id' => $t->id,
                    'network' => $t->network->value,
                    'status' => $t->status->value,
                    'external_url' => $t->external_url,
                    'metrics' => $metrics,
                    'has_data' => ! Metric::isEmpty($metrics),
                    'engagement_rate' => Calculator::engagementRate($metrics[Metric::INTERACTIONS], $metrics[Metric::VIEWS]),
                    'as_of' => isset($latest[$t->id]) ? substr((string) $latest[$t->id]->date, 0, 10) : null,
                ];
            })->values()->all(),
        ];
    }
}
