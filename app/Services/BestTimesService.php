<?php

namespace App\Services;

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Mejores horas para publicar (PA-05): mapa de calor 7 (dia de la semana) x 24 (hora) por red con el engagement
 * medio de las publicaciones propias, en la zona horaria de la marca. Solo usa metricas ya guardadas
 * (`post_metric_snapshots`, la ultima lectura de cada destino). Con menos de `min_posts` publicaciones con datos
 * devuelve `available = false`: un mapa con tres puntos sugiere una certeza que no existe.
 */
class BestTimesService
{
    public const DAYS = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];

    /**
     * @return array{brand_id: int, network: string, timezone: string, days: int, available: bool, reason: ?string, posts: int, min_posts: int, matrix: list<list<float|null>>, counts: list<list<int>>, top: list<array{day: string, dow: int, hour: int, posts: int, avg_engagement: float}>}
     */
    public function heatmap(User $user, Brand $brand, Network $network, ?int $days = null): array
    {
        $days = max(7, min(365, $days ?? (int) config('social.best_times.days', 90)));
        $min = (int) config('social.best_times.min_posts', 10);
        $tz = $brand->timezone ?: 'UTC';

        $targets = PostTarget::withoutUserScope()
            ->where('user_id', $user->id)
            ->where('network', $network->value)
            ->where('status', PostStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', now()->subDays($days))
            ->whereIn('post_id', Post::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->select('id'))
            ->get(['id', 'published_at'])
            ->keyBy('id');

        // Ultima lectura (fecha mas alta) de cada destino: los acumulados crecen con el tiempo.
        $latest = [];
        foreach ($targets->keys()->chunk(500) as $ids) {
            PostMetricSnapshot::withoutUserScope()
                ->where('user_id', $user->id)
                ->whereIn('post_target_id', $ids->all())
                ->orderBy('date')
                ->get(['post_target_id', 'metrics'])
                ->each(function (PostMetricSnapshot $s) use (&$latest) {
                    $latest[$s->post_target_id] = $s->metrics;
                });
        }

        $sum = array_fill(0, 7, array_fill(0, 24, 0.0));
        $counts = array_fill(0, 7, array_fill(0, 24, 0));
        $posts = 0;

        foreach ($latest as $targetId => $metrics) {
            $engagement = $this->engagement((array) $metrics);
            if ($engagement === null) {
                continue;
            }
            $local = CarbonImmutable::instance($targets[$targetId]->published_at)->setTimezone($tz);
            $d = $local->dayOfWeekIso - 1;
            $h = $local->hour;
            $sum[$d][$h] += $engagement;
            $counts[$d][$h]++;
            $posts++;
        }

        $matrix = [];
        $cells = [];
        for ($d = 0; $d < 7; $d++) {
            for ($h = 0; $h < 24; $h++) {
                $avg = $counts[$d][$h] > 0 ? round($sum[$d][$h] / $counts[$d][$h], 2) : null;
                $matrix[$d][$h] = $avg;
                if ($avg !== null) {
                    $cells[] = ['day' => self::DAYS[$d], 'dow' => $d + 1, 'hour' => $h, 'posts' => $counts[$d][$h], 'avg_engagement' => $avg];
                }
            }
        }

        $available = $posts >= $min;
        usort($cells, fn (array $a, array $b) => [$b['avg_engagement'], $b['posts']] <=> [$a['avg_engagement'], $a['posts']]);

        return [
            'brand_id' => $brand->id,
            'network' => $network->value,
            'timezone' => $tz,
            'days' => $days,
            'available' => $available,
            'reason' => $available ? null : "No disponible: hay $posts publicaciones con metricas y se necesitan al menos $min.",
            'posts' => $posts,
            'min_posts' => $min,
            'matrix' => $available ? $matrix : [],
            'counts' => $available ? $counts : [],
            'top' => $available ? array_slice($cells, 0, 3) : [],
        ];
    }

    /** Interacciones de la publicacion; si la red no da el total, la suma de lo que si da. `null` = sin datos. */
    private function engagement(array $metrics): ?float
    {
        if (is_numeric($metrics[Metric::INTERACTIONS] ?? null)) {
            return (float) $metrics[Metric::INTERACTIONS];
        }

        $parts = array_filter(
            array_map(fn (string $k) => $metrics[$k] ?? null, [Metric::LIKES, Metric::COMMENTS, Metric::SHARES, Metric::SAVED]),
            'is_numeric',
        );

        return $parts === [] ? null : (float) array_sum($parts);
    }
}
