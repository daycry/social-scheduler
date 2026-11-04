<?php

namespace App\Analytics;

/** Prevent a missing day/account from being reported as a performance change. */
final class ComparisonQuality
{
    public static function forMetric(string $metric, array $current, array $previous, array $currentMetadata, ?array $previousMetadata): array
    {
        $status = match (true) {
            $previousMetadata === null => 'not_requested',
            $currentMetadata['coverage']['accounts'] === 0 => 'unsupported',
            $currentMetadata['coverage']['expected_account_days'] === 0 || $previousMetadata['coverage']['expected_account_days'] === 0 => 'not_due',
            $currentMetadata['coverage']['observed_account_days'] === 0 || $previousMetadata['coverage']['observed_account_days'] === 0 => 'unequal_coverage',
            self::sample($current, $metric) !== self::sample($previous, $metric) => 'unequal_coverage',
            $currentMetadata['coverage']['status'] === 'complete' && $previousMetadata['coverage']['status'] === 'complete' => 'complete',
            default => 'matched_partial',
        };

        return [
            'status' => $status, 'comparable' => in_array($status, ['complete', 'matched_partial'], true),
            'sample_basis' => in_array($metric, Metric::STOCKS, true) ? 'same_accounts_with_stock' : 'same_accounts_and_observation_counts',
            'previous_coverage' => $previousMetadata['coverage'] ?? null,
            'previous_freshness' => $previousMetadata['freshness'] ?? null,
        ];
    }

    public static function merge(array $items): array
    {
        $requested = array_filter($items, fn (array $item) => $item['status'] !== 'not_requested');
        $supported = array_filter($requested, fn (array $item) => $item['status'] !== 'unsupported');
        $statuses = array_column($supported, 'status');
        $status = match (true) {
            $requested === [] => 'not_requested',
            $supported === [] => 'unsupported',
            in_array('not_due', $statuses, true) => 'not_due',
            in_array('unequal_coverage', $statuses, true) => 'unequal_coverage',
            in_array('matched_partial', $statuses, true) => 'matched_partial',
            default => 'complete',
        };
        $previous = $requested === [] ? null : MetricCoverage::merge(array_map(fn (array $item) => [
            'coverage' => $item['previous_coverage'], 'freshness' => $item['previous_freshness'],
        ], array_values($requested)));

        return [
            'status' => $status, 'comparable' => in_array($status, ['complete', 'matched_partial'], true),
            'sample_basis' => $items[0]['sample_basis'] ?? 'same_accounts_and_observation_counts',
            'previous_coverage' => $previous['coverage'] ?? null, 'previous_freshness' => $previous['freshness'] ?? null,
        ];
    }

    public static function delta(int|float|null $current, int|float|null $previous, array $quality): array
    {
        return $quality['comparable'] ? Calculator::delta($current, $previous) : ['abs' => null, 'pct' => null];
    }

    private static function sample(array $accounts, string $metric): array
    {
        $sample = [];
        foreach ($accounts as $id => $series) {
            $values = array_filter($series, fn ($value) => $value !== null);
            if ($values !== []) {
                $sample[$id] = in_array($metric, Metric::STOCKS, true) ? 1 : count($values);
            }
        }
        ksort($sample);

        return $sample;
    }
}
