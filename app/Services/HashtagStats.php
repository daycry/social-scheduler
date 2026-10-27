<?php

namespace App\Services;

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\User;
use App\Support\Hashtags;
use Illuminate\Support\Facades\Cache;

/**
 * Sugerencias de hashtags desde el HISTORICO PROPIO de la marca (C-04a, D5): sin IA y sin llamadas externas. Extrae los `#tags` del texto
 * publicado de cada destino (el texto propio del destino manda sobre el del post, con la misma extraccion que los *facets* de Bluesky),
 * los cruza con la ultima lectura de metricas del destino y ordena por mejora de interacciones sobre la media de la marca.
 * Solo se ofrecen los usados al menos `social.hashtags.min_uses` veces (2). Cache corta por usuario, marca y red.
 */
class HashtagStats
{
    public function __construct(private readonly BestTimesService $engagement) {}

    /**
     * @return array{brand_id: int, network: ?string, available: bool, reason: ?string, min_uses: int, posts: int, average: ?float, tags: list<array{tag: string, uses: int, avg_interactions: float, lift_pct: ?float}>}
     */
    public function suggest(User $user, Brand $brand, ?Network $network = null): array
    {
        $ttl = (int) config('social.hashtags.cache_minutes', 60);
        if ($ttl <= 0) {
            return $this->compute($user, $brand, $network);
        }

        $key = sprintf('hashtags:%d:%d:%s', $user->id, $brand->id, $network?->value ?? 'all');

        return Cache::remember($key, now()->addMinutes($ttl), fn () => $this->compute($user, $brand, $network));
    }

    /** @return array<string, mixed> */
    private function compute(User $user, Brand $brand, ?Network $network): array
    {
        $min = max(1, (int) config('social.hashtags.min_uses', 2));
        $limit = max(1, (int) config('social.hashtags.limit', 15));
        $days = max(1, (int) config('social.hashtags.days', 365));

        $targets = PostTarget::withoutUserScope()
            ->where('user_id', $user->id)
            ->where('status', PostStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', now()->subDays($days))
            ->whereIn('post_id', Post::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->select('id'))
            ->when($network, fn ($q) => $q->where('network', $network->value))
            ->orderBy('id')
            ->get(['id', 'post_id', 'text']);

        $texts = Post::withoutUserScope()->whereIn('id', $targets->pluck('post_id')->unique())->pluck('text', 'id');
        $latest = $this->latestMetrics($user, $targets->pluck('id')->all());

        $sums = [];   // clave en minusculas => [etiqueta, usos, suma]
        $total = 0.0;
        $posts = 0;
        foreach ($targets as $target) {
            $interactions = isset($latest[$target->id]) ? $this->engagement->engagement($latest[$target->id]) : null;
            if ($interactions === null) {
                continue; // sin metricas no hay rendimiento que comparar
            }
            $total += $interactions;
            $posts++;

            foreach (Hashtags::tags((string) ($target->text ?? $texts[$target->post_id] ?? '')) as $tag) {
                $key = mb_strtolower($tag);
                $sums[$key] ??= ['tag' => $tag, 'uses' => 0, 'sum' => 0.0];
                $sums[$key]['uses']++;
                $sums[$key]['sum'] += $interactions;
            }
        }

        $average = $posts > 0 ? $total / $posts : null;
        $tags = [];
        foreach ($sums as $row) {
            if ($row['uses'] < $min) {
                continue;
            }
            $avg = $row['sum'] / $row['uses'];
            $tags[] = [
                'tag' => $row['tag'],
                'uses' => $row['uses'],
                'avg_interactions' => round($avg, 2),
                'lift_pct' => $average !== null && $average > 0 ? round(($avg / $average - 1) * 100, 1) : null,
            ];
        }
        usort($tags, fn (array $a, array $b) => [$b['avg_interactions'], $b['uses'], $a['tag']] <=> [$a['avg_interactions'], $a['uses'], $b['tag']]);
        $tags = array_slice($tags, 0, $limit);

        return [
            'brand_id' => $brand->id,
            'network' => $network?->value,
            'available' => $tags !== [],
            'reason' => $tags === [] ? 'Aún no hay datos suficientes: publica con hashtags y espera a tener métricas.' : null,
            'min_uses' => $min,
            'posts' => $posts,
            'average' => $average === null ? null : round($average, 1),
            'tags' => $tags,
        ];
    }

    /**
     * Ultima lectura (fecha mas alta) de cada destino, resuelta en SQL: los acumulados crecen con el tiempo.
     *
     * @param  list<int>  $targetIds
     * @return array<int, array<string, mixed>>
     */
    private function latestMetrics(User $user, array $targetIds): array
    {
        $latest = [];
        foreach (array_chunk($targetIds, 500) as $ids) {
            $newest = PostMetricSnapshot::withoutUserScope()->where('user_id', $user->id)->whereIn('post_target_id', $ids)
                ->groupBy('post_target_id')->selectRaw('post_target_id, MAX(date) as max_date');

            PostMetricSnapshot::withoutUserScope()->from('post_metric_snapshots as s')
                ->joinSub($newest, 'n', fn ($j) => $j->on('n.post_target_id', '=', 's.post_target_id')->on('n.max_date', '=', 's.date'))
                ->where('s.user_id', $user->id)
                ->get(['s.post_target_id', 's.metrics'])
                ->each(function ($s) use (&$latest) {
                    $latest[$s->post_target_id] = (array) (is_string($s->metrics) ? json_decode($s->metrics, true) : $s->metrics);
                });
        }

        return $latest;
    }
}
