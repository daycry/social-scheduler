<?php

namespace App\Analytics;

use App\Models\SocialAccount;
use App\Services\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Read-only observation metadata. A collection attempt is not proof that a stored value was refreshed. */
final class MetricCoverage
{
    public static function forMetric(string $metric, Collection $accounts, Collection $snapshots, Period $period): array
    {
        // Historical values from disconnected accounts remain in totals. Include that same population,
        // while not expecting data from an unused disconnected account with no snapshots in this period.
        $participating = $snapshots->pluck('social_account_id')->flip();
        $accounts = $accounts->filter(fn (SocialAccount $a) => $a->status !== SocialAccount::STATUS_DISCONNECTED || $participating->has($a->id));
        $supported = $accounts->filter(fn (SocialAccount $a) => in_array($metric, AnalyticsService::SUPPORTED[$a->network->value] ?? [], true));
        $expected = $observed = $present = $withData = $lagging = 0;
        $latestAttempt = $latestData = $latestExpected = null;
        $latestByAccount = [];
        $reasons = [];
        $rowsByAccount = $snapshots->groupBy('social_account_id');

        foreach ($supported as $account) {
            $flag = (array) ($account->meta['analytics_unavailable'] ?? []);
            if (isset($flag['reason']) && in_array($metric, (array) ($flag['metrics'] ?? []), true)) {
                $reasons[] = 'unavailable_'.$flag['reason'];
            }
            $closed = Days::closed($account->network)->format('Y-m-d');
            $dates = array_values(array_filter($period->dates(), fn (string $date) => $date <= $closed));
            $expected += count($dates);
            if ($dates === []) {
                continue;
            }
            $lastExpected = $dates[count($dates) - 1];
            $latestExpected = self::latest($latestExpected, $lastExpected);
            $lastData = null;
            foreach ($rowsByAccount->get($account->id, collect()) as $row) {
                $date = substr((string) $row->date, 0, 10);
                if (! in_array($date, $dates, true)) {
                    continue;
                }
                $present++;
                if ($row->collected_at !== null) {
                    $attempt = CarbonImmutable::instance($row->collected_at)->utc()->toIso8601String();
                    $latestAttempt = self::latest($latestAttempt, $attempt);
                }
                if (is_numeric($row->metrics[$metric] ?? null)) {
                    $observed++;
                    $lastData = self::latest($lastData, $date);
                    $latestData = self::latest($latestData, $date);
                }
            }
            $latestByAccount[] = $lastData;
            $withData += $lastData !== null ? 1 : 0;
            $lagging += $lastData === null || $lastData < $lastExpected ? 1 : 0;
        }

        return [
            'coverage' => [
                'unit' => 'account_days',
                // Razon explicita cuando la red no da la metrica en este entorno (p. ej. analitica de Pinterest en sandbox): no es un `no_data` eterno.
                'status' => $observed === 0 && $expected > 0 && $reasons !== [] ? $reasons[0] : self::status($supported->count(), $expected, $observed),
                'reason' => $reasons[0] ?? null,
                'accounts' => $supported->count(), 'accounts_with_data' => $withData,
                'unsupported_accounts' => $accounts->count() - $supported->count(),
                'expected_account_days' => $expected, 'snapshot_account_days' => $present,
                'observed_account_days' => $observed, 'missing_account_days' => max(0, $expected - $observed),
            ],
            'freshness' => [
                'basis' => 'snapshot_attempts', 'latest_attempt_at' => $latestAttempt,
                'latest_data_date' => $latestData,
                'oldest_latest_data_date' => $latestByAccount === [] || in_array(null, $latestByAccount, true) ? null : min($latestByAccount),
                'latest_expected_date' => $latestExpected, 'lagging_accounts' => $lagging,
                'is_stale' => $expected === 0 ? null : $lagging > 0,
                'data_updated_at' => null,
            ],
        ];
    }

    /** Combine already scoped metadata across brands or networks, never substituting missing values with metric zeros. */
    public static function merge(array $items): array
    {
        $coverage = ['unit' => 'account_days', 'accounts' => 0, 'accounts_with_data' => 0, 'unsupported_accounts' => 0,
            'expected_account_days' => 0, 'snapshot_account_days' => 0, 'observed_account_days' => 0, 'missing_account_days' => 0, 'reason' => null];
        $freshness = ['basis' => 'snapshot_attempts', 'latest_attempt_at' => null, 'latest_data_date' => null,
            'oldest_latest_data_date' => null, 'latest_expected_date' => null, 'lagging_accounts' => 0, 'is_stale' => null, 'data_updated_at' => null];
        $oldest = [];
        foreach ($items as $item) {
            foreach ($coverage as $key => $value) {
                if ($key === 'reason') {
                    $coverage[$key] ??= $item['coverage'][$key] ?? null;
                } elseif ($key !== 'unit') {
                    $coverage[$key] += $item['coverage'][$key];
                }
            }
            foreach (['latest_attempt_at', 'latest_data_date', 'latest_expected_date'] as $key) {
                $freshness[$key] = self::latest($freshness[$key], $item['freshness'][$key]);
            }
            $freshness['lagging_accounts'] += $item['freshness']['lagging_accounts'];
            if ($item['coverage']['expected_account_days'] > 0) {
                $oldest[] = $item['freshness']['oldest_latest_data_date'];
            }
        }
        $coverage['status'] = $coverage['observed_account_days'] === 0 && $coverage['expected_account_days'] > 0 && $coverage['reason'] !== null
            ? $coverage['reason']
            : self::status($coverage['accounts'], $coverage['expected_account_days'], $coverage['observed_account_days']);
        $freshness['oldest_latest_data_date'] = $oldest === [] || in_array(null, $oldest, true) ? null : min($oldest);
        $freshness['is_stale'] = $coverage['expected_account_days'] === 0 ? null : $freshness['lagging_accounts'] > 0;

        return ['coverage' => $coverage, 'freshness' => $freshness];
    }

    private static function status(int $accounts, int $expected, int $observed): string
    {
        return match (true) {
            $accounts === 0 => 'unsupported',
            $expected === 0 => 'not_due',
            $observed === 0 => 'no_data',
            $observed < $expected => 'partial',
            default => 'complete',
        };
    }

    private static function latest(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }
}
