<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\Studio\StudioDataset;
use App\Models\Studio\StudioDatasetRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

final class StudioDatasets
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function replace(int $userId, int $brandId, int|StudioDataset $dataset, int $expectedRevision, string $name, array $table): StudioDataset
    {
        $this->ownTransaction();
        $this->owned($userId, $brandId, $dataset, true);
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('Expected revision must be positive.');
        }
        $name = $this->name($name);
        $table = (new StudioChartData('dataset'))->normalize($table);
        $path = null;
        try {
            return DB::transaction(function () use ($userId, $brandId, $dataset, $expectedRevision, $name, $table, &$path) {
                $this->reserveWriter($dataset);
                $current = $this->owned($userId, $brandId, $dataset, true, true);
                abort_unless($current->revision === $expectedRevision, 409, 'Dataset revision conflict.');
                $current->fill(['name' => $name, 'revision' => $current->revision + 1, 'row_count' => count($table['rows']), 'column_count' => count($table['columns'])])->save();
                $this->snapshot($current, $table, $path);

                return $current;
            });
        } catch (Throwable $error) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    public function read(int $userId, int $brandId, int|StudioDataset $dataset, ?int $revision = null): array
    {
        $current = $this->owned($userId, $brandId, $dataset);
        $number = $revision ?? $current->revision;
        abort_if($number < 1, 404);
        $snapshot = StudioDatasetRevision::withoutUserScope()->where('user_id', $userId)->where('brand_id', $brandId)->where('dataset_id', $current->id)->where('revision', $number)->first();
        abort_unless($snapshot, 404);
        try {
            $prefix = 'studio-datasets/'.$userId.'/'.$brandId.'/';
            if (! preg_match('~^'.preg_quote($prefix, '~').'[a-f0-9-]{36}\.json$~D', $snapshot->path)
                || $snapshot->json_bytes < 1 || $snapshot->json_bytes > 52000000
                || ! preg_match('/^[a-f0-9]{64}$/D', $snapshot->sha256)) {
                throw new RuntimeException('Invalid snapshot metadata.');
            }
            $disk = Storage::disk('local');
            if ($disk->size($snapshot->path) !== $snapshot->json_bytes) {
                throw new RuntimeException('Invalid snapshot size.');
            }
            $stream = $disk->readStream($snapshot->path);
            if (! is_resource($stream)) {
                throw new RuntimeException('Snapshot unavailable.');
            }
            try {
                $bytes = stream_get_contents($stream, $snapshot->json_bytes + 1);
            } finally {
                fclose($stream);
            }
            if (! is_string($bytes) || strlen($bytes) !== $snapshot->json_bytes || ! hash_equals($snapshot->sha256, hash('sha256', $bytes))) {
                throw new RuntimeException('Invalid snapshot digest.');
            }
            $decoded = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
            unset($bytes);
            if (! $decoded instanceof \stdClass) {
                throw new RuntimeException('Invalid snapshot table.');
            }
            $table = get_object_vars($decoded);
            unset($decoded);
            foreach (['columns', 'rows'] as $collection) {
                if (! isset($table[$collection]) || ! is_array($table[$collection])) {
                    throw new RuntimeException('Invalid snapshot collection.');
                }
                foreach ($table[$collection] as $index => $entry) {
                    if (! $entry instanceof \stdClass) {
                        throw new RuntimeException('Invalid snapshot entry.');
                    }
                    $table[$collection][$index] = get_object_vars($entry);
                }
            }
            $table = (new StudioChartData('dataset'))->normalize($table);
            if (count($table['rows']) !== $snapshot->row_count || count($table['columns']) !== $snapshot->column_count) {
                throw new RuntimeException('Invalid snapshot shape.');
            }

            return $table;
        } catch (Throwable) {
            abort(503, 'Dataset snapshot unavailable.');
        }
    }

    public function archive(int $userId, int $brandId, int|StudioDataset $dataset): StudioDataset
    {
        return $this->setArchived($userId, $brandId, $dataset, true);
    }

    public function restore(int $userId, int $brandId, int|StudioDataset $dataset): StudioDataset
    {
        return $this->setArchived($userId, $brandId, $dataset, false);
    }

    public function listing(int $userId, int $brandId, string $search = '', int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        $this->brand($userId, $brandId);
        $query = StudioDataset::withoutUserScope()->where('user_id', $userId)->where('brand_id', $brandId)->whereNull('archived_at');
        if ($search !== '') {
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
        }

        return $query->orderByDesc('id')->paginate(max(1, min(100, $perPage)), $this->metadataColumns(), 'page', max(1, $page));
    }

    public function history(int $userId, int $brandId, int|StudioDataset $dataset, int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        $current = $this->owned($userId, $brandId, $dataset);

        return StudioDatasetRevision::withoutUserScope()->where('user_id', $userId)->where('brand_id', $brandId)->where('dataset_id', $current->id)
            ->orderByDesc('revision')->paginate(max(1, min(100, $perPage)), ['id', 'dataset_id', 'brand_id', 'revision', 'name', 'row_count', 'column_count', 'sha256', 'json_bytes', 'created_at'], 'page', max(1, $page));
    }

    public function present(int $userId, int $brandId, int|StudioDataset $dataset): array
    {
        return $this->owned($userId, $brandId, $dataset)->only($this->metadataColumns());
    }

    private function metadataColumns(): array
    {
        return ['id', 'brand_id', 'name', 'revision', 'row_count', 'column_count', 'archived_at', 'created_at', 'updated_at'];
    }

    private function owned(int $userId, int $brandId, int|StudioDataset $dataset, bool $active = false, bool $lock = false): StudioDataset
    {
        $this->brand($userId, $brandId);
        $query = StudioDataset::withoutUserScope()->where('user_id', $userId)->where('brand_id', $brandId)->whereKey($dataset instanceof StudioDataset ? $dataset->getKey() : $dataset);
        if ($active) {
            $query->whereNull('archived_at');
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $current = $query->first();
        abort_unless($current, 404);

        return $current;
    }

    private function reserveWriter(int|StudioDataset $dataset): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('studio_datasets')->where('id', $dataset instanceof StudioDataset ? $dataset->getKey() : $dataset)->update(['id' => DB::raw('id')]);
        }
    }

    private function setArchived(int $userId, int $brandId, int|StudioDataset $dataset, bool $archive): StudioDataset
    {
        $this->ownTransaction();
        $this->owned($userId, $brandId, $dataset);

        return DB::transaction(function () use ($userId, $brandId, $dataset, $archive) {
            $this->reserveWriter($dataset);
            $current = $this->owned($userId, $brandId, $dataset, false, true);
            $current->archived_at = $archive ? ($current->archived_at ?? now()) : null;
            $current->save();

            return $current;
        });
    }

    public function create(int $userId, int $brandId, string $name, array $table): StudioDataset
    {
        $this->ownTransaction();
        $this->brand($userId, $brandId);
        $name = $this->name($name);
        $table = (new StudioChartData('dataset'))->normalize($table);
        $path = null;
        try {
            return DB::transaction(function () use ($userId, $brandId, $name, $table, &$path) {
                $dataset = StudioDataset::create(['user_id' => $userId, 'brand_id' => $brandId, 'name' => $name, 'revision' => 1, 'row_count' => count($table['rows']), 'column_count' => count($table['columns'])]);
                $this->snapshot($dataset, $table, $path);

                return $dataset;
            });
        } catch (Throwable $error) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    private function ownTransaction(): void
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Dataset operations require an independent transaction.');
        }
    }

    private function brand(int $userId, int $brandId): void
    {
        abort_unless(Brand::withoutUserScope()->where('user_id', $userId)->whereKey($brandId)->exists(), 404);
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if (! mb_check_encoding($name, 'UTF-8') || ! preg_match('/\S/u', $name) || mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new InvalidArgumentException('Dataset name requires 1–255 significant characters.');
        }

        return $name;
    }

    private function snapshot(StudioDataset $dataset, array $table, ?string &$path): void
    {
        $path = 'studio-datasets/'.$dataset->user_id.'/'.$dataset->brand_id.'/'.Str::uuid().'.json';
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Dataset storage unavailable.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        $write = static function (string $chunk) use ($stream, $hash, &$bytes): void {
            if (fwrite($stream, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('Dataset storage unavailable.');
            }
            hash_update($hash, $chunk);
            $bytes += strlen($chunk);
        };
        try {
            $write('{"columns":'.json_encode($table['columns'], self::JSON_FLAGS).',"rows":[');
            foreach ($table['rows'] as $index => $row) {
                $write(($index === 0 ? '' : ',').json_encode($row, self::JSON_FLAGS));
            }
            $write(']}');
            rewind($stream);
            if (! Storage::disk('local')->put($path, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('Dataset storage unavailable.');
            }
        } finally {
            fclose($stream);
        }
        StudioDatasetRevision::create(['dataset_id' => $dataset->id, 'user_id' => $dataset->user_id, 'brand_id' => $dataset->brand_id, 'revision' => $dataset->revision, 'name' => $dataset->name, 'row_count' => $dataset->row_count, 'column_count' => $dataset->column_count, 'json_bytes' => $bytes, 'sha256' => hash_final($hash), 'path' => $path]);
    }
}
