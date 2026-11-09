<?php

namespace Tests\Feature\Studio;

use App\Models\Brand;
use App\Models\User;
use App\Services\Studio\StudioChartFiles;
use App\Services\Studio\StudioDatasets;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class StudioChartFilesTest extends TestCase
{
    public function createApplication()
    {
        foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => 'storage/framework/testing/t12-chart-static-exports/absent.php', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('storage/framework/testing/t12-chart-static-exports/absent.env');
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => storage_path('framework/testing/t12-chart-static-exports/files-'.bin2hex(random_bytes(8))), 'throw' => true]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', fn (Blueprint $t) => $t->id());
        Schema::create('brands', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
        });
        DB::table('users')->insert([['id' => 1], ['id' => 2]]);
        DB::table('brands')->insert([['id' => 1, 'user_id' => 1], ['id' => 2, 'user_id' => 2]]);
        (require base_path('database/migrations/2026_11_06_010000_create_studio_datasets.php'))->up();
    }

    private function fixture(): array
    {
        $dataset = (new StudioDatasets)->create(1, 1, 'Pinned', ['columns' => [['id' => 'cat', 'label' => 'Category'], ['id' => 'val', 'label' => 'Value']], 'rows' => [['id' => 'row', 'values' => ['A', 1.25]]]]);
        $revision = $dataset->revisions()->first();
        $ref = ['id' => $dataset->id, 'revision' => 1, 'sha256' => $revision->sha256];

        return [$revision, ['pages' => [['elements' => [['type' => 'chart', 'chart' => ['binding' => ['dataset' => $ref]]]]]]]];
    }

    private function runMaterialize(array $doc, string $dir = 'studio-exports/1/7/11111111-1111-4111-8111-111111111111'): array
    {
        return (new StudioChartFiles)->materialize(User::withoutGlobalScopes()->findOrFail(1), Brand::withoutGlobalScopes()->findOrFail(1), $doc, $dir);
    }

    public function test_copies_original_pinned_bytes_and_deduplicates_archived_history(): void
    {
        [$revision,$doc] = $this->fixture();
        DB::table('studio_datasets')->update(['archived_at' => now(), 'revision' => 2]);
        $doc['pages'][0]['elements'][] = $doc['pages'][0]['elements'][0];
        $files = $this->runMaterialize($doc);
        $this->assertCount(1, $files);
        $this->assertSame(['id', 'revision', 'sha256', 'bytes', 'name'], array_keys($files[0]));
        $this->assertSame(Storage::disk('local')->get($revision->path), Storage::disk('local')->get('studio-exports/1/7/11111111-1111-4111-8111-111111111111/'.$files[0]['name']));
    }

    public function test_missing_sha_scope_corruption_and_path_are_rejected(): void
    {
        [$revision,$doc] = $this->fixture();
        foreach ([['id', 999, 404], ['revision', 9, 404], ['sha256', str_repeat('a', 64), 422]] as [$field,$value,$status]) {
            $bad = $doc;
            $bad['pages'][0]['elements'][0]['chart']['binding']['dataset'][$field] = $value;
            $this->expectStatus($status, fn () => $this->runMaterialize($bad));
        }
        $this->expectStatus(422, fn () => $this->runMaterialize($doc, 'studio-exports/2/7/11111111-1111-4111-8111-111111111111'));
        Storage::disk('local')->put($revision->path, 'broken');
        $this->expectStatus(503, fn () => $this->runMaterialize($doc));
        $this->assertSame([], Storage::disk('local')->allFiles('studio-exports'));
    }

    public function test_all_metadata_is_checked_before_read_and_existing_files_survive(): void
    {
        [$revision,$doc] = $this->fixture();
        $files = $this->runMaterialize($doc);
        $this->expectStatus(503, fn () => $this->runMaterialize($doc));
        $this->assertTrue(Storage::disk('local')->exists('studio-exports/1/7/11111111-1111-4111-8111-111111111111/'.$files[0]['name']));
        DB::table('studio_dataset_revisions')->update(['json_bytes' => 64000001]);
        Storage::disk('local')->delete($revision->path);
        $this->expectStatus(422, fn () => $this->runMaterialize($doc));
    }

    public function test_cross_tenant_owner_revision_and_dataset_path_are_authorized(): void
    {
        [$revision,$doc] = $this->fixture();
        DB::table('studio_datasets')->update(['user_id' => 2]);
        $this->expectStatus(404, fn () => $this->runMaterialize($doc));
        DB::table('studio_datasets')->update(['user_id' => 1]);
        DB::table('studio_dataset_revisions')->update(['brand_id' => 2]);
        $this->expectStatus(404, fn () => $this->runMaterialize($doc));
        DB::table('studio_dataset_revisions')->update(['brand_id' => 1, 'path' => '../secret.json']);
        $this->expectStatus(503, fn () => $this->runMaterialize($doc));
    }

    public function test_reference_and_aggregate_budgets_reject_before_filesystem_access(): void
    {
        [$revision,$doc] = $this->fixture();
        $many = $doc;
        $many['pages'][0]['elements'] = [];
        for ($i = 1; $i <= 33; $i++) {
            $el = $doc['pages'][0]['elements'][0];
            $el['chart']['binding']['dataset']['id'] = $i;
            $many['pages'][0]['elements'][] = $el;
        }
        $this->expectStatus(422, fn () => $this->runMaterialize($many));
        $data = (array) DB::table('studio_dataset_revisions')->first();
        unset($data['id']);
        $data['revision'] = 2;
        $data['json_bytes'] = 32000001;
        $data['path'] = 'studio-datasets/1/1/22222222-2222-4222-8222-222222222222.json';
        DB::table('studio_dataset_revisions')->insert($data);
        DB::table('studio_dataset_revisions')->where('revision', 1)->update(['json_bytes' => 32000000]);
        $el = $doc['pages'][0]['elements'][0];
        $el['chart']['binding']['dataset']['revision'] = 2;
        $doc['pages'][0]['elements'][] = $el;
        Storage::disk('local')->delete($revision->path);
        $this->expectStatus(422, fn () => $this->runMaterialize($doc));
    }

    public function test_failure_cleans_only_new_outputs_after_a_previous_copy(): void
    {
        [$revision,$doc] = $this->fixture();
        $other = (new StudioDatasets)->create(1, 1, 'Other', ['columns' => [['id' => 'cat', 'label' => 'Category'], ['id' => 'val', 'label' => 'Value']], 'rows' => [['id' => 'row', 'values' => ['B', 2]]]]);
        $otherRevision = $other->revisions()->first();
        $el = $doc['pages'][0]['elements'][0];
        $el['chart']['binding']['dataset'] = ['id' => $other->id, 'revision' => 1, 'sha256' => $otherRevision->sha256];
        $doc['pages'][0]['elements'][] = $el;
        Storage::disk('local')->delete($otherRevision->path);
        $dir = 'studio-exports/1/7/11111111-1111-4111-8111-111111111111';
        Storage::disk('local')->put($dir.'/keep.txt', 'untouched');
        $this->expectStatus(503, fn () => $this->runMaterialize($doc));
        $this->assertSame([$dir.'/keep.txt'], Storage::disk('local')->allFiles($dir));
        $this->assertSame('untouched', Storage::disk('local')->get($dir.'/keep.txt'));
    }

    private function expectStatus(int $status, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected '.$status);
        } catch (HttpException $e) {
            $this->assertSame($status,$e->getStatusCode());
        }
    }
}
