<?php

namespace Tests\Feature\Studio;

use App\Models\Brand;
use App\Models\User;
use App\Services\Studio\StudioCharts;
use App\Services\Studio\StudioDatasets;
use App\Services\Studio\StudioDocument;
use App\Services\Studio\StudioFonts;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class StudioChartsTest extends TestCase
{
    private StudioDatasets $datasets;

    private User $user;

    private Brand $brand;

    public function createApplication()
    {
        foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => 'storage/framework/testing/t12-chart-contract-php/absent-'.bin2hex(random_bytes(8)).'.php', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('storage/framework/testing/t12-chart-contract-php/absent.env');
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => storage_path('framework/testing/t12-chart-contract-php/files-'.bin2hex(random_bytes(8))), 'throw' => true]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });
        Schema::create('studio_fonts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('brand_id');
            $table->string('status');
        });
        DB::table('users')->insert([['id' => 1], ['id' => 2]]);
        DB::table('brands')->insert([['id' => 1, 'user_id' => 1], ['id' => 2, 'user_id' => 2], ['id' => 3, 'user_id' => 1]]);
        (require base_path('database/migrations/2026_11_06_010000_create_studio_datasets.php'))->up();
        $this->datasets = new StudioDatasets;
        $this->user = User::withoutGlobalScopes()->findOrFail(1);
        $this->brand = Brand::withoutGlobalScopes()->findOrFail(1);
    }

    private function chart(array $values = [2, null, 0], array $options = ['kind' => 'column']): array
    {
        $table = ['columns' => [['id' => 'category', 'label' => 'Category'], ['id' => 'value', 'label' => 'Value']], 'rows' => []];
        foreach ($values as $index => $value) {
            $table['rows'][] = ['id' => 'r'.$index, 'values' => ['Same', $value]];
        }
        $dataset = $this->datasets->create(1, 1, 'Chart', $table);

        return ['id' => 'chart', 'type' => 'chart', 'x' => 0, 'y' => 0, 'width' => 640, 'height' => 360, 'chart' => ['binding' => ['dataset' => ['id' => $dataset->id, 'revision' => 1, 'sha256' => $dataset->revisions()->first()->sha256], 'category_column' => 'category', 'series_columns' => ['value']], 'options' => $options]];
    }

    private function document(array $elements): array
    {
        $doc = StudioDocument::blank();
        $doc['pages'][0]['elements'] = $elements;

        return $doc;
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation rejection');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
    }

    public function test_document_preserves_chart_exactly_and_checks_dimensions(): void
    {
        $chart = $this->chart();
        unset($chart['chart']['options']);
        $document = $this->document([$chart]);
        $this->assertSame($document, app(StudioDocument::class)->validate($this->user, $this->brand, $document));
        foreach ([159, 8001] as $size) {
            $chart['width'] = $size;
            $this->invalid(fn () => app(StudioDocument::class)->validate($this->user, $this->brand, $this->document([$chart])));
        }
    }

    public function test_typed_series_precision_and_domains_are_enforced(): void
    {
        foreach ([[['2'], ['kind' => 'column']], [[-1], ['kind' => 'bar-proportional']], [[9007199254740992], ['kind' => 'column']], [[10], ['kind' => 'column', 'domain_max' => 5]], [[-10], ['kind' => 'bar', 'domain_min' => -5]]] as [$values, $options]) {
            $chart = $this->chart($values, $options);
            $this->invalid(fn () => app(StudioCharts::class)->validate($this->user, $this->brand, $this->document([$chart])));
        }
        $chart = $this->chart([9007199254740992]);
        $chart['chart']['binding']['precision'] = 'approximate';
        $document = $this->document([$chart]);
        $this->assertSame($document, app(StudioDocument::class)->validate($this->user, $this->brand, $document));
    }

    public function test_scope_revision_sha_and_resource_integrity_statuses(): void
    {
        $chart = $this->chart();
        foreach ([['id', 999, 404], ['revision', 2, 404], ['sha256', str_repeat('b', 64), 422]] as [$key, $value, $status]) {
            $copy = $chart;
            $copy['chart']['binding']['dataset'][$key] = $value;
            $this->assertHttpStatus($status, fn () => app(StudioCharts::class)->validate($this->user, $this->brand, $this->document([$copy])));
        }
        $foreign = Brand::withoutGlobalScopes()->findOrFail(3);
        $this->assertHttpStatus(404, fn () => app(StudioCharts::class)->validate($this->user, $foreign, $this->document([$chart])));
        $revision = DB::table('studio_dataset_revisions')->first();
        Storage::disk('local')->put($revision->path, 'corrupt');
        $this->assertHttpStatus(503, fn () => app(StudioCharts::class)->validate($this->user, $this->brand, $this->document([$chart])));
    }

    private function assertHttpStatus(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected HTTP '.$status);
        } catch (ValidationException $error) {
            $this->assertSame($status, $error->status);
        } catch (ModelNotFoundException $error) {
            $this->assertSame(404, $status);
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_metadata_budgets_precede_any_read_and_repeated_layers_are_deduplicated(): void
    {
        $chart = $this->chart();
        $revision = DB::table('studio_dataset_revisions')->first();
        DB::table('studio_dataset_revisions')->update(['json_bytes' => 64000001]);
        Storage::disk('local')->delete($revision->path);
        $this->invalid(fn () => app(StudioCharts::class)->validate($this->user, $this->brand, $this->document([$chart])));
        DB::table('studio_dataset_revisions')->update(['json_bytes' => $revision->json_bytes]);
        // 500 empty charts exactly meet the 50k budget: one metadata resolution + one read.
        $chart = $this->chart([]);
        $disk = Storage::disk('local');
        $spy = \Mockery::mock($disk);
        $spy->shouldReceive('readStream')->once()->andReturnUsing(fn ($path) => $disk->readStream($path));
        Storage::set('local', $spy);
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(StudioCharts::class)->validate($this->user, $this->brand, $this->document(array_fill(0, 500, $chart)));
        $this->assertLessThan(12, count(DB::getQueryLog()));
        DB::disableQueryLog();
        DB::table('studio_dataset_revisions')->where('dataset_id', $chart['chart']['binding']['dataset']['id'])->update(['row_count' => 1000]);
        $this->invalid(fn () => app(StudioCharts::class)->validate($this->user, $this->brand, $this->document(array_fill(0, 20, $chart))));
    }

    public function test_chart_fonts_are_walked_and_require_own_ready_brand_font(): void
    {
        $chart = $this->chart();
        $chart['chart']['style'] = ['font_id' => 7];
        $document = $this->document([['id' => 'group', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 640, 'height' => 360, 'elements' => [$chart]]]);
        $this->assertSame([7], StudioFonts::idsFromDocument($document));
        $this->invalid(fn () => app(StudioDocument::class)->validate($this->user, $this->brand, $document));
        DB::table('studio_fonts')->insert(['id' => 7, 'user_id' => 1, 'brand_id' => 1, 'status' => 'ready']);
        $this->assertSame($document, app(StudioDocument::class)->validate($this->user, $this->brand, $document));
        foreach ([['brand_id' => 3], ['brand_id' => 1, 'status' => 'pending'], ['user_id' => 2, 'status' => 'ready']] as $mutation) {
            DB::table('studio_fonts')->where('id', 7)->update($mutation);
            $this->invalid(fn () => app(StudioDocument::class)->validate($this->user, $this->brand, $document));
        }
    }

    /** Characterization of scoped dataset behavior reused by the new integration. */
    public function test_archived_historical_revision_is_fixed_and_missing_columns_fail(): void
    {
        $chart = $this->chart([2, null, 0]);
        $id = $chart['chart']['binding']['dataset']['id'];
        $this->datasets->replace(1, 1, $id, 1, 'Changed', ['columns' => [['id' => 'other', 'label' => 'Other']], 'rows' => []]);
        $this->datasets->archive(1, 1, $id);
        $document = $this->document([$chart]);
        $this->assertSame($document, app(StudioDocument::class)->validate($this->user, $this->brand, $document));
        $chart['chart']['binding']['category_column'] = 'absent';
        $this->invalid(fn () => app(StudioCharts::class)->validate($this->user, $this->brand, $this->document([$chart])));
    }

    public function test_characterization_stacked_extremes_simple_series_and_overflow(): void
    {
        $chart = $this->chart([6, -6]);
        $chart['chart']['binding']['series_columns'] = ['value', 'second'];
        $id = $chart['chart']['binding']['dataset']['id'];
        $table = $this->datasets->read(1, 1, $id, 1);
        $table['columns'][] = ['id' => 'second', 'label' => 'Second'];
        foreach ($table['rows'] as &$row) {
            $row['values'][] = $row['values'][1];
        } unset($row);
        $this->datasets->replace(1, 1, $id, 1, 'Two series', $table);
        $chart['chart']['binding']['dataset']['revision'] = 2;
        $chart['chart']['binding']['dataset']['sha256'] = DB::table('studio_dataset_revisions')->where('dataset_id', $id)->where('revision', 2)->value('sha256');
        $this->invalid(fn () => app(StudioCharts::class)->validate($this->user, $this->brand, $this->document([$chart])));
        foreach ([['kind' => 'column-stacked', 'domain_max' => 10], ['kind' => 'bar-stacked', 'domain_min' => -10]] as $options) {
            $chart['chart']['options'] = $options;
            $this->invalid(fn () => app(StudioCharts::class)->validate($this->user, $this->brand, $this->document([$chart])));
        }
        $chart['chart']['options'] = ['kind' => 'bar-stacked'];
        $document = $this->document([$chart]);
        $this->assertSame($document, app(StudioDocument::class)->validate($this->user, $this->brand, $document));
        $chart['chart']['options'] = ['kind' => 'bar', 'domain_min' => -PHP_FLOAT_MAX, 'domain_max' => PHP_FLOAT_MAX];
        $chart['chart']['binding']['series_columns'] = ['value'];
        $document = $this->document([$chart]);
        $this->assertSame($document, app(StudioDocument::class)->validate($this->user, $this->brand, $document));
    }

    public static function finiteWideDomains(): array
    {
        return ['maximum finite endpoints' => [PHP_FLOAT_MAX], 'decimal exponent endpoints' => [1e308]];
    }

    #[DataProvider('finiteWideDomains')]
    public function test_save_accepts_finite_domain_endpoints_even_when_their_difference_overflows(float $endpoint): void
    {
        $chart = $this->chart([1], ['kind' => 'column', 'domain_min' => -$endpoint, 'domain_max' => $endpoint]);
        // JSON transport has only finite endpoints; the same JSON options are valid input to G5.
        $document = json_decode(json_encode($this->document([$chart]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($document, app(StudioDocument::class)->validate($this->user, $this->brand, $document));
    }

    public function test_real_stacked_overflow_and_incompatible_domains_still_return_422(): void
    {
        $chart = $this->chart([PHP_FLOAT_MAX], ['kind' => 'column-stacked']);
        $id = $chart['chart']['binding']['dataset']['id'];
        $table = $this->datasets->read(1, 1, $id, 1);
        $table['columns'][] = ['id' => 'second', 'label' => 'Second'];
        $table['rows'][0]['values'][] = PHP_FLOAT_MAX;
        $this->datasets->replace(1, 1, $id, 1, 'Overflow', $table);
        $chart['chart']['binding']['dataset']['revision'] = 2;
        $chart['chart']['binding']['dataset']['sha256'] = DB::table('studio_dataset_revisions')->where('dataset_id', $id)->where('revision', 2)->value('sha256');
        $chart['chart']['binding']['series_columns'] = ['value', 'second'];
        $chart['chart']['binding']['precision'] = 'approximate';
        try {
            app(StudioDocument::class)->validate($this->user, $this->brand, $this->document([$chart]));
            $this->fail('Expected a real stacked accumulation overflow to be rejected.');
        } catch (ValidationException $error) {
            $this->assertSame(422, $error->status);
            $this->assertSame(['document.pages.0.elements.0.chart.options' => ['Acumulado no finito.']], $error->errors());
        }
        foreach ([['domain_min' => 0, 'domain_max' => 0], ['domain_min' => 1, 'domain_max' => 10], ['domain_min' => -10, 'domain_max' => 0]] as $domain) {
            $chart = $this->chart([1], ['kind' => 'column'] + $domain);
            $this->assertHttpStatus(422, fn () => app(StudioDocument::class)->validate($this->user, $this->brand, $this->document([$chart])));
        }
    }

    public function test_characterization_unique_reference_and_aggregate_document_budgets(): void
    {
        $chart = $this->chart();
        $elements = [];
        for ($i = 0; $i < 33; $i++) {
            $copy = $chart;
            $copy['chart']['binding']['dataset']['id'] = $i + 1;
            $elements[] = $copy;
        }
        // Reference bound is checked before querying nonexistent ids.
        $this->invalid(fn () => app(StudioCharts::class)->validate($this->user, $this->brand, $this->document($elements)));
        $other = $this->chart();
        DB::table('studio_dataset_revisions')->update(['json_bytes' => 33000000]);
        $this->invalid(fn () => app(StudioCharts::class)->validate($this->user, $this->brand, $this->document([$chart, $other])));
        DB::table('studio_dataset_revisions')->update(['json_bytes' => 1, 'row_count' => 1000]);
        $document = $this->document(array_fill(0, 16, $chart));
        $document['pages'] = array_fill(0, 5, $document['pages'][0]);
        // Each page is under 50k, their combined 248k exceeds the 200k document budget before reads.
        $this->invalid(fn () => app(StudioCharts::class)->validate($this->user, $this->brand, $document));
    }

    public function test_characterization_v2_preserves_chart_timing_and_captions(): void
    {
        $chart = $this->chart();
        $chart['timing'] = ['start' => 0, 'duration' => 4];
        $document = $this->document([$chart]);
        $document['schema_version'] = 2;
        $document['timeline'] = ['fps' => 30, 'audio_tracks' => [], 'captions' => ['style' => ['fontFamily' => 'Arial'], 'cues' => [['id' => 'cue', 'start' => 0, 'end' => 1, 'text' => 'Caption']]]];
        $this->assertSame($document, app(StudioDocument::class)->validate($this->user, $this->brand, $document));
    }
}
