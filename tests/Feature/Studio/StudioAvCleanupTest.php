<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvWorkspace;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioAvCheckpointCorrupt;
use App\Services\Studio\StudioAvCleanup;
use App\Services\Studio\StudioAvFiles;
use App\Services\Studio\StudioAvState;
use App\Services\Studio\StudioAvStorage;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $bytes = 'private snapshot';
    Storage::disk('local')->put('source.bin', $bytes);
    $this->media = MediaAsset::factory()->for($this->user)->create(['disk' => 'local', 'path' => 'source.bin',
        'size' => strlen($bytes), 'declared_size' => strlen($bytes), 'kind' => 'image', 'mime' => 'image/png', 'status' => MediaAsset::STATUS_READY]);
    app(StudioAssets::class)->attach($this->user, $this->brand, $this->media->id);
    $document = ['schema_version' => 2, 'width' => 100, 'height' => 80, 'timeline' => ['fps' => 12, 'audio_tracks' => []],
        'pages' => [['id' => 'one', 'duration' => 1, 'background' => '#ffffff', 'elements' => []]]];
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'AV cleanup', 'document' => $document]);
    $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id,
        'revision' => 1, 'format' => 'mp4', 'pages' => ['one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
    $this->state = app(StudioAvState::class);
    $this->export = $this->state->initialize($this->user, $export);
    $this->plan = ['frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 12, 'segmentCount' => 1,
        'selection' => ['one'], 'assetIds' => [$this->media->id], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]];
    $this->generation = $this->export->render_state['generation'];
    $this->state->accept($this->state->claim($export->id, $this->generation), $this->plan);
    $this->lease = $this->state->claim($export->id, $this->generation, strlen($bytes));
    $this->result = app(StudioAvFiles::class)->snapshot($this->lease);
    $this->workspace = StudioAvWorkspace::withoutUserScope()->findOrFail($this->export->render_state['allocation_id']);
    $this->root = 'studio-exports/'.$this->user->id.'/'.$export->id.'/'.$this->workspace->uuid;
});

test('AV cleanup releases abandoned reservations only after their private files are deleted', function () {
    $cleanup = app(StudioAvCleanup::class);
    expect($cleanup->lease($this->lease->id))->toBeFalse();
    Storage::disk('local')->assertExists($this->root.'/'.$this->result['file']);
    $this->state->cancel($this->user, $this->export);
    expect($cleanup->lease($this->lease->id))->toBeFalse();
    $this->travel(181)->seconds();
    expect($cleanup->lease($this->lease->id))->toBeTrue();
    expect($this->lease->refresh()->status)->toBe('released')->and($this->lease->actual_bytes)->toBe(0);
    Storage::disk('local')->assertMissing($this->root.'/'.$this->result['file']);
    Storage::disk('local')->assertExists('source.bin');
    expect($cleanup->lease($this->lease->id))->toBeFalse();
});

test('AV cleanup removes concat controls from its own lease while preserving controls of other deliveries', function () {
    $this->state->cancel($this->user, $this->export);
    $this->travel(181)->seconds();
    $own = $this->root.'/'.$this->lease->id.'-'.Str::uuid().'.video.txt';
    $other = $this->root.'/'.Str::uuid().'-'.Str::uuid().'.audio.txt';
    Storage::disk('local')->put($own, 'private concat input');
    Storage::disk('local')->put($other, 'another delivery');
    expect(app(StudioAvCleanup::class)->lease($this->lease->id))->toBeTrue();
    Storage::disk('local')->assertMissing($own);
    Storage::disk('local')->assertExists($other);
});

test('AV orphan cleanup preserves live allocations then removes only the vanished export workspace', function () {
    $cleanup = app(StudioAvCleanup::class);
    expect($cleanup->orphan($this->workspace->id))->toBeFalse();
    $this->brand->delete();
    expect($cleanup->orphan($this->workspace->id))->toBeFalse();
    $this->travel(181)->seconds();
    Storage::disk('local')->put('studio-exports/unrelated/keep.txt', 'preserved');
    expect($cleanup->orphan($this->workspace->id))->toBeTrue();
    expect(StudioAvWorkspace::withoutUserScope()->find($this->workspace->id))->toBeNull();
    expect(StudioAvLease::find($this->lease->id))->toBeNull();
    expect(Storage::disk('local')->exists($this->root))->toBeFalse();
    Storage::disk('local')->assertExists('studio-exports/unrelated/keep.txt');
    Storage::disk('local')->assertExists('source.bin');
});

test('AV filesystem mutex refuses a second holder and protects snapshot readers from cleanup', function () {
    $storage = app(StudioAvStorage::class);
    expect(fn () => $storage->locked($this->workspace->id, fn () => $storage->locked($this->workspace->id, fn () => true)))
        ->toThrow(RuntimeException::class);
    $this->state->accept($this->lease, $this->result, $this->result['size']);
    $storage->locked($this->workspace->id, function () {
        expect(fn () => app(StudioAvFiles::class)->verify($this->lease))->toThrow(RuntimeException::class);
    });
    expect(app(StudioAvFiles::class)->verify($this->lease))->toBe($this->result);
});

test('AV corrupt checkpoint recovery blocks every generation until physical retirement then restores the source', function () {
    $files = app(StudioAvFiles::class);
    $this->state->accept($this->lease, $this->result, $this->result['size']);
    $files->publishSource($this->lease);
    Storage::disk('local')->put($this->root.'/'.$this->result['file'], str_repeat('x', $this->result['size']));
    $this->state->cancel($this->user, $this->export);
    $retry = $this->state->retry($this->user, $this->export);
    $generation = $retry->render_state['generation'];
    $this->state->accept($this->state->claim($retry->id, $generation), $this->plan);
    $verification = $this->state->claim($retry->id, $generation);
    expect($verification->phase)->toBe('verify');
    expect(fn () => $files->verify($this->lease))->toThrow(RuntimeException::class);
    $position = $retry->refresh()->render_state['position'];
    expect($this->state->rejectCheckpoint($verification))->toBeTrue();
    expect($this->lease->refresh()->status)->toBe('invalid');
    expect($retry->refresh()->render_state['position'])->toBe($position);
    expect($this->state->task($retry))->toBe(['phase' => 'asset', 'index' => $this->media->id]);
    expect($this->state->claim($retry->id, $generation, $this->result['size']))->toBeNull();
    expect($this->state->rejectCheckpoint($verification))->toBeFalse();
    // A second explicit retry must not bypass the source-retirement barrier.
    $this->state->cancel($this->user, $retry);
    $retry = $this->state->retry($this->user, $retry);
    $generation = $retry->render_state['generation'];
    $this->state->accept($this->state->claim($retry->id, $generation), $this->plan);
    expect($this->state->claim($retry->id, $generation, $this->result['size']))->toBeNull();
    $this->travel(181)->seconds();
    expect(app(StudioAvCleanup::class)->lease($this->lease->id))->toBeTrue();
    Storage::disk('local')->assertMissing($this->root.'/assets/'.$this->media->id.'.bin');
    $replacement = $this->state->claim($retry->id, $generation, $this->result['size']);
    $result = $files->snapshot($replacement);
    expect($result['sha256'])->toBe($this->result['sha256']);
    expect($this->state->accept($replacement, $result, $result['size']))->toBeTrue();
    $files->publishSource($replacement);
    expect(Storage::disk('local')->get($this->root.'/assets/'.$this->media->id.'.bin'))->toBe('private snapshot');
});

test('AV corruption has a distinct failure from a busy workspace so only damaged checkpoints are retired', function () {
    $this->state->accept($this->lease, $this->result, $this->result['size']);
    Storage::disk('local')->put($this->root.'/'.$this->result['file'], str_repeat('x', $this->result['size']));
    expect(fn () => app(StudioAvFiles::class)->verify($this->lease))->toThrow(StudioAvCheckpointCorrupt::class);
});

test('AV recovery cannot mix a changed original resource with segments already pinned to its previous bytes', function () {
    $this->state->accept($this->lease, $this->result, $this->result['size']);
    $this->state->cancel($this->user, $this->export);
    $retry = $this->state->retry($this->user, $this->export);
    $generation = $retry->render_state['generation'];
    $this->state->accept($this->state->claim($retry->id, $generation), $this->plan);
    $verification = $this->state->claim($retry->id, $generation);
    expect($this->state->rejectCheckpoint($verification))->toBeTrue();
    $this->travel(181)->seconds();
    expect(app(StudioAvCleanup::class)->lease($this->lease->id))->toBeTrue();
    Storage::disk('local')->put('source.bin', str_repeat('z', $this->result['size']));
    $replacement = $this->state->claim($retry->id, $generation, $this->result['size']);
    expect(fn () => app(StudioAvFiles::class)->snapshot($replacement))->toThrow(RuntimeException::class);
    expect($replacement->refresh()->status)->toBe('running');
    Storage::disk('local')->assertMissing($this->root.'/imports/'.$replacement->id.'/asset-'.$this->media->id.'.bin');
});

test('AV cleanup preserves accepted inputs and cannot reclaim a workspace whose export still exists', function () {
    $this->state->accept($this->lease, $this->result, $this->result['size']);
    app(StudioAvFiles::class)->publishSource($this->lease);
    $this->travel(181)->seconds();
    expect(app(StudioAvCleanup::class)->lease($this->lease->id))->toBeFalse();
    expect(app(StudioAvCleanup::class)->orphan($this->workspace->id))->toBeFalse();
    expect($this->lease->refresh()->status)->toBe('accepted');
    expect(app(StudioAvFiles::class)->verify($this->lease))->toBe($this->result);
    Storage::disk('local')->assertExists($this->root.'/assets/'.$this->media->id.'.bin');
});

test('AV source pin lookup uses resource-specific index keys rather than scanning the snapshot category', function () {
    $this->state->accept($this->lease, $this->result, $this->result['size']);
    $this->state->cancel($this->user, $this->export);
    $retry = $this->state->retry($this->user, $this->export);
    $generation = $retry->render_state['generation'];
    $this->state->accept($this->state->claim($retry->id, $generation), $this->plan);
    $verify = $this->state->claim($retry->id, $generation);
    $this->state->rejectCheckpoint($verify);
    $this->travel(181)->seconds();
    app(StudioAvCleanup::class)->lease($this->lease->id);
    $replacement = $this->state->claim($retry->id, $generation, $this->result['size']);
    DB::enableQueryLog();
    try {
        app(StudioAvFiles::class)->snapshot($replacement);
        $query = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'studio_av_leases') && str_contains($q['query'], '"result" is not null'));
        expect($query)->not->toBeNull();
        $plan = DB::select('EXPLAIN QUERY PLAN '.$query['query'], $query['bindings']);
        $details = implode(' ', array_map(fn ($row) => $row->detail, $plan));
        expect($details)->toContain('workspace_id=? AND phase=? AND item=?');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('AV cleanup refuses a busy workspace then reclaims an expired running delivery without accepting its late reply', function () {
    $this->travel(181)->seconds();
    app(StudioAvStorage::class)->locked($this->workspace->id, function () {
        expect(app(StudioAvCleanup::class)->lease($this->lease->id))->toBeFalse();
        expect($this->lease->refresh()->status)->toBe('running');
        Storage::disk('local')->assertExists($this->root.'/'.$this->result['file']);
    });
    expect(app(StudioAvCleanup::class)->lease($this->lease->id))->toBeTrue();
    expect($this->state->accept($this->lease, $this->result, $this->result['size']))->toBeFalse();
    expect($this->state->claim($this->export->id, $this->generation, $this->result['size']))->not->toBeNull();
});

test('AV cleanup retains accounting after an I/O error and never holds database locks across file removal', function () {
    $this->state->cancel($this->user, $this->export);
    $this->travel(181)->seconds();
    $storage = Mockery::mock(StudioAvStorage::class)->makePartial();
    $storage->shouldReceive('removeLease')->once()->andReturnUsing(function ($workspace, $lease) {
        expect(DB::transactionLevel())->toBe(1); // RefreshDatabase owns the outer test transaction; no service transaction remains.
        expect($lease->refresh()->status)->toBe('abandoned')->and($lease->reserved_bytes)->toBe($this->result['size']);
        throw new RuntimeException('simulated local I/O failure');
    });
    $this->app->instance(StudioAvStorage::class, $storage);
    expect(fn () => app(StudioAvCleanup::class)->lease($this->lease->id))->toThrow(RuntimeException::class, 'simulated local I/O failure');
    expect($this->lease->refresh()->status)->toBe('abandoned')->and($this->lease->actual_bytes)->toBeNull();
    Storage::disk('local')->assertExists($this->root.'/'.$this->result['file']);
    $this->app->forgetInstance(StudioAvStorage::class);
    expect(app(StudioAvCleanup::class)->lease($this->lease->id))->toBeTrue();
});

test('AV workspace lock is enforced by a second PHP process and remains the same file after release', function () {
    $path = Storage::disk('local')->path('studio-av-locks/'.$this->workspace->uuid.'.lock');
    $code = '$h=fopen($argv[1],"c+b");echo flock($h,LOCK_EX|LOCK_NB)?"acquired":"busy";fclose($h);';
    app(StudioAvStorage::class)->locked($this->workspace->id, function () use ($path, $code) {
        $process = new Process([PHP_BINARY, '-r', $code, $path]);
        $process->mustRun();
        expect($process->getOutput())->toBe('busy');
    });
    expect(is_file($path))->toBeTrue();
    $process = new Process([PHP_BINARY, '-r', $code, $path]);
    $process->mustRun();
    expect($process->getOutput())->toBe('acquired');
});

test('AV a refused nested filesystem lock cannot revoke the original holders cleanup capability', function () {
    $this->state->cancel($this->user, $this->export);
    $this->travel(181)->seconds();
    $storage = app(StudioAvStorage::class);
    expect(fn () => $storage->removeLease($this->workspace, $this->lease->refresh()))->toThrow(RuntimeException::class);
    $storage->locked($this->workspace->id, function ($workspace) use ($storage) {
        expect(fn () => $storage->locked($workspace->id, fn () => true))->toThrow(RuntimeException::class);
        $storage->removeLease($workspace, $this->lease->refresh());
        Storage::disk('local')->assertMissing($this->root.'/'.$this->result['file']);
    });
    expect(app(StudioAvCleanup::class)->lease($this->lease->id))->toBeTrue();
});

test('AV cleanup refuses linked descendants and retains the journal until a safe retry completes', function () {
    $this->state->cancel($this->user, $this->export);
    $this->travel(181)->seconds();
    Storage::disk('local')->put('outside/keep.txt', 'do not delete');
    $target = Storage::disk('local')->path('outside');
    $link = Storage::disk('local')->path($this->root.'/imports/'.$this->lease->id.'/foreign');
    $created = @symlink($target, $link);
    if (! $created && PHP_OS_FAMILY === 'Windows') {
        $process = new Process(['cmd.exe', '/c', 'mklink', '/J', str_replace('/', '\\', $link), str_replace('/', '\\', $target)]);
        $process->run();
        $created = $process->isSuccessful();
    }
    if (! $created) {
        $this->markTestSkipped('Host cannot create directory links.');
    }
    try {
        expect(fn () => app(StudioAvCleanup::class)->lease($this->lease->id))->toThrow(RuntimeException::class);
        expect($this->lease->refresh()->status)->toBe('abandoned')->and($this->lease->actual_bytes)->toBeNull();
        expect(Storage::disk('local')->get('outside/keep.txt'))->toBe('do not delete');
    } finally {
        PHP_OS_FAMILY === 'Windows' ? rmdir($link) : unlink($link);
    }
    expect(app(StudioAvCleanup::class)->lease($this->lease->id))->toBeTrue();
    Storage::disk('local')->assertExists('outside/keep.txt');
});
