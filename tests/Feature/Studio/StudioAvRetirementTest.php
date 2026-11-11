<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvWorkspace;
use App\Models\User;
use App\Services\Studio\StudioAvCleanup;
use App\Services\Studio\StudioAvRecovery;
use App\Services\Studio\StudioAvRegistrationCleanup;
use App\Services\Studio\StudioAvState;
use App\Services\Studio\StudioAvTransfer;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\StudioAvR2;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->state = app(StudioAvState::class);
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Retirement', 'document' => [
        'schema_version' => 2, 'width' => 100, 'height' => 80, 'timeline' => ['fps' => 12, 'audio_tracks' => []],
        'pages' => [['id' => 'one', 'duration' => 1, 'background' => '#ffffff', 'elements' => []]]]]);
    $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id,
        'revision' => 1, 'format' => 'mp4', 'pages' => ['one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
    $this->export = $this->state->initialize($this->user, $export);
    $generation = $this->export->render_state['generation'];
    $this->state->accept($this->state->claim($export->id, $generation), [
        'frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 24, 'segmentCount' => 1,
        'selection' => ['one'], 'assetIds' => [], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]]);
    $render = $this->state->claim($export->id, $generation, 1);
    $this->state->accept($render, ['file' => 'segments/'.$render->id.'/video-000000.mp4', 'namespace' => $render->id,
        'size' => 1, 'sha256' => hash('sha256', 'x')], 1);
    $source = $this->state->claim($export->id, $generation, 5);
    $this->state->accept($source, ['file' => 'outputs/'.$source->id.'/project.mp4', 'namespace' => $source->id,
        'size' => 5, 'sha256' => hash('sha256', 'video'), 'width' => 100, 'height' => 80, 'duration' => 1,
        'fps' => 12, 'frameCount' => 12, 'videoCodec' => 'h264', 'audioCodec' => null, 'scale' => 1, 'quality' => 0.94,
        'chunk_size' => 16 * 1024 ** 2, 'chunk_sha256' => [hash('sha256', 'video')]], 5);
    $root = 'studio-exports/'.$this->user->id.'/'.$export->id.'/'.$this->export->render_state['workspace'];
    Storage::disk('local')->put($root.'/outputs/'.$source->id.'/project.mp4', 'video');
    $this->lease = $this->state->claim($export->id, $generation);
    $this->workspace = StudioAvWorkspace::withoutUserScope()->findOrFail($this->lease->workspace_id);
});

test('AV storage receipt is durable before I/O and survives deletion of the media owner', function () {
    $r2 = new StudioAvR2;
    app(StudioAvTransfer::class)->run($this->lease);
    $media = MediaAsset::withoutUserScope()->sole();
    $receipt = $this->workspace->refresh()->media_receipt;
    expect($receipt)->toBeArray()->and($receipt['media_id'])->toBe($media->id)->and($receipt['upload_id'])->toBe($media->upload_id)
        ->and($receipt['location']['key'])->toBe('tenant/'.$media->path)->and($receipt['state'])->toBe('active');
    $this->user->delete();
    expect(MediaAsset::withoutUserScope()->find($media->id))->toBeNull()->and($this->workspace->refresh()->media_receipt)->toBe($receipt);
    expect($this->workspace->toArray())->not->toHaveKey('media_receipt');
});

test('AV retirement deletes only expired cancelled local copy and releases its reservation after removal', function () {
    app(StudioAvTransfer::class)->run($this->lease);
    $media = MediaAsset::withoutUserScope()->sole();
    Storage::disk('local')->put('media/unrelated.bin', 'keep');
    $this->state->cancel($this->user, $this->export);
    expect(app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toBeFalse();
    $this->travel(181)->seconds();
    expect(app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toBeTrue();
    expect($this->workspace->refresh()->media_reserved_bytes)->toBe(0)->and($this->workspace->media_id)->toBeNull();
    expect(MediaAsset::withoutUserScope()->find($media->id))->toBeNull();
    Storage::disk('local')->assertMissing($media->path.'.part');
    Storage::disk('local')->assertExists('media/unrelated.bin');
    expect($this->export->refresh()->status)->toBe('cancelled');
});

test('AV retirement aborts known multipart and verifies removal before releasing its bytes', function () {
    $r2 = new StudioAvR2;
    app(StudioAvTransfer::class)->run($this->lease);
    app(StudioAvTransfer::class)->run($this->lease);
    $media = MediaAsset::withoutUserScope()->sole();
    $this->state->cancel($this->user, $this->export);
    $this->travel(181)->seconds();
    expect(app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toBeTrue();
    expect($r2->uploads)->toBe([])->and($this->workspace->refresh()->media_reserved_bytes)->toBe(0);
    expect(MediaAsset::withoutUserScope()->find($media->id))->toBeNull();
    $calls = collect($r2->calls->getArrayCopy());
    expect($calls->where('name', 'AbortMultipartUpload'))->toHaveCount(1)->and($calls->where('name', 'ListParts'))->toHaveCount(1);
    foreach ($calls->filter(fn ($call) => in_array($call['name'], ['AbortMultipartUpload', 'ListParts', 'DeleteObject'], true)) as $call) {
        expect($call['args']['Key'])->toBe('tenant/'.$media->path)->and($call['args']['@retries'])->toBe(0)
            ->and($call['args']['@http']['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(5);
    }
});

test('AV orphan retirement recovers a lost upload id after deleting its owner without touching another key', function () {
    $r2 = new StudioAvR2;
    $r2->after = function ($name) use ($r2) {
        if ($name === 'CreateMultipartUpload') {
            $r2->after = null;
            throw new RuntimeException('fixture lost response');
        }
    };
    expect(fn () => app(StudioAvTransfer::class)->run($this->lease))->toThrow(RuntimeException::class);
    $receipt = $this->workspace->refresh()->media_receipt;
    $r2->uploads['unrelated'] = ['key' => $receipt['location']['key'].'-other', 'parts' => [], 'metadata' => []];
    $this->user->delete();
    $this->travel(181)->seconds();
    expect(app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toBeFalse();
    expect($this->workspace->refresh()->media_reserved_bytes)->toBe(5);
    expect(app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toBeTrue();
    expect(array_keys($r2->uploads))->toBe(['unrelated'])->and($this->workspace->refresh()->media_reserved_bytes)->toBe(0);
});

test('AV retirement keeps journal and reservation after a delete acknowledgement is lost and resumes idempotently', function () {
    $r2 = new StudioAvR2;
    $result = null;
    for ($pass = 0; $pass < 8 && $result === null; $pass++) {
        $result = app(StudioAvTransfer::class)->run($this->lease);
    }
    $media = MediaAsset::withoutUserScope()->findOrFail($result['media_id']);
    $this->state->cancel($this->user, $this->export);
    $this->travel(181)->seconds();
    $r2->after = function ($name) use ($r2) {
        if ($name === 'DeleteObject') {
            $r2->after = null;
            throw new RuntimeException('fixture lost deletion acknowledgement');
        }
    };
    expect(fn () => app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toThrow(RuntimeException::class);
    expect($this->workspace->refresh()->media_retiring)->toBeTrue()->and($this->workspace->media_reserved_bytes)->toBe(5)
        ->and(MediaAsset::withoutUserScope()->find($media->id))->not->toBeNull();
    expect(app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toBeTrue();
    expect($r2->objects)->toBe([])->and($this->workspace->refresh()->media_reserved_bytes)->toBe(0);
});

test('AV orphan cleanup cannot delete the workspace journal before its unknown multipart is retired', function () {
    $r2 = new StudioAvR2;
    $r2->after = function ($name) use ($r2) {
        if ($name === 'CreateMultipartUpload') {
            $r2->after = null;
            throw new RuntimeException('fixture lost response');
        }
    };
    expect(fn () => app(StudioAvTransfer::class)->run($this->lease))->toThrow(RuntimeException::class);
    $this->user->delete();
    $this->travel(181)->seconds();
    expect(app(StudioAvCleanup::class)->orphan($this->workspace->id))->toBeFalse();
    expect(StudioAvWorkspace::withoutUserScope()->find($this->workspace->id))->not->toBeNull();
    expect(app(StudioAvCleanup::class)->orphan($this->workspace->id))->toBeTrue();
    expect($r2->uploads)->toBe([])->and(StudioAvWorkspace::withoutUserScope()->find($this->workspace->id))->toBeNull();
});

test('AV scheduled recovery retires a terminal copy even when all its leases have already been released', function () {
    app(StudioAvTransfer::class)->run($this->lease);
    $this->state->cancel($this->user, $this->export);
    $this->travel(181)->seconds();
    app(StudioAvCleanup::class)->lease($this->lease->id);
    app(StudioAvRecovery::class)->run();
    expect($this->workspace->refresh()->media_reserved_bytes)->toBe(0)->and($this->workspace->media_id)->toBeNull();
    expect($this->export->refresh()->status)->toBe('cancelled');
});

test('AV retirement preserves ready library media when its design export becomes orphaned', function () {
    $result = null;
    for ($pass = 0; $pass < 6 && $result === null; $pass++) {
        $result = app(StudioAvTransfer::class)->run($this->lease);
    }
    $this->state->accept($this->lease, $result);
    $media = MediaAsset::withoutUserScope()->findOrFail($result['media_id']);
    expect($this->workspace->refresh()->media_receipt['state'])->toBe('published');
    $this->export->delete();
    $this->travel(181)->seconds();
    expect(app(StudioAvCleanup::class)->orphan($this->workspace->id))->toBeTrue();
    expect($media->refresh()->isReady())->toBeTrue();
    Storage::disk('local')->assertExists($media->path);
});

test('AV retirement retains the upload id while in-flight parts still appear after abort', function () {
    $r2 = new StudioAvR2;
    app(StudioAvTransfer::class)->run($this->lease);
    app(StudioAvTransfer::class)->run($this->lease);
    $saved = $r2->uploads;
    $this->state->cancel($this->user, $this->export);
    $this->travel(181)->seconds();
    $r2->after = function ($name) use ($r2, $saved) {
        if ($name === 'AbortMultipartUpload') {
            $r2->after = null;
            $r2->uploads = $saved;
        }
    };
    expect(app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toBeFalse();
    expect($this->workspace->refresh()->media_receipt['upload_id'])->not->toBeNull()->and($this->workspace->media_reserved_bytes)->toBe(5);
    expect(app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toBeTrue();
    expect($r2->uploads)->toBe([]);
});

test('AV recovery completes an interrupted retirement after retry even while the new generation holds a lease', function () {
    $r2 = new StudioAvR2;
    app(StudioAvTransfer::class)->run($this->lease);
    $this->state->cancel($this->user, $this->export);
    $this->travel(181)->seconds();
    $r2->after = function ($name) use ($r2) {
        if ($name === 'DeleteObject') {
            $r2->after = null;
            throw new RuntimeException('fixture lost deletion acknowledgement');
        }
    };
    expect(fn () => app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toThrow(RuntimeException::class);
    $retry = $this->state->retry($this->user, $this->export);
    $next = $this->state->claim($retry->id, $retry->render_state['generation']);
    expect($next->expires_at->isFuture())->toBeTrue();
    app(StudioAvRecovery::class)->run();
    expect($this->workspace->refresh()->media_reserved_bytes)->toBe(0)->and($this->workspace->media_retiring)->toBeFalse();
    expect($this->export->refresh()->status)->toBe('processing')->and($this->export->render_state['lease'])->toBe($next->id);
});

test('AV retirement paginates unrelated multipart prefixes without deleting another key', function () {
    $r2 = new StudioAvR2;
    $result = null;
    for ($pass = 0; $pass < 6 && $result === null; $pass++) {
        $result = app(StudioAvTransfer::class)->run($this->lease);
    }
    $key = $this->workspace->refresh()->media_receipt['location']['key'];
    for ($i = 0; $i < 20; $i++) {
        $r2->uploads['unrelated-'.$i] = ['key' => $key.'-'.sprintf('%03d', $i), 'metadata' => [], 'parts' => []];
    }
    $this->state->cancel($this->user, $this->export);
    $this->travel(181)->seconds();
    expect(app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toBeFalse();
    expect($this->workspace->refresh()->media_reserved_bytes)->toBe(5)->and($this->workspace->media_receipt['cursor'])->not->toBe([]);
    expect(app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toBeTrue();
    expect($r2->uploads)->toHaveCount(20)->and($r2->objects)->toBe([]);
});

test('AV retirement targets the original S3 key after its configured prefix changes', function () {
    $r2 = new StudioAvR2;
    app(StudioAvTransfer::class)->run($this->lease);
    $receipt = $this->workspace->refresh()->media_receipt;
    $r2->setPrefix('different');
    expect(fn () => app(StudioAvTransfer::class)->run($this->lease))->toThrow(ValidationException::class);
    $this->state->cancel($this->user, $this->export);
    $this->travel(181)->seconds();
    expect(app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toBeTrue();
    foreach (collect($r2->calls->getArrayCopy())->filter(fn ($call) => in_array($call['name'], ['AbortMultipartUpload', 'DeleteObject'], true)) as $call) {
        expect($call['args']['Key'])->toBe($receipt['location']['key']);
    }
});

test('AV retirement does not touch an expired but still resumable processing copy', function () {
    app(StudioAvTransfer::class)->run($this->lease);
    $media = MediaAsset::withoutUserScope()->sole();
    $this->travel(181)->seconds();
    expect(app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toBeFalse();
    expect($this->workspace->refresh()->media_retiring)->toBeFalse()->and($this->workspace->media_reserved_bytes)->toBe(5);
    Storage::disk('local')->assertExists($media->path.'.part');
});

test('AV retirement refuses an exhausted caller budget before claiming its cleanup token', function () {
    app(StudioAvTransfer::class)->run($this->lease);
    $this->state->cancel($this->user, $this->export);
    $this->travel(181)->seconds();
    expect(fn () => app(StudioAvRegistrationCleanup::class)->run($this->workspace->id, microtime(true) - 1))->toThrow(RuntimeException::class);
    expect($this->workspace->refresh()->media_retiring)->toBeFalse();
});

test('AV scheduled retirement backoff is respected by every recovery branch', function (bool $orphan) {
    $r2 = new StudioAvR2;
    $result = null;
    for ($pass = 0; $pass < 6 && $result === null; $pass++) {
        $result = app(StudioAvTransfer::class)->run($this->lease);
    }
    if ($orphan) {
        $this->user->delete();
    } else {
        $this->state->cancel($this->user, $this->export);
    }
    $this->travel(181)->seconds();
    $r2->after = function ($name) {
        if ($name === 'DeleteObject') {
            throw new RuntimeException('fixture persistently unavailable');
        }
    };
    expect(fn () => app(StudioAvRegistrationCleanup::class)->run($this->workspace->id))->toThrow(RuntimeException::class);
    $count = fn () => collect($r2->calls->getArrayCopy())->where('name', 'DeleteObject')->count();
    $before = $count();
    app(StudioAvRecovery::class)->run();
    expect($count() - $before)->toBe(1);
    expect($this->workspace->refresh()->media_cleanup_after->isFuture())->toBeTrue();
    app(StudioAvRecovery::class)->run();
    expect($count() - $before)->toBe(1)->and($this->workspace->refresh()->media_reserved_bytes)->toBe(5);
})->with(['terminal' => false, 'orphan' => true]);
