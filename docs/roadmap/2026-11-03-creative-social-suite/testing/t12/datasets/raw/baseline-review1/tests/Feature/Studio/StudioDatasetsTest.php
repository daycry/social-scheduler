<?php

namespace Tests\Feature\Studio;

use App\Models\Studio\StudioDataset;
use App\Services\Studio\StudioDatasets;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** No RefreshDatabase: the service owns its transaction and rejects external ones. */
final class StudioDatasetsTest extends TestCase
{
    private StudioDatasets $datasets;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('storage/framework/testing/t12-datasets/absent.env');
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => storage_path('framework/testing/t12-datasets/files-'.bin2hex(random_bytes(8))), 'throw' => true]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
        });
        DB::table('users')->insert([['id' => 1], ['id' => 2]]);
        DB::table('brands')->insert([['id' => 1, 'user_id' => 1], ['id' => 2, 'user_id' => 2], ['id' => 3, 'user_id' => 1]]);
        $migration = base_path('database/migrations/2026_11_06_010000_create_studio_datasets.php');
        if (is_file($migration)) {
            (require $migration)->up();
        }
        $this->datasets = new StudioDatasets;
    }

    private function table(string $value = '001'): array
    {
        return ['columns' => [['id' => 'c', 'label' => 'Label']], 'rows' => [['id' => 'r', 'values' => [$value]]]];
    }

    public function test_creation_persists_private_canonical_snapshot(): void
    {
        $dataset = $this->datasets->create(1, 1, ' Report ', $this->table());
        $this->assertInstanceOf(StudioDataset::class, $dataset);
        $this->assertSame('Report', $dataset->name);
        $this->assertSame(1, $dataset->revision);
        $revision = $dataset->revisions()->firstOrFail();
        $bytes = Storage::disk('local')->get($revision->path);
        $this->assertSame($this->table(), json_decode($bytes, true));
        $this->assertSame(hash('sha256', $bytes), $revision->sha256);
        $this->assertSame(strlen($bytes), $revision->json_bytes);
        $this->assertStringStartsWith('studio-datasets/1/1/', $revision->path);
        $this->assertSame(1, $revision->row_count);
        $this->assertSame(1, $revision->column_count);
    }

    private function assertStatus(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected HTTP '.$status);
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_replacement_preserves_snapshot_and_optimistic_conflict(): void
    {
        $dataset = $this->datasets->create(1, 1, 'Old', $this->table());
        $old = $dataset->revisions()->firstOrFail();
        $updated = $this->datasets->replace(1, 1, $dataset, 1, 'New', $this->table('002'));
        $this->assertSame(2, $updated->revision);
        $this->assertSame($this->table(), $this->datasets->read(1, 1, $dataset, 1));
        $this->assertSame($this->table('002'), $this->datasets->read(1, 1, $dataset));
        $files = Storage::disk('local')->allFiles();
        $this->assertStatus(409, fn () => $this->datasets->replace(1, 1, $dataset, 1, 'Conflict', $this->table('bad')));
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(hash('sha256', Storage::disk('local')->get($old->path)), $old->sha256);
        $this->assertSame(2, $dataset->revisions()->count());
    }

    public function test_read_is_typed_and_missing_revision_never_falls_back(): void
    {
        $table = ['columns' => [['id' => 'c', 'label' => '€']], 'rows' => [['id' => 'a', 'values' => [1.0]], ['id' => 'b', 'values' => [null]]]];
        $dataset = $this->datasets->create(1, 1, 'Typed', $table);
        $this->assertSame($table, $this->datasets->read(1, 1, $dataset, 1));
        $this->assertStatus(404, fn () => $this->datasets->read(1, 1, $dataset, 2));
    }

    public function test_all_operations_reject_foreign_unscoped_models_and_crossed_brands(): void
    {
        $dataset = $this->datasets->create(1, 1, 'Owned', $this->table());
        foreach ([[2, 1], [1, 2], [1, 3]] as [$user, $brand]) {
            foreach ([fn () => $this->datasets->read($user, $brand, $dataset, 1), fn () => $this->datasets->replace($user, $brand, $dataset, 1, 'bad', $this->table()), fn () => $this->datasets->archive($user, $brand, $dataset), fn () => $this->datasets->restore($user, $brand, $dataset), fn () => $this->datasets->present($user, $brand, $dataset), fn () => $this->datasets->history($user, $brand, $dataset)] as $operation) {
                $this->assertStatus(404, $operation);
            }
        }
        $this->assertStatus(404, fn () => $this->datasets->create(1, 2, 'bad', $this->table()));
        $this->assertStatus(404, fn () => $this->datasets->listing(1, 2));
        $this->assertSame(1, $dataset->fresh()->revision);
    }

    public function test_archive_restore_keep_history_and_block_edits(): void
    {
        $dataset = $this->datasets->create(1, 1, 'Archive', $this->table());
        $files = Storage::disk('local')->allFiles();
        $this->assertNotNull($this->datasets->archive(1, 1, $dataset)->archived_at);
        $this->assertSame(0, $this->datasets->listing(1, 1)->total());
        $this->assertStatus(404, fn () => $this->datasets->replace(1, 1, $dataset, 1, 'bad', $this->table()));
        $this->assertSame($this->table(), $this->datasets->read(1, 1, $dataset, 1));
        $this->assertNull($this->datasets->restore(1, 1, $dataset)->archived_at);
        $this->assertSame(1, $this->datasets->listing(1, 1)->total());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(1, $dataset->revisions()->count());
    }

    public function test_listing_literal_search_pagination_safe_presentation_and_history(): void
    {
        $dataset = $this->datasets->create(1, 1, '100%_ready', $this->table());
        $this->datasets->create(1, 1, '100Xready', $this->table());
        $this->datasets->create(1, 3, '100%_ready', $this->table());
        $page = $this->datasets->listing(1, 1, '%_', 1, 1);
        $this->assertSame(1, $page->total());
        $this->assertSame($dataset->id, $page->items()[0]['id']);
        $this->assertSame(2, $this->datasets->listing(1, 1, '', 2, 1)->total());
        $this->assertSame(1, $this->datasets->history(1, 1, $dataset)->total());
        $safe = $this->datasets->present(1, 1, $dataset);
        $this->assertSame($dataset->id, $safe['id']);
        foreach (['user_id', 'path', 'disk', 'table', 'rows', 'columns'] as $key) {
            $this->assertArrayNotHasKey($key, $safe);
        }
        $this->assertArrayNotHasKey('path', $this->datasets->history(1, 1, $dataset)->items()[0]);
        Storage::shouldReceive('disk')->never();
        $this->assertSame(2, $this->datasets->listing(1, 1)->total());
        $this->assertSame(1, $this->datasets->history(1, 1, $dataset)->total());
    }

    public function test_missing_or_corrupt_storage_is_controlled_503_without_path(): void
    {
        $dataset = $this->datasets->create(1, 1, 'Read', $this->table());
        $revision = $dataset->revisions()->firstOrFail();
        foreach (['bad', str_repeat('x', $revision->json_bytes)] as $bytes) {
            Storage::disk('local')->put($revision->path, $bytes);
            try {
                $this->datasets->read(1, 1, $dataset, 1);
                $this->fail('Expected 503');
            } catch (HttpException $error) {
                $this->assertSame(503, $error->getStatusCode());
                $this->assertStringNotContainsString($revision->path, $error->getMessage());
            }
        }
        Storage::disk('local')->delete($revision->path);
        $this->assertStatus(503, fn () => $this->datasets->read(1, 1, $dataset, 1));
    }

    public function test_external_transaction_rejected_before_any_storage_for_each_mutation(): void
    {
        $dataset = $this->datasets->create(1, 1, 'Transaction', $this->table());
        DB::beginTransaction();
        Storage::shouldReceive('disk')->never();
        try {
            foreach ([fn () => $this->datasets->create(1, 1, 'bad', $this->table()), fn () => $this->datasets->replace(1, 1, $dataset, 1, 'bad', $this->table()), fn () => $this->datasets->archive(1, 1, $dataset), fn () => $this->datasets->restore(1, 1, $dataset)] as $operation) {
                try {
                    $operation();
                    $this->fail('Expected independent transaction guard');
                } catch (\LogicException $error) {
                    $this->assertStringContainsString('independent transaction', $error->getMessage());
                }
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_failed_write_removes_only_new_file_and_rolls_back_metadata(): void
    {
        $dataset = $this->datasets->create(1, 1, 'Stable', $this->table());
        $disk = Storage::disk('local');
        $oldFiles = $disk->allFiles();
        $disk->put('unrelated/keep.json', 'unrelated');
        $proxy = \Mockery::mock($disk)->makePartial();
        $proxy->shouldReceive('put')->once()->andReturnUsing(function ($path, $stream, $options) use ($disk) {
            $disk->put($path, 'partial');

            return false;
        });
        $proxy->shouldReceive('delete')->once()->andReturnUsing(fn ($path) => $disk->delete($path));
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        try {
            $this->datasets->replace(1, 1, $dataset, 1, 'Failed', $this->table('bad'));
            $this->fail('Expected write failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Dataset storage unavailable.', $error->getMessage());
        }
        $this->assertSame('Stable', $dataset->fresh()->name);
        $this->assertSame(1, $dataset->fresh()->revision);
        $this->assertSame(1, $dataset->revisions()->count());
        $this->assertSame('unrelated', $disk->get('unrelated/keep.json'));
        $this->assertSame($oldFiles, $disk->allFiles('studio-datasets'));
    }

    public function test_revision_insert_failure_rolls_back_only_new_file(): void
    {
        $dataset = $this->datasets->create(1, 1, 'Stable', $this->table());
        $files = Storage::disk('local')->allFiles();
        DB::unprepared("CREATE TRIGGER t12_reject_revision BEFORE INSERT ON studio_dataset_revisions BEGIN SELECT RAISE(FAIL, 'injected failure'); END");
        try {
            $this->datasets->replace(1, 1, $dataset, 1, 'Failed', $this->table('bad'));
            $this->fail('Expected insert failure');
        } catch (QueryException $error) {
            $this->assertStringContainsString('injected failure', $error->getMessage());
        }
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame('Stable', $dataset->fresh()->name);
        $this->assertSame(1, $dataset->fresh()->revision);
        $this->assertSame(1, $dataset->revisions()->count());
        try {
            $this->datasets->create(1, 1, 'Failed create', $this->table());
            $this->fail('Expected insert failure');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('studio_datasets')->count());
        }
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_sqlite_writer_is_reserved_before_first_transaction_read(): void
    {
        $dataset = $this->datasets->create(1, 1, 'Lock', $this->table());
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            if (DB::transactionLevel() > 0) {
                $queries[] = strtolower($event->sql);
            }
        });
        $this->datasets->replace(1, 1, $dataset, 1, 'New', $this->table());
        $this->assertStringStartsWith('update "studio_datasets" set "id" = id', $queries[0]);
        $queries = [];
        $this->datasets->archive(1, 1, $dataset);
        $this->assertStringStartsWith('update "studio_datasets" set "id" = id', $queries[0]);
    }

    public function test_dataset_profile_roundtrips_over_inline_budget_without_json_database_column(): void
    {
        $table = ['columns' => [['id' => 'c', 'label' => 'Large']], 'rows' => []];
        for ($row = 0; $row < 250; $row++) {
            $table['rows'][] = ['id' => 'r'.$row, 'values' => [str_repeat('a', 9000)]];
        }
        $dataset = $this->datasets->create(1, 1, 'Large', $table);
        $revision = $dataset->revisions()->firstOrFail();
        $this->assertGreaterThan(2097152, $revision->json_bytes);
        $this->assertSame($table, $this->datasets->read(1, 1, $dataset, 1));
        $this->assertNotContains('table', Schema::getColumnListing('studio_dataset_revisions'));
        $this->assertNotContains('document', Schema::getColumnListing('studio_datasets'));
    }

    public function test_read_rejects_valid_digest_invalid_schema_and_tampered_private_path(): void
    {
        $dataset = $this->datasets->create(1, 1, 'Validate', $this->table());
        $revision = $dataset->revisions()->firstOrFail();
        foreach (['{}', '{"columns":[],"rows":[]}'] as $bytes) {
            Storage::disk('local')->put($revision->path, $bytes);
            DB::table('studio_dataset_revisions')->where('id', $revision->id)->update(['sha256' => hash('sha256', $bytes), 'json_bytes' => strlen($bytes)]);
            $this->assertStatus(503, fn () => $this->datasets->read(1, 1, $dataset, 1));
        }
        DB::table('studio_dataset_revisions')->where('id', $revision->id)->update(['path' => 'unrelated/private.json']);
        Storage::shouldReceive('disk')->never();
        $this->assertStatus(503, fn () => $this->datasets->read(1, 1, $dataset, 1));
    }

    public function test_input_validation_and_name_only_revision_and_immutability(): void
    {
        foreach (['', '  ', str_repeat('a', 256), "bad\0name"] as $name) {
            try {
                $this->datasets->create(1, 1, $name, $this->table());
                $this->fail('Expected name validation');
            } catch (\InvalidArgumentException) {
                $this->assertSame(0, DB::table('studio_datasets')->count());
            }
        }
        try {
            $this->datasets->create(1, 1, 'bad', ['columns' => [], 'rows' => []]);
            $this->fail('Expected table validation');
        } catch (\InvalidArgumentException) {
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
        $dataset = $this->datasets->create(1, 1, 'Old name', $this->table());
        $updated = $this->datasets->replace(1, 1, $dataset, 1, 'New name', $this->table());
        $this->assertSame(2, $updated->revision);
        $this->assertSame('Old name', $dataset->revisions()->where('revision', 1)->firstOrFail()->name);
        $this->assertSame('New name', $dataset->revisions()->where('revision', 2)->firstOrFail()->name);
        $revision = $dataset->revisions()->firstOrFail();
        try {
            $revision->update(['name' => 'Mutation']);
            $this->fail('Expected immutable revision');
        } catch (\LogicException) {
            $this->assertSame('Old name', $revision->fresh()->name);
        }
        try {
            $revision->delete();
            $this->fail('Expected immutable revision');
        } catch (\LogicException) {
            $this->assertSame(2, $dataset->revisions()->count());
        }
    }

    public function test_corrupt_json_object_cannot_masquerade_as_empty_rows_list(): void
    {
        $table = $this->table();
        $table['rows'] = [];
        $dataset = $this->datasets->create(1, 1, 'Empty', $table);
        $revision = $dataset->revisions()->firstOrFail();
        $bytes = '{"columns":[{"id":"c","label":"Label"}],"rows":{}}';
        Storage::disk('local')->put($revision->path, $bytes);
        DB::table('studio_dataset_revisions')->where('id', $revision->id)->update(['sha256' => hash('sha256', $bytes), 'json_bytes' => strlen($bytes)]);
        $this->assertStatus(503, fn () => $this->datasets->read(1, 1, $dataset, 1));
    }
}
