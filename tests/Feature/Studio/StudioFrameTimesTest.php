<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioDocument;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $doc = StudioDocument::blank(100, 80);
    $doc['pages'][0]['id'] = 'one';
    $doc['pages'][0]['duration'] = 1.2;
    $doc['schema_version'] = 2;
    $doc['timeline'] = ['fps' => 24, 'audio_tracks' => []];
    $this->design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Frames', 'document' => $doc]);
    $this->image = MediaAsset::factory()->for($this->user)->create(['kind' => 'image', 'mime' => 'image/png', 'width' => 100, 'height' => 80]);
    $this->input = ['revision' => 1, 'format' => 'png', 'pages' => ['one']];
});

test('server rendering preserves selected frame times in the export options', function () {
    $times = [['page_id' => 'one', 'time' => 0.75]];
    $export = app(StudioRenderer::class)->export($this->user, $this->design, $this->input + ['frame_times' => $times]);
    expect($export->options['frame_times'] ?? null)->toBe($times);
});

test('MCP advertises typed frame selection and shares the API renderer options', function () {
    $key = $this->mcpKey($this->user, ['designs:read', 'designs:write']);
    $tools = [];
    $cursor = null;
    do {
        $result = $this->rpc($key, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor])->assertOk()->json('result');
        $tools = array_merge($tools, $result['tools']);
        $cursor = $result['nextCursor'] ?? null;
    } while ($cursor !== null);
    $export = collect($tools)->firstWhere('name', 'export_design');
    expect(data_get($export, 'inputSchema.properties.frame_times.type'))->toBe('array');
    $times = [['page_id' => 'one', 'time' => 0.5]];
    [$error, $data] = $this->callTool($key, 'export_design', $this->input + ['design_id' => $this->design->id, 'frame_times' => $times]);
    expect($error)->toBeFalse()->and($data['options']['frame_times'])->toBe($times);
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/designs/'.$this->design->id.'/exports', $this->input + ['frame_times' => $times], ['Authorization' => 'Bearer '.$key])
        ->assertAccepted()->assertJsonPath('options.frame_times', $times);
});

test('browser registration preserves selected frame times in the export options', function () {
    $times = [['page_id' => 'one', 'time' => 0.75]];
    $export = app(StudioExports::class)->register($this->user, $this->design, $this->input + ['media_ids' => [$this->image->id], 'frame_times' => $times]);
    expect($export->options['frame_times'] ?? null)->toBe($times);
});

test('frame selection preserves numeric and prototype-like page ids as string values', function ($id) {
    $doc = $this->design->document;
    $doc['pages'][0]['id'] = $id;
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'String id', 'document' => $doc]);
    $times = [['page_id' => $id, 'time' => 0.25]];
    $export = app(StudioRenderer::class)->export($this->user, $design, ['revision' => 1, 'format' => 'png', 'pages' => [$id], 'frame_times' => $times]);
    expect($export->options['frame_times'])->toBe($times);
})->with(['0', '01', '__proto__']);

test('JSON objects cannot masquerade as frame-time lists at any HTTP entry point', function ($entry, $indexed) {
    $times = $indexed ? (object) ['0' => (object) ['page_id' => 'one', 'time' => 0.5]] : (object) [];
    $payload = $this->input + ['frame_times' => $times];
    if ($entry === 'mcp') {
        $key = $this->mcpKey($this->user, ['designs:write']);
        [$error] = $this->callTool($key, 'export_design', $payload + ['design_id' => $this->design->id]);
        expect($error)->toBeTrue();
    } elseif ($entry === 'api') {
        $key = $this->mcpKey($this->user, ['designs:write']);
        $this->postJson('/api/v1/designs/'.$this->design->id.'/exports', $payload, ['Authorization' => 'Bearer '.$key])->assertUnprocessable();
    } else {
        $this->actingAs($this->user);
        $url = '/studio/designs/'.$this->design->id.'/exports';
        $payload['media_ids'] = [$this->image->id];
        $this->postJson($url, $payload)->assertUnprocessable();
    }
})->with(['api', 'mcp', 'register'])->with([false, true]);

test('frame times reject malformed or out-of-page values in both entry points', function ($times) {
    expect(fn () => app(StudioRenderer::class)->export($this->user, $this->design, $this->input + ['frame_times' => $times]))->toThrow(ValidationException::class);
    expect(fn () => app(StudioExports::class)->register($this->user, $this->design, $this->input + ['media_ids' => [$this->image->id], 'frame_times' => $times]))->toThrow(ValidationException::class);
})->with([
    'null' => [null], 'scalar' => ['1'], 'object' => [['one' => 0]],
    'time string' => [[['page_id' => 'one', 'time' => '0.5']]],
    'time null' => [[['page_id' => 'one', 'time' => null]]],
    'raw end' => [[['page_id' => 'one', 'time' => 1.2]]],
    'frame end' => [[['page_id' => 'one', 'time' => 29 / 24]]],
    'negative' => [[['page_id' => 'one', 'time' => -0.001]]],
    'nan' => [[['page_id' => 'one', 'time' => NAN]]],
    'other page' => [[['page_id' => 'other', 'time' => 0]]],
    'unknown key' => [[['page_id' => 'one', 'time' => 0, 'url' => 'https://invalid.test']]],
    'duplicate' => [[['page_id' => 'one', 'time' => 0], ['page_id' => 'one', 'time' => 0.5]]],
]);

test('legacy designs reject explicit frame times instead of silently changing their poster', function () {
    $doc = $this->design->document;
    $doc['schema_version'] = 1;
    unset($doc['timeline']);
    $legacy = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Legacy', 'document' => $doc]);
    expect(fn () => app(StudioRenderer::class)->export($this->user, $legacy, $this->input + ['frame_times' => [['page_id' => 'one', 'time' => 0]]]))->toThrow(ValidationException::class);
});

test('server frame preparation does not read resources outside the selected temporal window', function () {
    $future = MediaAsset::factory()->for($this->user)->create(['kind' => 'image', 'mime' => 'image/png', 'width' => 8, 'height' => 8, 'path' => 'media/future-missing.png']);
    app(StudioAssets::class)->attach($this->user, $this->brand, $future->id);
    $doc = $this->design->document;
    $doc['pages'][0]['elements'] = [['id' => 'future', 'type' => 'image', 'asset_id' => $future->id, 'x' => 0, 'y' => 0, 'width' => 8, 'height' => 8,
        'timing' => ['start' => 0.5, 'duration' => 0.5]]];
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Window', 'document' => $doc]);
    Process::fake(function ($process) {
        $input = json_decode($process->input, true);
        expect($input['assets'])->toBe([])->and($input['frame_times'])->toBe([['page_id' => 'one', 'time' => 0]]);
        $image = imagecreatetruecolor(100, 80);
        imagepng($image, $process->command[2].DIRECTORY_SEPARATOR.'page-001.png');
        imagedestroy($image);

        return Process::result(output: '{"files":[{"name":"page-001.png","mime":"image/png"}]}');
    });
    $export = app(StudioRenderer::class)->export($this->user, $design, $this->input + ['frame_times' => [['page_id' => 'one', 'time' => 0]]]);
    app(StudioRenderer::class)->render($export->id);
    expect($export->refresh()->status)->toBe('ready', $export->error ?? '');
});
