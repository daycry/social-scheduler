<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\User;
use App\Services\Studio\StudioAvFiles;
use App\Services\Studio\StudioAvProcess;
use App\Services\Studio\StudioAvProcessFailed;
use App\Services\Studio\StudioAvState;
use App\Services\Studio\StudioAvTransfer;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->document = ['schema_version' => 2, 'width' => 100, 'height' => 80, 'timeline' => ['fps' => 12, 'audio_tracks' => []],
        'pages' => [['id' => 'one', 'duration' => 1, 'background' => '#ffffff', 'elements' => []]]];
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Native bridge', 'document' => $this->document]);
    $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id,
        'revision' => 1, 'format' => 'mp4', 'pages' => ['one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
    $this->state = app(StudioAvState::class);
    $this->export = $this->state->initialize($this->user, $export);
    $this->lease = $this->state->claim($export->id, $this->export->render_state['generation']);
    $this->root = 'studio-exports/'.$this->user->id.'/'.$export->id.'/'.$this->export->render_state['workspace'];
});

function studioProcessPlan(): array
{
    return ['frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 24, 'segmentCount' => 1,
        'selection' => ['one'], 'assetIds' => [], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]];
}

test('AV native bridge writes a private generated manifest invokes the managed supervisor and removes work files', function () {
    Process::fake(function ($process) {
        expect($process->command[1])->toBe(base_path('scripts/studio/av-runtime.py'));
        expect(array_slice($process->command, 3))->toBe([$this->lease->id, 'plan', '0']);
        $manifest = json_decode(file_get_contents($process->command[2].'/work/'.$this->lease->id.'.json'), true);
        expect($manifest['document'])->toBe($this->document)->and($manifest['options']['pageIds'])->toBe(['one']);
        expect($manifest['assets'])->toBe([])->and($manifest['fonts'])->toBe([])->and($manifest['audioSources'])->toBe([])->and($manifest['segments'])->toBe([]);
        expect($process->timeout)->toBeLessThan(100);

        return Process::result(output: json_encode(['ok' => true, 'namespace' => $this->lease->id, 'phase' => 'plan', 'index' => 0,
            'result' => studioProcessPlan(), 'memory' => ['scope' => 'job', 'limit' => 3 * 1024 ** 3, 'kill_on_close' => true, 'breakaway' => false]]));
    });
    $result = app(StudioAvProcess::class)->execute($this->lease);
    expect($result['frameCount'] ?? null)->toBe(12);
    expect(Storage::disk('local')->allFiles($this->root.'/work'))->toBe([]);
    expect($this->state->accept($this->lease, $result))->toBeTrue();
});

test('AV native bridge rejects forged phase or OS proof and cleans its private manifest on every failure', function (string $fault) {
    Process::fake(function ($process) use ($fault) {
        $output = ['ok' => true, 'namespace' => $this->lease->id, 'phase' => 'plan', 'index' => 0, 'result' => studioProcessPlan(),
            'memory' => ['scope' => 'job', 'limit' => 3 * 1024 ** 3, 'kill_on_close' => true, 'breakaway' => false]];
        if ($fault === 'namespace') {
            $output['namespace'] = 'other';
        }
        if ($fault === 'index') {
            $output['index'] = 1;
        }
        if ($fault === 'memory') {
            $output['memory']['limit'] = 8 * 1024 ** 3;
        }
        if ($fault === 'breakaway') {
            $output['memory']['breakaway'] = true;
        }
        if ($fault === 'cancel') {
            $this->state->cancel($this->user, $this->export);
        }

        return Process::result(output: json_encode($output));
    });
    expect(fn () => app(StudioAvProcess::class)->execute($this->lease))->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->allFiles($this->root.'/work'))->toBe([]);
})->with(['namespace', 'index', 'memory', 'breakaway', 'cancel']);

test('AV native bridge shares a finite deadline and never starts heavy work with an exhausted budget', function () {
    Process::fake();
    expect(fn () => app(StudioAvProcess::class)->execute($this->lease, microtime(true) - 1))->toThrow(StudioAvProcessFailed::class);
    Process::assertNothingRan();
    expect(Storage::disk('local')->allFiles($this->root.'/work'))->toBe([]);
});

test('AV native Laravel bridge renders and assembles an actual MP4 under the inherited OS group limit', function () {
    $bridge = app(StudioAvProcess::class);
    $plan = $bridge->execute($this->lease);
    expect($plan['runtime_memory']['limit'])->toBeLessThanOrEqual(3 * 1024 ** 3)->toBeGreaterThan(0);
    expect($this->state->accept($this->lease, $plan))->toBeTrue();
    $generation = $this->export->render_state['generation'];
    $render = $this->state->claim($this->export->id, $generation, 512 * 1024 ** 2);
    $deadline = microtime(true) + 85;
    $segment = $bridge->execute($render, $deadline);
    app(StudioAvFiles::class)->inspect($render, $segment, $deadline);
    expect($this->state->accept($render, $segment, $segment['size']))->toBeTrue();
    $assemble = $this->state->claim($this->export->id, $generation, 8 * 1024 ** 3);
    $deadline = microtime(true) + 85;
    $final = $bridge->execute($assemble, $deadline);
    app(StudioAvFiles::class)->inspect($assemble, $final, $deadline);
    expect($this->state->accept($assemble, $final, $final['size']))->toBeTrue();
    expect($final['videoCodec'])->toBe('h264')->and($final['frameCount'])->toBe(12)
        ->and($final['width'])->toBe(100)->and($final['height'])->toBe(80)->and($final['audioCodec'])->toBeNull();
    expect($this->state->task($this->export->refresh()))->toBe(['phase' => 'register', 'index' => 0]);
    expect($this->export->status)->toBe('processing');
    expect(Storage::disk('local')->allFiles($this->root.'/work'))->toBe([]);
    expect($final['chunk_size'])->toBe(16 * 1024 ** 2)->and($final['chunk_sha256'])->toBe([$final['sha256']]);
    config(['filesystems.media' => 'local']);
    $registration = $this->state->claim($this->export->id, $generation);
    $result = null;
    for ($pass = 0; $pass < 5 && $result === null; $pass++) {
        $result = app(StudioAvTransfer::class)->run($registration);
    }
    expect($result)->toHaveKey('media_id');
    $media = MediaAsset::withoutUserScope()->findOrFail($result['media_id']);
    expect(hash_file('sha256', Storage::disk('local')->path($media->path)))->toBe($final['sha256']);
    expect($this->state->accept($registration, $result))->toBeTrue();
    expect($this->export->refresh()->status)->toBe('ready')->and($this->export->media_ids)->toBe([$media->id]);
})->skip(fn () => getenv('STUDIO_AV_NATIVE_TEST') !== '1', 'Explicit managed native runtime opt-in; no ONNX.');
