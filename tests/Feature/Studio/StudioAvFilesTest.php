<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAsset;
use App\Models\Studio\StudioFont;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioAvFiles;
use App\Services\Studio\StudioAvState;
use App\Services\Studio\StudioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\StudioAudioR2;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->bytes = str_repeat('private source bytes', 100);
    Storage::disk('local')->put('source.bin', $this->bytes);
    $this->media = MediaAsset::factory()->for($this->user)->create(['disk' => 'local', 'path' => 'source.bin',
        'size' => strlen($this->bytes), 'declared_size' => strlen($this->bytes), 'kind' => 'image', 'mime' => 'image/png', 'status' => MediaAsset::STATUS_READY]);
    app(StudioAssets::class)->attach($this->user, $this->brand, $this->media->id);
    $document = ['schema_version' => 2, 'width' => 100, 'height' => 80, 'timeline' => ['fps' => 12, 'audio_tracks' => []],
        'pages' => [['id' => 'page_one', 'duration' => 1, 'background' => '#ffffff', 'elements' => []]]];
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'AV files', 'document' => $document]);
    $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id,
        'revision' => 1, 'format' => 'mp4', 'pages' => ['page_one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
    $this->state = app(StudioAvState::class);
    $this->export = $this->state->initialize($this->user, $export);
    $generation = $this->export->render_state['generation'];
    $plan = ['frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 12, 'segmentCount' => 1,
        'selection' => ['page_one'], 'assetIds' => [$this->media->id], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]];
    $this->state->accept($this->state->claim($export->id, $generation), $plan);
    $this->lease = $this->state->claim($export->id, $generation, strlen($this->bytes));
    $this->root = 'studio-exports/'.$this->user->id.'/'.$export->id.'/'.$this->export->render_state['workspace'];
});

test('AV snapshot streams an authorized complete source into its immutable private lease', function () {
    $result = app(StudioAvFiles::class)->snapshot($this->lease);
    $file = 'imports/'.$this->lease->id.'/asset-'.$this->media->id.'.bin';
    expect($result)->toHaveKeys(['file', 'namespace', 'size', 'sha256']);
    expect($result['file'])->toBe($file)->and($result['namespace'])->toBe($this->lease->id)
        ->and($result['size'])->toBe(strlen($this->bytes))->and($result['sha256'])->toBe(hash('sha256', $this->bytes));
    expect(Storage::disk('local')->get($this->root.'/'.$file))->toBe($this->bytes);
    expect($this->state->accept($this->lease, $result, $result['size']))->toBeTrue();
    Storage::disk('local')->put('source.bin', 'changed upload');
    expect(Storage::disk('local')->get($this->root.'/'.$file))->toBe($this->bytes);
});

test('AV workspace directory matches the native renderer attempt contract', function () {
    app(StudioAvFiles::class)->snapshot($this->lease);
    $relative = 'studio-exports/'.$this->user->id.'/'.$this->export->id.'/'.$this->export->render_state['workspace'];
    expect(is_dir(Storage::disk('local')->path($relative)))->toBeTrue();
});

test('AV private output paths refuse linked ancestors without writing to their target', function () {
    Storage::disk('local')->makeDirectory($this->root);
    Storage::disk('local')->makeDirectory('outside-attempt');
    $link = Storage::disk('local')->path($this->root.'/imports');
    $target = Storage::disk('local')->path('outside-attempt');
    $created = @symlink($target, $link);
    if (! $created && PHP_OS_FAMILY === 'Windows') {
        // NTFS junctions require no symbolic-link privilege; still exercise the actual linked ancestor.
        $process = new Process(['cmd.exe', '/c', 'mklink', '/J', str_replace('/', '\\', $link), str_replace('/', '\\', $target)]);
        $process->run();
        $created = $process->isSuccessful();
    }
    if (! $created) {
        $this->markTestSkipped('Host cannot create directory links.');
    }
    try {
        expect(fn () => app(StudioAvFiles::class)->snapshot($this->lease))->toThrow(RuntimeException::class);
        expect(Storage::disk('local')->allFiles('outside-attempt'))->toBe([]);
    } finally {
        if (PHP_OS_FAMILY === 'Windows') {
            rmdir($link);
        } else {
            unlink($link);
        }
    }
});

test('AV checkpoint verification reads actual bytes and rejects changed content', function () {
    $files = app(StudioAvFiles::class);
    $result = $files->snapshot($this->lease);
    $this->state->accept($this->lease, $result, $result['size']);
    expect($files->verify($this->lease))->toBe($result);
    // Same length corruption must not pass a size-only check.
    Storage::disk('local')->put($this->root.'/'.$result['file'], str_repeat('x', $result['size']));
    expect(fn () => $files->verify($this->lease))->toThrow(RuntimeException::class);
});

test('AV native results are physically checked before acceptance and cannot escape their lease', function () {
    $files = app(StudioAvFiles::class);
    $snapshot = $files->snapshot($this->lease);
    $this->state->accept($this->lease, $snapshot, $snapshot['size']);
    $lease = $this->state->claim($this->export->id, $this->export->render_state['generation'], 100);
    expect($lease->phase)->toBe('render');
    $result = ['file' => 'segments/'.$lease->id.'/video-000000.mp4', 'namespace' => $lease->id,
        'size' => 13, 'sha256' => hash('sha256', 'native result'), 'index' => 0, 'frameCount' => 12];
    Storage::disk('local')->put($this->root.'/'.$result['file'], 'native result');
    expect($files->inspect($lease, $result))->toBe($result);
    $wrong = array_replace($result, ['file' => '../foreign.mp4']);
    expect(fn () => $files->inspect($lease, $wrong))->toThrow(RuntimeException::class);
    Storage::disk('local')->put($this->root.'/'.$result['file'], 'native corrup');
    expect(fn () => $files->inspect($lease, $result))->toThrow(RuntimeException::class);
    expect($lease->refresh()->status)->toBe('running');
    $this->state->cancel($this->user, $this->export);
    expect(fn () => $files->inspect($lease, $result))->toThrow(RuntimeException::class);
});

test('AV source promotion uses only accepted checkpoints and never replaces pinned inputs', function () {
    $files = app(StudioAvFiles::class);
    $result = $files->snapshot($this->lease);
    expect(fn () => $files->publishSource($this->lease))->toThrow(RuntimeException::class);
    Storage::disk('local')->assertMissing($this->root.'/assets/'.$this->media->id.'.bin');
    $this->state->accept($this->lease, $result, $result['size']);
    expect($files->publishSource($this->lease))->toBe('assets/'.$this->media->id.'.bin');
    expect($files->publishSource($this->lease))->toBe('assets/'.$this->media->id.'.bin');
    expect(Storage::disk('local')->get($this->root.'/assets/'.$this->media->id.'.bin'))->toBe($this->bytes);
    // A conflicting fixed input is never silently overwritten, even with a valid import.
    Storage::disk('local')->delete($this->root.'/assets/'.$this->media->id.'.bin');
    Storage::disk('local')->put($this->root.'/assets/'.$this->media->id.'.bin', 'conflict');
    expect(fn () => $files->publishSource($this->lease))->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->get($this->root.'/assets/'.$this->media->id.'.bin'))->toBe('conflict');
});

test('AV snapshot refuses expired cancelled and foreign-brand sources before creating files', function (string $condition) {
    if ($condition === 'expired') {
        $this->travel(181)->seconds();
    } elseif ($condition === 'cancelled') {
        $this->state->cancel($this->user, $this->export);
    } else {
        StudioAsset::withoutUserScope()->where('brand_id', $this->brand->id)->where('media_asset_id', $this->media->id)->delete();
    }
    try {
        app(StudioAvFiles::class)->snapshot($this->lease);
        $this->fail('A stale or unauthorized source was copied.');
    } catch (RuntimeException|ModelNotFoundException $e) {
        expect(Storage::disk('local')->allFiles($this->root))->toBe([]);
    }
})->with(['expired', 'cancelled', 'foreign-brand']);

test('AV snapshot never overwrites a previously published file even for duplicate delivery', function () {
    $files = app(StudioAvFiles::class);
    $first = $files->snapshot($this->lease);
    Storage::disk('local')->put('source.bin', str_repeat('y', strlen($this->bytes)));
    expect(fn () => $files->snapshot($this->lease))->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->get($this->root.'/'.$first['file']))->toBe($this->bytes);
    expect(Storage::disk('local')->allFiles($this->root))->toBe([$this->root.'/'.$first['file']]);
});

test('AV snapshot refuses size mismatch and budget exhaustion before allocating bytes', function (string $condition) {
    if ($condition === 'size') {
        Storage::disk('local')->put('source.bin', 'truncated');
    } else {
        $this->lease->update(['reserved_bytes' => strlen($this->bytes) - 1]);
    }
    expect(fn () => app(StudioAvFiles::class)->snapshot($this->lease))->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->allFiles($this->root))->toBe([]);
})->with(['size', 'budget']);

test('AV remote snapshots use bounded range requests without transport retries', function () {
    $remote = new StudioAudioR2;
    $bytes = str_repeat('r', 2 * 1024 * 1024 + 17);
    $remote->objects['remote.bin'] = $bytes;
    $this->media->update(['disk' => 'audio-test-r2', 'path' => 'remote.bin', 'size' => strlen($bytes)]);
    $this->lease->update(['reserved_bytes' => strlen($bytes)]);
    $result = app(StudioAvFiles::class)->snapshot($this->lease);
    expect($result['sha256'])->toBe(hash('sha256', $bytes));
    expect(Storage::disk('local')->get($this->root.'/'.$result['file']))->toBe($bytes);
    $calls = collect($remote->calls->getArrayCopy());
    expect($calls->pluck('name')->all())->toBe(['HeadObject', 'GetObject', 'GetObject', 'GetObject']);
    expect($calls->where('name', 'GetObject')->pluck('args.Range')->values()->all())->toBe([
        'bytes=0-1048575', 'bytes=1048576-2097151', 'bytes=2097152-2097168',
    ]);
    foreach ($calls as $call) {
        expect($call['args']['@retries'])->toBe(0)->and($call['args']['@http']['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(5);
    }
});

test('AV cancellation during transport removes partial data and cannot publish a late snapshot', function () {
    $remote = new StudioAudioR2;
    $remote->objects['remote.bin'] = $this->bytes;
    $this->media->update(['disk' => 'audio-test-r2', 'path' => 'remote.bin']);
    $state = $this->state;
    $user = $this->user;
    $export = $this->export;
    Storage::disk('audio-test-r2')->getClient()->getHandlerList()->appendInit(function (callable $next) use ($state, $user, $export) {
        return function ($command, $request) use ($next, $state, $user, $export) {
            if ($command->getName() === 'GetObject') {
                $state->cancel($user, $export);
            }

            return $next($command, $request);
        };
    }, 'cancel-av-test');
    expect(fn () => app(StudioAvFiles::class)->snapshot($this->lease))->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->allFiles($this->root))->toBe([]);
    expect($this->lease->refresh()->status)->toBe('abandoned')->and($this->lease->reserved_bytes)->toBe(strlen($this->bytes));
});

test('AV font snapshot preserves the normalized font hash and rejects same-size source corruption', function () {
    $bytes = file_get_contents(base_path('tests/Fixtures/fonts/SpaceGrotesk-Variable.woff2'));
    Storage::disk('local')->put('font.woff2', str_repeat('x', strlen($bytes)));
    $font = StudioFont::withoutUserScope()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id,
        'name' => 'Brand font', 'family' => 'Space Grotesk', 'style' => 'normal', 'weight' => 400, 'format' => 'woff2',
        'mime' => 'font/woff2', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'source_sha256' => hash('sha256', $bytes),
        'disk' => 'local', 'path' => 'font.woff2', 'status' => 'ready']);
    $this->state->cancel($this->user, $this->export);
    $export = $this->state->retry($this->user, $this->export);
    $generation = $export->render_state['generation'];
    $plan = ['frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 12, 'segmentCount' => 1,
        'selection' => ['page_one'], 'assetIds' => [], 'fontIds' => [$font->id], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]];
    $this->state->accept($this->state->claim($export->id, $generation), $plan);
    $lease = $this->state->claim($export->id, $generation, strlen($bytes));
    expect(fn () => app(StudioAvFiles::class)->snapshot($lease))->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->allFiles($this->root))->toBe([]);
    Storage::disk('local')->put('font.woff2', $bytes);
    $result = app(StudioAvFiles::class)->snapshot($lease);
    expect($result['sha256'])->toBe($font->sha256);
    $this->state->accept($lease, $result, $result['size']);
    expect(app(StudioAvFiles::class)->publishSource($lease))->toBe('fonts/'.$font->id.'.woff2');
    expect(Storage::disk('local')->get($this->root.'/fonts/'.$font->id.'.woff2'))->toBe($bytes);
});
