<?php

namespace App\Services\Studio;

final class StudioChartData
{
    private const MAX_BYTES = 2097152;

    private int $inputBytes;

    private int $jsonBytes;

    private string $budgetLabel;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(string $profile = 'inline')
    {
        [$this->inputBytes, $this->jsonBytes, $this->budgetLabel] = match ($profile) {
            'inline' => [self::MAX_BYTES, self::MAX_BYTES, '2 MiB'],
            'dataset' => [25000000, 52000000, '52000000 bytes'],
            default => $this->fail('Unknown chart data profile'),
        };
    }

    private function budget(int $bytes): void
    {
        if ($bytes > $this->jsonBytes) {
            $this->fail('Canonical table exceeds '.$this->budgetLabel);
        }
    }

    public function normalize(array $table): array
    {
        return $this->normalizeTable($table);
    }

    private function normalizeTable(array $table, int $csvHeaderRows = 0): array
    {
        $this->fields($table, ['columns', 'rows'], 'table');
        foreach (['columns', 'rows'] as $key) {
            if (! is_array($table[$key]) || ! array_is_list($table[$key])) {
                $this->fail($key.' must be a list');
            }
        }
        $width = count($table['columns']);
        if ($width < 1 || $width > 100 || count($table['rows']) > 1000) {
            $this->fail('Table requires 1–100 columns and at most 1000 rows');
        }
        $bytes = strlen('{"columns":[],"rows":[]}');
        $ids = [];
        foreach ($table['columns'] as $index => $column) {
            $path = 'column '.($index + 1);
            $this->fields($column, ['id', 'label'], $path);
            $this->id($column['id'], $ids, $path);
            $labelPath = $csvHeaderRows === 1 ? 'row 1, column '.($index + 1) : $path.' label';
            $this->text($column['label'], 500, $labelPath);
            $bytes += (int) ($index > 0) + strlen(json_encode($column, self::JSON_FLAGS));
            $this->budget($bytes);
        }
        $ids = [];
        foreach ($table['rows'] as $index => $row) {
            $path = 'row '.($index + 1 + $csvHeaderRows);
            $this->fields($row, ['id', 'values'], $path);
            $this->id($row['id'], $ids, $path);
            if (! is_array($row['values']) || ! array_is_list($row['values']) || count($row['values']) !== $width) {
                $this->fail($path.' has invalid width or values list');
            }
            $bytes += (int) ($index > 0) + strlen(json_encode(['id' => $row['id'], 'values' => []], self::JSON_FLAGS));
            $this->budget($bytes);
            foreach ($row['values'] as $col => $value) {
                $cell = $path.', column '.($col + 1);
                if (is_string($value)) {
                    $this->text($value, 10000, $cell);
                } elseif ($value !== null && ! is_int($value) && ! (is_float($value) && is_finite($value))) {
                    $this->fail($cell.' must be a string, finite number or null');
                }
                $bytes += (int) ($col > 0) + strlen(json_encode($value, self::JSON_FLAGS));
                $this->budget($bytes);
            }
        }

        return $table;
    }

    private function fields(mixed $value, array $keys, string $path): void
    {
        if (! is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value)) !== []) {
            $this->fail($path.' has missing or unknown fields');
        }
    }

    private function id(mixed $id, array &$seen, string $path): void
    {
        if (! is_string($id) || ! preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $id) || isset($seen[$id])) {
            $this->fail($path.' has invalid or duplicate id');
        }
        $seen[$id] = true;
    }

    private function text(mixed $value, int $limit, string $path): void
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit
            || preg_match('/[^\P{Cc}\n\t]/u', $value)) {
            $this->fail($path.' has invalid UTF-8, length or control characters');
        }
    }

    private function fail(string $message): never
    {
        throw new \InvalidArgumentException($message);
    }

    public function import(string $text, string $format = 'csv', bool $header = true): array
    {
        return $this->importChunks([$text], $format, $header);
    }

    /** The caller owns I/O; this codec never opens files or URLs. */
    public function importChunks(iterable $chunks, string $format = 'csv', bool $header = true): array
    {
        $delimiter = $this->delimiter($format);
        $records = [];
        $record = [];
        $value = '';
        $state = 'start';
        $endedRecord = false;
        $seen = false;
        $pendingCR = false;
        foreach ($this->characters($chunks) as $char) {
            $seen = true;
            $path = 'row '.(count($records) + 1).', column '.(count($record) + 1);
            if ($pendingCR) {
                if ($char !== "\n") {
                    $this->fail($path.': bare CR is not allowed');
                }
                $pendingCR = false;
            } elseif ($char === "\r") {
                $pendingCR = true;

                continue;
            }
            $endedRecord = false;
            if ($state === 'quoted') {
                if ($char === '"') {
                    $state = 'closed';
                } else {
                    $value .= $char;
                }
            } elseif ($state === 'closed' && $char === '"') {
                $value .= '"';
                $state = 'quoted';
            } elseif ($char === $delimiter || $char === "\n") {
                $this->text($value, $header && $records === [] ? 500 : 10000, $path);
                $record[] = $value;
                $value = '';
                $state = 'start';
                if (count($record) > 100) {
                    $this->fail($path.': exceeds 100 columns');
                }
                if ($char === "\n") {
                    $this->record($records, $record, $header);
                    $record = [];
                    $endedRecord = true;
                }
            } elseif ($state === 'closed') {
                $this->fail($path.': unexpected character after closing quote');
            } elseif ($char === '"') {
                if ($state !== 'start') {
                    $this->fail($path.': unexpected quote in unquoted field');
                }
                $state = 'quoted';
            } else {
                $value .= $char;
                $state = 'unquoted';
            }
            // No valid UTF-8 field can exceed four bytes per allowed codepoint.
            if (strlen($value) > 4 * ($header && $records === [] ? 500 : 10000)) {
                $this->fail($path.' has invalid UTF-8, length or control characters');
            }
        }
        if ($pendingCR) {
            $this->fail('row '.(count($records) + 1).', column '.(count($record) + 1).': bare CR is not allowed');
        }
        if (! $seen) {
            $this->fail('Input is empty');
        }
        if ($state === 'quoted') {
            $this->fail('row '.(count($records) + 1).', column '.(count($record) + 1).': unclosed quote');
        }
        if (! $endedRecord) {
            $this->text($value, $header && $records === [] ? 500 : 10000, 'row '.(count($records) + 1).', column '.(count($record) + 1));
            $record[] = $value;
            $this->record($records, $record, $header);
        }
        $labels = $header ? array_shift($records) : array_fill(0, count($records[0]), '');
        $table = ['columns' => [], 'rows' => []];
        foreach ($labels as $index => $label) {
            $table['columns'][] = ['id' => 'column_'.($index + 1), 'label' => $label];
        }
        foreach ($records as $index => $values) {
            $table['rows'][] = ['id' => 'row_'.($index + 1), 'values' => $values];
        }

        return $this->normalizeTable($table, (int) $header);
    }

    private function characters(iterable $chunks): \Generator
    {
        $bytes = 0;
        $prefix = '';
        $started = false;
        foreach ($chunks as $chunk) {
            if (! is_string($chunk)) {
                $this->fail('Input chunks must be strings');
            }
            $bytes += strlen($chunk);
            if ($bytes > $this->inputBytes) {
                $this->fail('Input must be valid UTF-8 and at most '.($this->inputBytes === self::MAX_BYTES ? '2 MiB' : '25000000 bytes'));
            }
            $length = strlen($chunk);
            for ($i = 0; $i < $length; $i++) {
                if (! $started) {
                    $prefix .= $chunk[$i];
                    if (strlen($prefix) < 3) {
                        continue;
                    }
                    $started = true;
                    if ($prefix !== "\xEF\xBB\xBF") {
                        for ($j = 0; $j < 3; $j++) {
                            yield $prefix[$j];
                        }
                    }
                    $prefix = '';
                } else {
                    yield $chunk[$i];
                }
            }
        }
        for ($i = 0; $i < strlen($prefix); $i++) {
            yield $prefix[$i];
        }
    }

    private function record(array &$records, array $record, bool $header): void
    {
        $index = count($records) + 1;
        if (count($record) > 100 || (isset($records[0]) && count($record) !== count($records[0]))) {
            $this->fail('row '.$index.': irregular width or too many columns');
        }
        if ($index > 1000 + (int) $header) {
            $this->fail('row '.$index.': exceeds 1000 data rows');
        }
        $records[] = $record;
    }

    private function delimiter(string $format): string
    {
        return match ($format) {
            'csv' => ',',
            'tsv' => "\t",
            default => $this->fail('Unsupported format: expected csv or tsv'),
        };
    }

    /**
     * Lexical interchange only: external spreadsheets may evaluate formula text.
     * CSV/TSV cannot preserve cell types or ids; null is encoded as an empty field.
     */
    public function exportChunks(array $table, string $format = 'csv', bool $header = true): iterable
    {
        $table = $this->normalize($table);
        $delimiter = $this->delimiter($format);
        if ($header) {
            yield $this->encodeRecord(array_column($table['columns'], 'label'), $delimiter);
        }
        foreach ($table['rows'] as $row) {
            yield $this->encodeRecord($row['values'], $delimiter);
        }
    }

    public function export(array $table, string $format = 'csv', bool $header = true): string
    {
        $output = '';
        foreach ($this->exportChunks($table, $format, $header) as $chunk) {
            $output .= $chunk;
        }

        return $output;
    }

    private function encodeRecord(array $values, string $delimiter): string
    {
        $cells = array_map(function ($value) use ($delimiter): string {
            $text = is_string($value) ? $value : ($value === null ? '' : json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            if (str_contains($text, $delimiter) || str_contains($text, '"') || str_contains($text, "\n") || str_starts_with($text, "\xEF\xBB\xBF")) {
                return '"'.str_replace('"', '""', $text).'"';
            }

            return $text;
        }, $values);

        return implode($delimiter, $cells)."\r\n";
    }
}
