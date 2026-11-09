<?php

namespace App\Services\Studio;

use App\Models\Studio\StudioDatasetRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class StudioDatasetActions
{
    public function __construct(private StudioDatasets $datasets) {}

    public function execute(int $userId, int $brandId, string $action, ?int $datasetId, array $input): array
    {
        $allowed = match ($action) {
            'list' => ['query', 'page', 'per_page'],
            'create' => ['name', 'table'],
            'replace' => ['name', 'table', 'revision'],
            'history' => ['page', 'per_page'],
            'rows' => ['revision', 'offset', 'limit'],
            'export' => ['revision', 'format', 'mode', 'header'],
            'import' => $datasetId === null ? ['name', 'format', 'header', 'file', 'text'] : ['name', 'format', 'header', 'file', 'text', 'revision'],
            default => [],
        };
        StudioDatasetInput::keys($input, $allowed);
        if ($action === 'export') {
            $export = $this->export($userId, $brandId, $datasetId, $input);
            $query = ['revision' => $export['revision'], 'format' => $export['format']] + array_intersect_key($input, ['mode' => true, 'header' => true]);

            return array_intersect_key($export, array_flip(['dataset', 'revision', 'sha256', 'format'])) + ['download_url' => route('api.studio.datasets.export', ['brand' => $brandId, 'dataset' => $datasetId] + $query, false)];
        }
        if ($action === 'import') {
            $name = StudioDatasetInput::text($input, 'name', 255);
            $revision = $datasetId === null ? null : StudioDatasetInput::integer($input, 'revision');
            if ($datasetId !== null) {
                $this->datasets->present($userId, $brandId, $datasetId);
            } else {
                $this->datasets->listing($userId, $brandId, '', 1, 1);
            }
            $format = StudioDatasetInput::text($input, 'format', 4);
            if (! in_array($format, ['csv', 'tsv', 'json'], true)) {
                StudioDatasetInput::fail('format', 'Expected csv, tsv or json.');
            }
            if ($format === 'json' && array_key_exists('header', $input)) {
                StudioDatasetInput::fail('header', 'JSON does not accept header.');
            }
            $header = StudioDatasetInput::boolean($input, 'header');
            $max = $format === 'json' ? 52000000 : 25000000;
            if (array_key_exists('file', $input) === array_key_exists('text', $input)) {
                StudioDatasetInput::fail('file', 'Provide exactly one upload or MCP text.');
            }
            $file = $input['file'] ?? null;
            if (array_key_exists('file', $input)) {
                if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() > $max) {
                    StudioDatasetInput::fail('file', 'Invalid upload or byte limit exceeded.');
                }
                $stream = fopen($file->getRealPath(), 'rb');
                if ($stream === false) {
                    StudioDatasetInput::fail('file', 'Unreadable upload.');
                }
            } else {
                $text = $input['text'];
                if (! is_string($text) || strlen($text) > $max) {
                    StudioDatasetInput::fail('text', 'Invalid text or byte limit exceeded.');
                }
                $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
                fwrite($stream, $text);
                rewind($stream);
            }
            try {
                if ($format === 'json') {
                    $bytes = stream_get_contents($stream, $max + 1);
                    if (strlen($bytes) > $max) {
                        StudioDatasetInput::fail('file', 'Byte limit exceeded.');
                    }
                    $table = StudioDatasetInput::table(StudioDatasetInput::objectJson($bytes, 'table'));
                } else {
                    $chunks = static function () use ($stream): \Generator {
                        while (! feof($stream)) {
                            $chunk = fread($stream, 65536);
                            if ($chunk === false) {
                                StudioDatasetInput::fail('file', 'Unreadable upload.');
                            }
                            yield $chunk;
                        }
                    };
                    try {
                        $table = (new StudioChartData('dataset'))->importChunks($chunks(), $format, $header);
                    } catch (InvalidArgumentException $e) {
                        StudioDatasetInput::fail($file === null ? 'text' : 'file', $e->getMessage());
                    }
                }
            } finally {
                fclose($stream);
            }

            return $this->execute($userId, $brandId, $datasetId === null ? 'create' : 'replace', $datasetId, ['name' => $name, 'table' => $table] + ($revision === null ? [] : ['revision' => $revision]));
        }
        if ($action === 'rows') {
            $snapshot = $this->authorizedRevisionInfo($userId, $brandId, $datasetId, array_key_exists('revision', $input) ? StudioDatasetInput::integer($input, 'revision') : null);
            $offset = StudioDatasetInput::integer($input, 'offset', 0, 1000, 0);
            $limit = StudioDatasetInput::integer($input, 'limit', 1, 100, 50);
            $table = $this->datasets->read($userId, $brandId, $datasetId, $snapshot['revision']);
            $rows = [];
            $bytes = 2;
            foreach (array_slice($table['rows'], $offset, $limit) as $row) {
                $size = strlen(json_encode($row, StudioDatasetInput::JSON_FLAGS)) + ($rows === [] ? 0 : 1);
                if ($bytes + $size > 6 * 1024 * 1024) {
                    break;
                }
                $bytes += $size;
                $rows[] = $row;
            }
            $next = $offset + count($rows);

            return ['dataset' => $snapshot['dataset'], 'revision' => $snapshot['revision'], 'sha256' => $snapshot['sha256'], 'columns' => $table['columns'], 'rows' => $rows,
                'pagination' => ['offset' => $offset, 'limit' => $limit, 'total' => count($table['rows']), 'next_offset' => $next < count($table['rows']) ? $next : null]];
        }
        if ($action === 'list') {
            $page = StudioDatasetInput::integer($input, 'page', default: 1);
            $perPage = StudioDatasetInput::integer($input, 'per_page', max: 100, default: 25);

            return $this->page($this->datasets->listing($userId, $brandId, StudioDatasetInput::text($input, 'query', 120, ''), $page, $perPage), 'datasets');
        }
        if ($action === 'get') {
            return ['dataset' => $this->datasets->present($userId, $brandId, $datasetId)];
        }
        if ($action === 'history') {
            return $this->page($this->datasets->history($userId, $brandId, $datasetId, StudioDatasetInput::integer($input, 'page', default: 1), StudioDatasetInput::integer($input, 'per_page', max: 100, default: 25)), 'revisions');
        }
        if (in_array($action, ['archive', 'restore'], true)) {
            $dataset = $this->datasets->{$action}($userId, $brandId, $datasetId);

            return ['dataset' => $this->datasets->present($userId, $brandId, $dataset)];
        }
        if (in_array($action, ['create', 'replace'], true)) {
            $name = StudioDatasetInput::text($input, 'name', 255);
            $table = StudioDatasetInput::table($input['table'] ?? null);
            $revision = $action === 'replace' ? StudioDatasetInput::integer($input, 'revision') : null;
            try {
                $dataset = $action === 'create' ? $this->datasets->create($userId, $brandId, $name, $table) : $this->datasets->replace($userId, $brandId, $datasetId, $revision, $name, $table);
            } catch (InvalidArgumentException $e) {
                StudioDatasetInput::fail(str_contains($e->getMessage(), 'name') ? 'name' : 'table', $e->getMessage());
            }

            return ['dataset' => $this->datasets->present($userId, $brandId, $dataset)];
        }
        StudioDatasetInput::fail('action', 'Unsupported dataset action.');
    }

    private function page(LengthAwarePaginator $page, string $key): array
    {
        return [$key => $page->items(), 'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null]];
    }

    public function authorizedRevisionInfo(int $userId, int $brandId, int $datasetId, ?int $revision = null): array
    {
        $dataset = $this->datasets->present($userId, $brandId, $datasetId);
        $snapshot = StudioDatasetRevision::withoutUserScope()->where('user_id', $userId)->where('brand_id', $brandId)->where('dataset_id', $datasetId)->where('revision', $revision ?? $dataset['revision'])
            ->first(['revision', 'sha256', 'name', 'row_count', 'column_count', 'json_bytes']);
        abort_unless($snapshot, 404);

        return ['dataset' => $dataset] + $snapshot->toArray();
    }

    public function export(int $userId, int $brandId, int $datasetId, array $input): array
    {
        StudioDatasetInput::keys($input, ['revision', 'format', 'mode', 'header']);
        $format = StudioDatasetInput::text($input, 'format', 4, 'csv');
        if (! in_array($format, ['csv', 'tsv', 'json'], true)) {
            StudioDatasetInput::fail('format', 'Expected csv, tsv or json.');
        }
        if ($format === 'json' && (array_key_exists('mode', $input) || array_key_exists('header', $input))) {
            StudioDatasetInput::fail(array_key_exists('mode', $input) ? 'mode' : 'header', 'JSON does not accept mode or header.');
        }
        $mode = array_key_exists('mode', $input) ? StudioDatasetInput::text($input, 'mode', 11) : null;
        if ($mode !== null && ! in_array($mode, ['text', 'spreadsheet'], true)) {
            StudioDatasetInput::fail('mode', 'Expected spreadsheet or text.');
        }
        $header = StudioDatasetInput::boolean($input, 'header');
        $snapshot = $this->authorizedRevisionInfo($userId, $brandId, $datasetId, array_key_exists('revision', $input) ? StudioDatasetInput::integer($input, 'revision') : null);
        $table = $this->datasets->read($userId, $brandId, $datasetId, $snapshot['revision']);
        $chunks = (new StudioTableExport)->chunks($table, $format, $mode, $header);

        return array_intersect_key($snapshot, array_flip(['dataset', 'revision', 'sha256', 'json_bytes'])) + ['format' => $format, 'chunks' => $chunks];
    }
}
