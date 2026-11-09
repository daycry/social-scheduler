<?php

namespace Tests\Feature\Studio;

use App\Services\Studio\StudioChartSpec;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class StudioChartSpecTest extends TestCase
{
    public function createApplication()
    {
        foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => 'storage/framework/testing/t12-chart-contract-php/absent-'.bin2hex(random_bytes(8)).'.php', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('storage/framework/testing/t12-chart-contract-php/absent.env');
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private function fixtures(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/studio/chart-spec.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_defaults_match_shared_contract(): void
    {
        foreach ($this->fixtures()['valid'] as $fixture) {
            $result = StudioChartSpec::normalize($fixture['input']);
            $this->assertSame($fixture['expected_subset']['kind'], $result['options']['kind'] ?? null, $fixture['name']);
            $this->assertSame($fixture['expected_subset']['fontFamily'], $result['style']['fontFamily'] ?? null);
            $this->assertSame($fixture['expected_subset']['fontSize'], $result['style']['fontSize'] ?? null);
            $this->assertSame($fixture['input']['binding']['precision'] ?? 'safe', $result['binding']['precision']);
            $this->assertArrayNotHasKey('width', $result['options']);
        }
    }

    public function test_shared_invalid_mutations_are_rejected(): void
    {
        foreach ($this->fixtures()['invalid'] as $fixture) {
            $input = $this->fixtures()['valid'][0]['input'];
            $cursor = &$input;
            foreach ($fixture['path'] as $key) {
                $cursor = &$cursor[$key];
            }
            $cursor = $fixture['value'];
            unset($cursor);
            try {
                StudioChartSpec::normalize($input);
                $this->fail('Accepted '.$fixture['name']);
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
    }

    /** Characterization of the complete defaults, beyond the shared fixture's compact subset. */
    public function test_complete_defaults_and_unicode_codepoint_boundary(): void
    {
        $input = $this->fixtures()['valid'][0]['input'];
        $result = StudioChartSpec::normalize($input);
        $this->assertSame(['kind' => 'column', 'band_gap' => 0.2, 'series_gap' => 0.1, 'palette' => ['#7c3aed', '#0284c7', '#059669', '#d97706', '#dc2626', '#db2777', '#4f46e5', '#0891b2', '#65a30d', '#ea580c', '#9333ea', '#475569'], 'domain_min' => null, 'domain_max' => null, 'tick_count' => 5], $result['options']);
        $this->assertSame(['title' => '', 'subtitle' => '', 'source' => '', 'x_title' => '', 'y_title' => '', 'fontFamily' => 'Arial', 'fontSize' => 16, 'fontWeight' => 'normal', 'fontStyle' => 'normal', 'text_color' => '#111827', 'axis_color' => '#64748b', 'grid_color' => '#e2e8f0', 'background' => '#ffffff', 'legend' => true, 'show_grid' => true, 'show_axis' => true, 'category_labels' => true, 'legend_position' => 'bottom', 'value_labels' => 'none', 'label_position' => 'outside', 'category_label_limit' => 24], $result['style']);
        $title = str_repeat("\u{1F600}", 254)."\n\t";
        $input['style'] = ['title' => $title];
        $this->assertSame($title, StudioChartSpec::normalize($input)['style']['title']);
        $this->assertSame(['precision' => 'safe'], array_diff_key($result['binding'], $input['binding']));
    }

    public function test_characterization_nested_unknown_fields_and_nonfinite_numbers(): void
    {
        foreach ([['binding', 'url', 'https://example.invalid'], ['style', 'fontSize', INF], ['options', 'band_gap', NAN], ['style', 'legend', 1], ['options', 'opacity', 0.5], ['options', 'tick_count', 22], ['style', 'category_label_limit', 81]] as [$section, $key, $value]) {
            $input = $this->fixtures()['valid'][0]['input'];
            if ($section === 'options') {
                $input['options'] = ['kind' => 'column'];
            }
            $input[$section][$key] = $value;
            try {
                StudioChartSpec::normalize($input);
                $this->fail('Accepted '.$section.'.'.$key);
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
    }
}
