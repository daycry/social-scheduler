<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvLease;
use App\Models\User;
use App\Services\Studio\StudioAvQueue;
use App\Services\Studio\StudioAvRecovery;
use App\Services\Studio\StudioAvRunner;
use App\Services\Studio\StudioAvState;
use App\Services\Studio\StudioAvTransfer;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    Process::fake();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Delivery', 'document' => [
        'schema_version' => 2, 'width' => 100, 'height' => 80, 'timeline' => ['fps' => 12, 'audio_tracks' => []],
        'pages' => [['id' => 'one', 'duration' => 1, 'background' => '#ffffff', 'elements' => []]]]]);
    $this->state = app(StudioAvState::class);
    $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id,
        'revision' => 1, 'format' => 'mp4', 'pages' => ['one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
    $this->export = $this->state->initialize($this->user, $export);
    $generation = $this->export->render_state['generation'];
    $this->state->accept($this->state->claim($export->id, $generation), [
        'frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 24, 'segmentCount' => 1,
        'selection' => ['one'], 'assetIds' => [], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]]);
    $segment = $this->state->claim($export->id, $generation, 1);
    $this->state->accept($segment, ['file' => 'segments/'.$segment->id.'/video-000000.mp4', 'namespace' => $segment->id,
        'size' => 1, 'sha256' => hash('sha256', 'x')], 1);
    $source = $this->state->claim($export->id, $generation, 5);
    $this->sourceResult = ['file' => 'outputs/'.$source->id.'/project.mp4', 'namespace' => $source->id,
        'size' => 5, 'sha256' => hash('sha256', 'video'), 'width' => 100, 'height' => 80, 'duration' => 1,
        'fps' => 12, 'frameCount' => 12, 'videoCodec' => 'h264', 'audioCodec' => null, 'scale' => 1, 'quality' => 0.94,
        'chunk_size' => 16 * 1024 ** 2, 'chunk_sha256' => [hash('sha256', 'video')]];
    $this->state->accept($source, $this->sourceResult, 5);
    Storage::disk('local')->put('studio-exports/'.$this->user->id.'/'.$export->id.'/'.$this->export->render_state['workspace'].'/'.$this->sourceResult['file'], 'video');
});

test('AV registration worker checkpoints and requeues each part until one ready medium exists', function () {
    expect(app(StudioAvQueue::class)->enqueue($this->export->id))->toBeTrue();
    $first = StudioAvLease::findOrFail($this->export->refresh()->render_state['lease']);
    expect($first->phase)->toBe('register');
    expect(app(StudioAvRunner::class)->run($first->id))->toBeTrue();
    $next = $this->export->refresh()->render_state['lease'];
    expect($first->refresh()->status)->toBe('abandoned')->and($next)->not->toBe($first->id);
    expect($this->export->status)->toBe('processing')->and(MediaAsset::withoutUserScope()->sole()->isReady())->toBeFalse();
    expect(app(StudioAvRunner::class)->run($first->id))->toBeFalse();
    for ($pass = 0; $pass < 6 && $this->export->refresh()->status !== 'ready'; $pass++) {
        app(StudioAvRunner::class)->run($this->export->render_state['lease']);
    }
    expect($this->export->refresh()->status)->toBe('ready')->and($this->export->media_ids)->toHaveCount(1)
        ->and(MediaAsset::withoutUserScope()->count())->toBe(1)->and($this->export->render_progress['percent'])->toEqual(100);
    Process::assertNothingRan();
});

test('AV recovery resumes a register part after a crash between checkpoint and handoff', function () {
    $lease = $this->state->claim($this->export->id, $this->export->render_state['generation']);
    $this->state->begin($lease->id);
    app(StudioAvTransfer::class)->run($lease);
    $media = MediaAsset::withoutUserScope()->sole();
    $this->state->defer($lease);
    expect(app(StudioAvRecovery::class)->run()['enqueued'])->toBe(1);
    expect(MediaAsset::withoutUserScope()->sole()->id)->toBe($media->id);
    expect($this->export->refresh()->render_state['lease'])->not->toBeNull();
    for ($pass = 0; $pass < 6 && $this->export->refresh()->status !== 'ready'; $pass++) {
        app(StudioAvRunner::class)->run($this->export->render_state['lease']);
    }
    expect($this->export->refresh()->status)->toBe('ready')->and($this->export->media_ids)->toBe([$media->id]);
    Process::assertNothingRan();
});

test('AV nonretiring recovery selection keeps its ordered index without a temporary sort', function () {
    DB::enableQueryLog();
    try {
        app(StudioAvRecovery::class)->run();
        $query = collect(DB::getQueryLog())->first(fn ($row) => str_contains($row['query'], 'not exists') && str_contains($row['query'], 'studio_design_exports'));
        $plan = DB::select('EXPLAIN QUERY PLAN '.$query['query'], $query['bindings']);
        expect(implode(' ', array_map(fn ($row) => $row->detail, $plan)))->not->toContain('TEMP B-TREE');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});
