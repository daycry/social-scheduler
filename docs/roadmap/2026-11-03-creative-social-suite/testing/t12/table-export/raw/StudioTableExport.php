<?php

namespace App\Services\Studio;

final class StudioTableExport
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** Validate before the caller sends headers; the returned iterator owns no I/O. */
    public function chunks(array $table, string $format = 'csv', ?string $mode = null, bool $header = true): iterable
    {
        if (! in_array($format, ['csv', 'tsv', 'json'], true)) {
            throw new \InvalidArgumentException('Unsupported format: expected csv, tsv or json');
        }
        if (($format === 'json' && ($mode !== null || ! $header))
            || ($format !== 'json' && ! in_array($mode, [null, 'spreadsheet', 'text'], true))) {
            throw new \InvalidArgumentException('Incompatible export mode or header');
        }
        $table = (new StudioChartData('dataset'))->normalize($table);
        if ($format === 'json') {
            return $this->jsonRecords($table);
        }
        if ($mode === 'text') {
            return (new StudioChartData('dataset'))->exportChunks($table, $format, $header);
        }

        return $this->records($table, $format === 'tsv' ? "\t" : ',', $header);
    }

    private function jsonRecords(array $table): \Generator
    {
        yield '{"columns":[';
        foreach ($table['columns'] as $index => $column) {
            yield ($index > 0 ? ',' : '').json_encode($column, self::JSON_FLAGS);
        }
        yield '],"rows":[';
        foreach ($table['rows'] as $index => $row) {
            yield ($index > 0 ? ',' : '').json_encode($row, self::JSON_FLAGS);
        }
        yield ']}';
    }

    private function records(array $table, string $delimiter, bool $header): \Generator
    {
        if ($header) {
            yield $this->record(array_column($table['columns'], 'label'), $delimiter);
        }
        foreach ($table['rows'] as $row) {
            yield $this->record($row['values'], $delimiter);
        }
    }

    private function record(array $values, string $delimiter): string
    {
        $fields = [];
        foreach ($values as $value) {
            $text = is_string($value) ? "'".$value : ($value === null ? '' : json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            $fields[] = str_contains($text, $delimiter) || str_contains($text, '"') || str_contains($text, "\n")
                ? '"'.str_replace('"', '""', $text).'"' : $text;
        }

        return implode($delimiter, $fields)."\r\n";
    }
}
