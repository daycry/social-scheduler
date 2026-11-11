<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class StudioCharts
{
    /** Validation-local metadata and selections; never retain private tables on the service. */
    public function validate(User $user, Brand $brand, array $document): void
    {
        abort_unless((int) $brand->user_id === (int) $user->id, 404);
        $references = [];
        $pages = [];
        $walk = function (array $elements, int $page, string $path) use (&$walk, &$references, &$pages): void {
            foreach ($elements as $index => $element) {
                $p = $path.'.'.$index;
                if (($element['type'] ?? null) === 'chart') {
                    $spec = StudioChartSpec::normalize($element['chart'] ?? null, $p.'.chart');
                    $ref = $spec['binding']['dataset'];
                    $key = $ref['id'].':'.$ref['revision'].':'.$ref['sha256'];
                    $references[$key]['ref'] = $ref;
                    // Style does not affect cell validation. Same reference/selection/config is visited once.
                    $selection = json_encode([$spec['binding'], $spec['options']['kind'], $spec['options']['domain_min'], $spec['options']['domain_max']], JSON_THROW_ON_ERROR);
                    $references[$key]['selections'][$selection] ??= [$spec, $p.'.chart'];
                    $pages[$page]['charts'][] = [$key, count($spec['binding']['series_columns'])];
                } else {
                    $pages[$page]['ordinary'] = ($pages[$page]['ordinary'] ?? 0) + 1;
                    if (($element['type'] ?? null) === 'group') {
                        $walk($element['elements'], $page, $p.'.elements');
                    }
                }
            }
        };
        foreach ($document['pages'] ?? [] as $index => $page) {
            $walk($page['elements'] ?? [], $index, 'document.pages.'.$index.'.elements');
        }
        self::check(count($references) <= 32, 'document', 'Demasiadas referencias de datos.');
        $bytes = 0;
        $actions = app(StudioDatasetActions::class);
        foreach ($references as &$entry) {
            $ref = $entry['ref'];
            $metadata = $actions->authorizedRevisionInfo((int) $user->id, (int) $brand->id, $ref['id'], $ref['revision']);
            self::check(hash_equals($metadata['sha256'], $ref['sha256']), 'document', 'La huella de la revisión no coincide.');
            $entry['rows'] = (int) $metadata['row_count'];
            $bytes += (int) $metadata['json_bytes'];
        }
        unset($entry);
        self::check($bytes <= 64000000, 'document', 'El documento supera el presupuesto de datos.');
        $total = 0;
        foreach ($pages as $index => $page) {
            $cost = $page['ordinary'] ?? 0;
            foreach ($page['charts'] ?? [] as [$key, $series]) {
                $rows = $references[$key]['rows'];
                $cost += 100 + $rows + 2 * $rows * $series;
            }
            self::check($cost <= 50000, 'document.pages.'.$index, 'La página supera el presupuesto de gráficos.');
            $total += $cost;
        }
        self::check($total <= 200000, 'document', 'El documento supera el presupuesto de gráficos.');
        foreach ($references as $entry) {
            $ref = $entry['ref'];
            $table = app(StudioDatasets::class)->read((int) $user->id, (int) $brand->id, $ref['id'], $ref['revision']);
            foreach ($entry['selections'] as [$spec, $path]) {
                $this->selection($table, $spec, $path);
            }
            unset($table);
        }
    }

    private function selection(array $table, array $spec, string $path): void
    {
        $binding = $spec['binding'];
        $options = $spec['options'];
        $columns = array_column($table['columns'], null, 'id');
        foreach ([$binding['category_column'], ...$binding['series_columns']] as $id) {
            self::check(isset($columns[$id]), $path.'.binding', 'La columna no existe en esta revisión.');
        }
        $indices = array_flip(array_column($table['columns'], 'id'));
        $series = array_map(fn ($id) => $indices[$id], $binding['series_columns']);
        $proportional = str_ends_with($options['kind'], '-proportional');
        $stacked = str_ends_with($options['kind'], '-stacked');
        $min = 0;
        $max = 0;
        foreach ($table['rows'] as $row) {
            $positive = 0;
            $negative = 0;
            foreach ($series as $index) {
                $value = $row['values'][$index];
                if ($value === null) {
                    continue;
                }
                self::check((is_int($value) || is_float($value)) && is_finite((float) $value), $path.'.binding.series_columns', 'La serie debe contener números o nulos, sin convertir texto.');
                self::check($binding['precision'] === 'approximate' || abs($value) <= StudioChartSpec::MAX_SAFE, $path.'.binding.precision', 'La magnitud exige aproximación explícita.');
                self::check(! $proportional || $value >= 0, $path.'.options.kind', 'El gráfico proporcional no admite negativos.');
                if ($stacked) {
                    if ($value < 0) {
                        $negative += $value;
                    } else {
                        $positive += $value;
                    }
                    self::check(is_finite((float) $negative) && is_finite((float) $positive), $path.'.options', 'Acumulado no finito.');
                    $min = min($min, $negative);
                    $max = max($max, $positive);
                } else {
                    $min = min($min, $value);
                    $max = max($max, $value);
                }
            }
        }
        if (! $proportional) {
            self::check(($options['domain_min'] === null || $options['domain_min'] <= $min) && ($options['domain_max'] === null || $options['domain_max'] >= $max), $path.'.options', 'El dominio debe incluir cero y todos los extremos.');
        }
    }

    private static function check(bool $condition, string $path, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$path => [$message]]);
        }
    }
}
