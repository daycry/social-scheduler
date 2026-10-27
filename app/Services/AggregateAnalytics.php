<?php

namespace App\Services;

use App\Analytics\Calculator;
use App\Analytics\Metric;
use App\Analytics\Period;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Analitica «Todas las marcas» (C-03b): suma lo que `AnalyticsService::summary()` calcula para cada marca del usuario.
 * No relee snapshots por su cuenta: cada marca usa las MISMAS consultas acotadas de la paridad 2 (un par de consultas
 * por marca y periodo, sin hidratar publicaciones), asi el agregado y el dashboard por marca nunca discrepan.
 *
 * Reglas: una metrica es la suma de las marcas CON dato y `null` ("no disponible", nunca 0) si ninguna lo tiene. Los dias
 * son dias UTC como los snapshots. «Acumulado» (seguidores, videos, publicaciones) es la suma del ultimo valor de cada
 * cuenta: no son usuarios unicos (una misma persona puede seguir varias redes).
 */
class AggregateAnalytics
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    /**
     * @return array{period: array<string, mixed>, comparison: ?array<string, mixed>, networks: list<array<string, mixed>>, cards: list<array<string, mixed>>, brands: list<array<string, mixed>>}
     */
    public function summary(User $user, Period $period, ?Period $compare = null): array
    {
        $minutes = (int) config('social.analytics.all_brands_cache_minutes', 5);
        $compute = fn (): array => $this->compute($user, $period, $compare);

        if ($minutes <= 0) {
            return $compute();
        }

        $key = sprintf('all-brands:%d:%s:%s:%s', $user->id, $period->from->format('Y-m-d'), $period->to->format('Y-m-d'), $compare ? $compare->from->format('Y-m-d').'/'.$compare->to->format('Y-m-d') : 'none');

        return Cache::remember($key, now()->addMinutes($minutes), $compute);
    }

    /** @return array<string, mixed> */
    private function compute(User $user, Period $period, ?Period $compare): array
    {
        $brands = Brand::withoutUserScope()->where('user_id', $user->id)->orderBy('name')->orderBy('id')->get();
        $summaries = [];

        foreach ($brands as $brand) {
            $summaries[$brand->id] = $this->analytics->summary($user, $brand, $period, $compare);
        }

        $networks = [];
        foreach ($summaries as $s) {
            foreach ($s['networks'] as $n) {
                $networks[$n['value']] ??= ['value' => $n['value'], 'label' => $n['label'], 'accounts' => 0];
                $networks[$n['value']]['accounts'] += $n['accounts'];
            }
        }
        $networks = collect($networks)->sortBy('label')->values()->all();

        $cards = [];
        foreach (AnalyticsService::CARDS as $metric => $label) {
            $cards[] = $this->card($metric, $label, $summaries, $compare !== null);
        }

        $rows = [];
        foreach ($brands as $brand) {
            $s = $summaries[$brand->id];
            $rows[] = [
                'id' => $brand->id,
                'name' => $brand->name,
                'networks' => count($s['networks']),
                'values' => collect($s['cards'])->mapWithKeys(fn (array $c) => [$c['key'] => $c['total']])->all(),
                'previous' => collect($s['cards'])->mapWithKeys(fn (array $c) => [$c['key'] => $c['previous']])->all(),
            ];
        }

        return [
            'period' => $period->toArray(),
            'comparison' => $compare?->toArray(),
            'networks' => $networks,
            'cards' => $cards,
            'brands' => $rows,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $summaries  resumen de cada marca por id
     * @return array<string, mixed>
     */
    private function card(string $metric, string $label, array $summaries, bool $comparing): array
    {
        $total = null;
        $previous = null;
        $byNetwork = [];
        $timeline = [];

        foreach ($summaries as $s) {
            $card = collect($s['cards'])->firstWhere('key', $metric);
            if ($card === null) {
                continue;
            }

            $total = self::add($total, $card['total']);
            $previous = self::add($previous, $card['previous']);

            foreach ($card['by_network'] as $n) {
                $byNetwork[$n['network']] ??= ['network' => $n['network'], 'label' => $n['label'], 'value' => null, 'previous' => null];
                $byNetwork[$n['network']]['value'] = self::add($byNetwork[$n['network']]['value'], $n['value']);
                $byNetwork[$n['network']]['previous'] = self::add($byNetwork[$n['network']]['previous'], $n['previous']);
            }

            foreach ($card['series'] as $point) {
                $row = &$timeline[$point['date']];
                $row ??= ['date' => $point['date'], 'total' => null, 'values' => []];
                $row['total'] = self::add($row['total'], $point['total']);
                foreach ($point['values'] as $net => $v) {
                    $row['values'][$net] = self::add($row['values'][$net] ?? null, $v);
                }
                unset($row);
            }
        }

        $byNetwork = collect($byNetwork)->sortBy('label')->map(fn (array $n) => $n + [
            'delta_pct' => Calculator::delta($n['value'], $n['previous'])['pct'], 'available' => $n['value'] !== null,
        ])->values()->all();
        ksort($timeline);
        $delta = Calculator::delta($total, $previous);

        return [
            'key' => $metric, 'label' => $label, 'kind' => in_array($metric, Metric::STOCKS, true) ? 'stock' : 'flow',
            'total' => $total, 'previous' => $comparing ? $previous : null, 'delta_abs' => $delta['abs'], 'delta_pct' => $delta['pct'],
            'available' => $total !== null, 'by_network' => $byNetwork, 'series' => array_values($timeline),
        ];
    }

    /** Suma con `null` = «no disponible»: solo es `null` si ambos lo son. */
    private static function add(int|float|null $a, int|float|null $b): int|float|null
    {
        return $b === null ? $a : ($a ?? 0) + $b;
    }
}
