<?php

namespace App\Services\Studio;

use Illuminate\Validation\ValidationException;

final class StudioChartSpec
{
    public const MAX_SAFE = 9007199254740991;

    public const PALETTE = ['#7c3aed', '#0284c7', '#059669', '#d97706', '#dc2626', '#db2777', '#4f46e5', '#0891b2', '#65a30d', '#ea580c', '#9333ea', '#475569'];

    public static function normalize(mixed $value, string $path = 'chart'): array
    {
        self::object($value, ['binding', 'options', 'style'], $path);
        $binding = $value['binding'] ?? null;
        self::object($binding, ['dataset', 'category_column', 'series_columns', 'precision'], $path.'.binding');
        $dataset = $binding['dataset'] ?? null;
        self::object($dataset, ['id', 'revision', 'sha256'], $path.'.binding.dataset');
        foreach (['id', 'revision'] as $key) {
            self::positiveInteger($dataset[$key] ?? null, $path.'.binding.dataset.'.$key);
        }
        self::check(is_string($dataset['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $dataset['sha256']) === 1, $path.'.binding.dataset.sha256');
        self::identifier($binding['category_column'] ?? null, $path.'.binding.category_column');
        $series = $binding['series_columns'] ?? null;
        self::check(is_array($series) && array_is_list($series) && count($series) >= 1 && count($series) <= 12, $path.'.binding.series_columns');
        $seen = [$binding['category_column'] => true];
        foreach ($series as $index => $id) {
            self::identifier($id, $path.'.binding.series_columns.'.$index);
            self::check(! isset($seen[$id]), $path.'.binding.series_columns.'.$index);
            $seen[$id] = true;
        }
        $binding += ['precision' => 'safe'];
        self::check(in_array($binding['precision'], ['safe', 'approximate'], true), $path.'.binding.precision');

        $options = ['kind' => 'column', 'band_gap' => 0.2, 'series_gap' => 0.1, 'palette' => self::PALETTE, 'domain_min' => null, 'domain_max' => null, 'tick_count' => 5];
        if (array_key_exists('options', $value)) {
            self::object($value['options'], array_keys($options), $path.'.options');
            self::check(array_key_exists('kind', $value['options']), $path.'.options.kind');
            $options = $value['options'] + $options;
        }
        self::check(in_array($options['kind'], ['bar', 'column', 'bar-grouped', 'column-grouped', 'bar-stacked', 'column-stacked', 'bar-proportional', 'column-proportional', 'bar-multicolor', 'column-multicolor'], true), $path.'.options.kind');
        foreach (['band_gap', 'series_gap'] as $key) {
            self::number($options[$key], 0, 0.9, $path.'.options.'.$key);
        }
        self::number($options['tick_count'], 2, 21, $path.'.options.tick_count');
        self::check(is_int($options['tick_count']), $path.'.options.tick_count');
        self::check(is_array($options['palette']) && array_is_list($options['palette']) && count($options['palette']) >= 1 && count($options['palette']) <= 24, $path.'.options.palette');
        foreach ($options['palette'] as $color) {
            self::check(StudioDocument::color($color), $path.'.options.palette');
        }
        foreach (['domain_min', 'domain_max'] as $key) {
            if ($options[$key] !== null) {
                self::number($options[$key], -PHP_FLOAT_MAX, PHP_FLOAT_MAX, $path.'.options.'.$key);
            }
        }
        $proportional = str_ends_with($options['kind'], '-proportional');
        if ($proportional) {
            self::check(($options['domain_min'] === null || $options['domain_min'] == 0) && ($options['domain_max'] === null || $options['domain_max'] == 100), $path.'.options');
        } else {
            self::check(($options['domain_min'] === null || $options['domain_min'] <= 0) && ($options['domain_max'] === null || $options['domain_max'] >= 0), $path.'.options');
            self::check($options['domain_min'] === null || $options['domain_max'] === null || $options['domain_min'] < $options['domain_max'], $path.'.options');
        }
        self::check(preg_match('/-(grouped|stacked|proportional)$/D', $options['kind']) === 1 || count($series) === 1, $path.'.binding.series_columns');

        $style = ['title' => '', 'subtitle' => '', 'source' => '', 'x_title' => '', 'y_title' => '', 'fontFamily' => 'Arial', 'fontSize' => 16, 'fontWeight' => 'normal', 'fontStyle' => 'normal', 'text_color' => '#111827', 'axis_color' => '#64748b', 'grid_color' => '#e2e8f0', 'background' => '#ffffff', 'legend' => true, 'show_grid' => true, 'show_axis' => true, 'category_labels' => true, 'legend_position' => 'bottom', 'value_labels' => 'none', 'label_position' => 'outside', 'category_label_limit' => 24];
        if (array_key_exists('style', $value)) {
            self::object($value['style'], [...array_keys($style), 'font_id'], $path.'.style');
            $style = $value['style'] + $style;
        }
        foreach (['title', 'subtitle', 'source', 'x_title', 'y_title'] as $key) {
            self::check(is_string($style[$key]) && mb_check_encoding($style[$key], 'UTF-8') && mb_strlen($style[$key], 'UTF-8') <= 256 && preg_match('/[\x00-\x08\x0b-\x1f]/u', $style[$key]) === 0, $path.'.style.'.$key);
        }
        self::check(StudioDocument::validFont($style['fontFamily']), $path.'.style.fontFamily');
        if (array_key_exists('font_id', $style)) {
            self::positiveInteger($style['font_id'], $path.'.style.font_id');
        }
        self::number($style['fontSize'], 8, 72, $path.'.style.fontSize');
        foreach (['fontWeight' => ['normal', 'bold'], 'fontStyle' => ['normal', 'italic'], 'legend_position' => ['bottom', 'right'], 'value_labels' => ['none', 'raw', 'percentage'], 'label_position' => ['inside', 'outside']] as $key => $choices) {
            self::check(in_array($style[$key], $choices, true), $path.'.style.'.$key);
        }
        self::check($style['value_labels'] !== 'percentage' || $proportional, $path.'.style.value_labels');
        foreach (['text_color', 'axis_color', 'grid_color', 'background'] as $key) {
            self::check(StudioDocument::color($style[$key]), $path.'.style.'.$key);
        }
        foreach (['legend', 'show_grid', 'show_axis', 'category_labels'] as $key) {
            self::check(is_bool($style[$key]), $path.'.style.'.$key);
        }
        self::number($style['category_label_limit'], 1, 80, $path.'.style.category_label_limit');
        self::check(is_int($style['category_label_limit']), $path.'.style.category_label_limit');

        return ['binding' => $binding, 'options' => $options, 'style' => $style];
    }

    private static function object(mixed $value, array $allowed, string $path): void
    {
        self::check(is_array($value) && ($value === [] || ! array_is_list($value)), $path);
        self::check(array_diff(array_keys($value), $allowed) === [], $path);
    }

    private static function identifier(mixed $id, string $path): void
    {
        self::check(is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) === 1, $path);
    }

    private static function positiveInteger(mixed $value, string $path): void
    {
        self::check(is_int($value) && $value > 0 && $value <= self::MAX_SAFE, $path);
    }

    private static function number(mixed $value, float $min, float $max, string $path): void
    {
        self::check((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= $min && $value <= $max, $path);
    }

    private static function check(bool $valid, string $path): void
    {
        if (! $valid) {
            throw ValidationException::withMessages([$path => ['Configuración de gráfico no válida.']]);
        }
    }
}
