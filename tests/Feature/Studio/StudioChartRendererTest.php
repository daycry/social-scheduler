<?php

namespace Tests\Feature\Studio;

use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignRevision;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioDatasets;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioFonts;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StudioChartRendererTest extends TestCase
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

    public function test_renderer_materializes_only_selected_pages_and_passes_small_descriptors(): void
    {
        Schema::create('studio_designs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('brand_id');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('studio_design_exports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('brand_id');
            $t->unsignedBigInteger('design_id');
            $t->integer('revision');
            $t->string('format');
            $t->string('status');
            $t->text('pages');
            $t->text('options');
            $t->text('media_ids')->nullable();
            $t->string('disk')->nullable();
            $t->string('path')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
        });
        DB::table('studio_designs')->insert(['id' => 1, 'user_id' => 1, 'brand_id' => 1]);
        $ds = (new StudioDatasets)->create(1, 1, 'Pin', ['columns' => [['id' => 'cat', 'label' => 'Cat'], ['id' => 'val', 'label' => 'Val']], 'rows' => [['id' => 'r', 'values' => ['A', 1]]]]);
        $rev = $ds->revisions()->first();
        $chart = ['type' => 'chart', 'chart' => ['binding' => ['dataset' => ['id' => $ds->id, 'revision' => 1, 'sha256' => $rev->sha256]], 'style' => ['font_id' => 9]]];
        $doc = ['schema_version' => 1, 'width' => 800, 'height' => 500, 'pages' => [['id' => 'p', 'elements' => [$chart]], ['id' => 'skip', 'elements' => [['type' => 'chart', 'chart' => ['binding' => ['dataset' => ['id' => 999, 'revision' => 1, 'sha256' => str_repeat('a', 64)]]]]]]]];
        $exports = \Mockery::mock(StudioExports::class);
        $exports->shouldReceive('revision')->once()->andReturn(new DesignRevision(['document' => $doc, 'name' => 'Pinned']));
        $assets = \Mockery::mock(StudioAssets::class);
        $assets->shouldReceive('many')->once()->andReturn(collect());
        $fonts = \Mockery::mock(StudioFonts::class);
        $fonts->shouldReceive('many')->once()->withArgs(fn ($u, $b, $ids) => $ids === [9])->andReturn(collect());
        $export = DesignExport::create(['user_id' => 1, 'brand_id' => 1, 'design_id' => 1, 'revision' => 1, 'format' => 'svg', 'status' => 'pending', 'pages' => ['p'], 'options' => []]);
        Process::fake(function ($process) use ($rev) {
            $input = json_decode($process->input, true);
            $this->assertSame(1, $input['brandId']);
            $this->assertCount(1, $input['chartFiles']);
            $this->assertLessThan(3000, strlen($process->input));
            $this->assertArrayNotHasKey('table', $input['chartFiles'][0]);
            $file = $process->command[2].DIRECTORY_SEPARATOR.$input['chartFiles'][0]['name'];
            $this->assertSame($rev->sha256, hash_file('sha256', $file));
            file_put_contents($process->command[2].DIRECTORY_SEPARATOR.'page-001.svg', '<svg/>');

            return Process::result(output: '{"files":[{"name":"page-001.svg","mime":"image/svg+xml"}]}');
        });
        (new StudioRenderer(\Mockery::mock(StudioService::class), $exports, $assets, $fonts))->render($export->id);
        $this->assertSame('ready',$export->refresh()->status,$export->error ?? '');
    }
}
