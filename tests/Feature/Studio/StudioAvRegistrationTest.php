<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAsset;
use App\Models\User;
use App\Services\MediaUploadService;
use App\Services\Studio\StudioAvState;
use App\Services\Studio\StudioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $document = ['schema_version' => 2, 'width' => 100, 'height' => 80, 'timeline' => ['fps' => 12, 'audio_tracks' => []],
        'pages' => [['id' => 'one', 'duration' => 1, 'background' => '#ffffff', 'elements' => []]]];
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Registration', 'document' => $document]);
    $this->export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id,
        'revision' => 1, 'format' => 'mp4', 'pages' => ['one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
    $this->state = app(StudioAvState::class);
    $this->export = $this->state->initialize($this->user, $this->export);
    $generation = $this->export->render_state['generation'];
    $plan = ['frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 24, 'segmentCount' => 1,
        'selection' => ['one'], 'assetIds' => [], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]];
    $this->state->accept($this->state->claim($this->export->id, $generation), $plan);
    $segment = $this->state->claim($this->export->id, $generation, 5);
    $this->state->accept($segment, ['namespace' => $segment->id, 'file' => 'segments/'.$segment->id.'/video-000000.mp4',
        'size' => 5, 'sha256' => hash('sha256', 'video')], 5);
    $this->source = $this->state->claim($this->export->id, $generation, 5);
    $this->result = ['namespace' => $this->source->id, 'file' => 'outputs/'.$this->source->id.'/project.mp4',
        'size' => 5, 'sha256' => hash('sha256', 'video'), 'width' => 100, 'height' => 80,
        'duration' => 1, 'fps' => 12, 'frameCount' => 12, 'videoCodec' => 'h264', 'audioCodec' => null, 'scale' => 1, 'quality' => 0.94];
    $this->state->accept($this->source, $this->result, 5);
    $this->lease = $this->state->claim($this->export->id, $generation);
});

test('AV registration refuses an unrelated ready library asset even in the same brand', function () {
    $media = MediaAsset::factory()->for($this->user)->video()->create();
    StudioAsset::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'media_asset_id' => $media->id]);
    expect(fn () => $this->state->accept($this->lease, ['media_id' => $media->id]))->toThrow(ValidationException::class);
    expect($this->export->refresh()->status)->toBe('processing')->and($this->export->media_ids)->toBeNull();
});

test('AV library copy reserves output bytes before allocating its pending medium', function () {
    config(['studio.av.disk.output' => 9]);
    expect(fn () => $this->state->allocateRegistration($this->lease))->toThrow(ValidationException::class);
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
});

test('AV transfer progress is fenced and cannot publish or change its immutable identity', function () {
    $media = $this->state->allocateRegistration($this->lease);
    expect($this->state->checkpointRegistration($this->lease, ['bytes' => 5, 'stage' => 'verifying'], 'private-upload'))->toBeTrue();
    expect($media->refresh()->import_state['bytes'])->toBe(5)->and($media->isReady())->toBeFalse();
    expect(fn () => $this->state->checkpointRegistration($this->lease, ['sha256' => str_repeat('0', 64)]))->toThrow(ValidationException::class);
    $this->state->cancel($this->user, $this->export);
    expect($this->state->checkpointRegistration($this->lease, ['verified_bytes' => 5, 'complete' => true]))->toBeFalse();
    expect($media->refresh()->import_state['verified_bytes'])->toBe(0)->and($media->upload_id)->toBe('private-upload');
});

test('AV assemble lookup starts with the indexed lease phase and checks generation membership', function () {
    DB::enableQueryLog();
    try {
        expect($this->state->checkpoints($this->export, ['assemble'])->keys()->all())->toBe(['assemble:0']);
        $query = collect(DB::getQueryLog())->first(fn ($row) => str_contains($row['query'], 'studio_av_leases'));
        $plan = DB::select('EXPLAIN QUERY PLAN '.$query['query'], $query['bindings']);
        expect($plan[0]->detail)->toContain('workspace_id=? AND phase=?');
        expect(implode(' ', array_map(fn ($row) => $row->detail, $plan)))->toContain('generation=? AND result_lease_id=?');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('AV registration allocation is private idempotent and pinned to its accepted assembly', function () {
    $first = $this->state->allocateRegistration($this->lease);
    $second = $this->state->allocateRegistration($this->lease);
    expect($second->id)->toBe($first->id)->and(MediaAsset::withoutUserScope()->count())->toBe(1);
    expect($first->status)->toBe(MediaAsset::STATUS_PENDING)->and($first->disk)->toBe('local')
        ->and($first->path)->toBe('media/'.$this->user->id.'/studio-'.$this->export->render_state['workspace'].'.mp4')
        ->and($first->import_state['sha256'])->toBe($this->result['sha256'])->and($first->import_state['bytes'])->toBe(0);
    expect(StudioAsset::withoutUserScope()->count())->toBe(0)->and($first->toPublicArray())->not->toHaveKey('import_state');
    expect($this->export->refresh()->status)->toBe('processing');
});

test('AV registration cannot publish an incomplete durable transfer', function () {
    $media = $this->state->allocateRegistration($this->lease);
    expect(fn () => $this->state->accept($this->lease, ['media_id' => $media->id]))->toThrow(ValidationException::class);
    expect($media->refresh()->isReady())->toBeFalse()->and(StudioAsset::withoutUserScope()->count())->toBe(0);
});

test('AV registration atomically exposes one verified media and the signed download source', function () {
    $media = $this->state->allocateRegistration($this->lease);
    $journal = $media->import_state;
    $media->update(['import_state' => array_replace($journal, ['bytes' => 5, 'verified_bytes' => 5, 'complete' => true])]);
    expect($this->state->accept($this->lease, ['media_id' => $media->id]))->toBeTrue();
    expect($media->refresh()->isReady())->toBeTrue()->and($media->size)->toBe(5)->and($media->codec)->toBe('h264')
        ->and($media->width)->toBe(100)->and($media->height)->toBe(80);
    $export = $this->export->refresh();
    expect($export->status)->toBe('ready')->and($export->media_ids)->toBe([$media->id])->and($export->disk)->toBe('local')
        ->and($export->path)->toBe('studio-exports/'.$this->user->id.'/'.$export->id.'/'.$export->render_state['workspace'].'/'.$this->result['file']);
    expect(StudioAsset::withoutUserScope()->where('brand_id', $this->brand->id)->where('media_asset_id', $media->id)->count())->toBe(1);
    expect($this->state->accept($this->lease, ['media_id' => $media->id]))->toBeFalse();
});

test('AV cancellation after transfer leaves no ready media or brand attachment', function () {
    $media = $this->state->allocateRegistration($this->lease);
    $media->update(['import_state' => array_replace($media->import_state, ['bytes' => 5, 'verified_bytes' => 5, 'complete' => true])]);
    $this->state->cancel($this->user, $this->export);
    expect($this->state->accept($this->lease, ['media_id' => $media->id]))->toBeFalse();
    expect($media->refresh()->isReady())->toBeFalse()->and(StudioAsset::withoutUserScope()->count())->toBe(0);
    expect(fn () => $this->state->allocateRegistration($this->lease))->toThrow(ModelNotFoundException::class);
});

test('AV pending render allocation cannot be completed by the generic upload endpoint', function (string $method) {
    $media = $this->state->allocateRegistration($this->lease);
    Storage::disk('local')->put($media->path, 'video');
    expect(fn () => app(MediaUploadService::class)->{$method}($media))->toThrow(DomainException::class);
    expect($media->refresh()->status)->toBe(MediaAsset::STATUS_PENDING);
    Storage::disk('local')->assertExists($media->path);
})->with(['complete', 'finalize']);

test('AV generic deletion preserves a pending render allocation and its recovery journal', function () {
    $media = $this->state->allocateRegistration($this->lease);
    Storage::disk('local')->put($media->path, 'video');
    expect(fn () => app(MediaUploadService::class)->delete($media))->toThrow(DomainException::class);
    expect($media->refresh()->status)->toBe(MediaAsset::STATUS_PENDING);
    Storage::disk('local')->assertExists($media->path);
});

test('AV registration fences an expired delivery and reuses its allocation in the next lease', function () {
    $media = $this->state->allocateRegistration($this->lease);
    $this->travel(181)->seconds();
    expect(fn () => $this->state->allocateRegistration($this->lease))->toThrow(ModelNotFoundException::class);
    $lease = $this->state->claim($this->export->id, $this->export->render_state['generation']);
    expect($this->state->allocateRegistration($lease)->id)->toBe($media->id);
    expect(MediaAsset::withoutUserScope()->count())->toBe(1);
});

test('AV generic finalization cannot bypass render ownership using a stale model', function () {
    $media = $this->state->allocateRegistration($this->lease);
    $media->setAttribute('import_state', null);
    Storage::disk('local')->put($media->path, 'video');
    expect(fn () => app(MediaUploadService::class)->finalize($media))->toThrow(DomainException::class);
    expect($media->refresh()->status)->toBe(MediaAsset::STATUS_PENDING);
});

test('AV registration rolls back media visibility if the brand attachment fails', function () {
    $media = $this->state->allocateRegistration($this->lease);
    $media->update(['import_state' => array_replace($media->import_state, ['bytes' => 5, 'verified_bytes' => 5, 'complete' => true])]);
    $event = 'eloquent.creating: '.StudioAsset::class;
    Event::listen($event, fn () => throw new RuntimeException('test interrupted registration'));
    try {
        expect(fn () => $this->state->accept($this->lease, ['media_id' => $media->id]))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
    expect($media->refresh()->status)->toBe(MediaAsset::STATUS_PENDING)->and($this->export->refresh()->status)->toBe('processing')
        ->and($this->export->media_ids)->toBeNull()->and($this->lease->refresh()->status)->toBe('running');
    expect($this->state->accept($this->lease, ['media_id' => $media->id]))->toBeTrue();
    expect(MediaAsset::withoutUserScope()->count())->toBe(1);
});

test('AV registration refuses changing the generated library destination after allocation', function () {
    $media = $this->state->allocateRegistration($this->lease);
    $media->update(['path' => 'media/another.mp4', 'import_state' => array_replace($media->import_state,
        ['bytes' => 5, 'verified_bytes' => 5, 'complete' => true])]);
    expect(fn () => $this->state->accept($this->lease, ['media_id' => $media->id]))->toThrow(ValidationException::class);
    expect($media->refresh()->isReady())->toBeFalse();
});

test('AV registration enforces a lowered library budget when resuming an allocation', function () {
    $this->state->allocateRegistration($this->lease);
    config(['media.max_bytes.video' => 4]);
    expect(fn () => $this->state->allocateRegistration($this->lease))->toThrow(ValidationException::class);
    expect(MediaAsset::withoutUserScope()->count())->toBe(1);
});

test('AV registration validates the native output metadata against its pinned plan', function (string $key, mixed $value) {
    $this->source->update(['result' => array_replace($this->result, [$key => $value])]);
    expect(fn () => $this->state->allocateRegistration($this->lease))->toThrow(ValidationException::class);
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
})->with([['videoCodec', 'vp9'], ['audioCodec', 'aac'], ['width', 101], ['frameCount', 11], ['duration', 0], ['fps', 30]]);

test('AV registration uses the actual padded GIF dimensions for an odd canvas', function () {
    $document = ['schema_version' => 2, 'width' => 101, 'height' => 81, 'timeline' => ['fps' => 12, 'audio_tracks' => []],
        'pages' => [['id' => 'odd', 'duration' => 1, 'background' => '#ffffff', 'elements' => []]]];
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Odd GIF', 'document' => $document]);
    $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id,
        'revision' => 1, 'format' => 'gif', 'pages' => ['odd'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
    $export = $this->state->initialize($this->user, $export);
    $generation = $export->render_state['generation'];
    $plan = ['frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 24, 'segmentCount' => 1,
        'selection' => ['odd'], 'assetIds' => [], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 101, 'height' => 81, 'paddedWidth' => 102, 'paddedHeight' => 82, 'scale' => 1, 'quality' => 0.94]];
    $this->state->accept($this->state->claim($export->id, $generation), $plan);
    foreach (['render', 'assemble'] as $phase) {
        $lease = $this->state->claim($export->id, $generation, 5);
        $result = ['file' => $phase === 'render' ? 'segments/'.$lease->id.'/video-000000.gif' : 'outputs/'.$lease->id.'/project.gif',
            'namespace' => $lease->id, 'size' => 5, 'sha256' => hash('sha256', 'gif'),
            'width' => 102, 'height' => 82, 'fps' => 12, 'frameCount' => 12, 'duration' => 1,
            'videoCodec' => 'gif', 'audioCodec' => null, 'scale' => 1, 'quality' => 0.94];
        $this->state->accept($lease, $result, 5);
    }
    $registration = $this->state->claim($export->id, $generation);
    $media = $this->state->allocateRegistration($registration);
    $media->update(['import_state' => array_replace($media->import_state, ['bytes' => 5, 'verified_bytes' => 5, 'complete' => true])]);
    expect($this->state->accept($registration, ['media_id' => $media->id]))->toBeTrue();
    expect($media->refresh()->kind)->toBe(MediaAsset::KIND_IMAGE)->and($media->mime)->toBe('image/gif')
        ->and($media->width)->toBe(102)->and($media->height)->toBe(82)->and($media->audio_codec)->toBeNull();
});

test('AV HTTP upload and completion refuse the reserved destination before touching its bytes', function (string $operation) {
    $media = $this->state->allocateRegistration($this->lease);
    Storage::disk('local')->put($media->path, 'video');
    $journal = array_replace($media->import_state, ['bytes' => 5, 'verified_bytes' => 5, 'complete' => true]);
    $media->update(['import_state' => $journal]);
    $this->actingAs($this->user);
    $response = $operation === 'upload'
        ? $this->call('PUT', route('media.upload', $media), [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'wrong')
        : $this->postJson(route('media.complete', $media), []);
    expect(Storage::disk('local')->get($media->path))->toBe('video')
        ->and($media->refresh()->import_state)->toBe($journal)->and($media->isReady())->toBeFalse();
    $response->assertStatus(409);
})->with(['upload', 'complete']);
