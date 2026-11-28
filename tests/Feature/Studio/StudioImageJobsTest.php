<?php

use App\Jobs\Studio\ProcessStudioImage;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\StudioAsset;
use App\Models\Studio\StudioImageJob;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioImages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $bytes = file_get_contents(base_path('tests/Fixtures/images/astronaut.png'));
    $this->image = MediaAsset::factory()->for($this->user)->create([
        'disk' => 'local', 'path' => 'media/'.$this->user->id.'/photo.png', 'mime' => 'image/png',
        'kind' => 'image', 'status' => 'listo', 'size' => strlen($bytes), 'width' => 512, 'height' => 512,
    ]);
    Storage::disk('local')->put($this->image->path, $bytes);
    app(StudioAssets::class)->attach($this->user, $this->brand, $this->image->id);
    $model = storage_path('framework/testing/image-job-test.onnx');
    File::ensureDirectoryExists(dirname($model));
    file_put_contents($model, 'process-boundary-test-model');
    config(['studio.images.model_path' => $model, 'studio.images.model_sha256' => hash_file('sha256', $model)]);
    $this->url = '/brands/'.$this->brand->id.'/studio/image-jobs';
});

test('Studio image jobs create one queued job for duplicate active requests without altering input', function () {
    $before = hash('sha256', Storage::disk('local')->get($this->image->path));
    $payload = ['media_id' => $this->image->id, 'operation' => 'remove_background'];
    $first = $this->actingAs($this->user)->postJson($this->url, $payload)
        ->assertAccepted()->assertJsonPath('job.status', 'queued')->assertJsonPath('job.output_asset', null)->json('job');
    $this->postJson($this->url, $payload)->assertAccepted()->assertJsonPath('job.id', $first['id']);
    Queue::assertPushed(ProcessStudioImage::class, 1);
    expect(hash('sha256', Storage::disk('local')->get($this->image->path)))->toBe($before);
});

test('Studio image jobs status is private to its user and brand and cannot process another brand resource', function () {
    $created = $this->actingAs($this->user)->postJson($this->url, ['media_id' => $this->image->id, 'operation' => 'remove_background'])
        ->assertAccepted()->json('job.id');
    $this->getJson($this->url.'/'.$created)->assertOk()->assertJsonPath('job.source_media_id', $this->image->id);
    $otherBrand = Brand::factory()->for($this->user)->create();
    $otherUrl = '/brands/'.$otherBrand->id.'/studio/image-jobs';
    $this->getJson($otherUrl.'/'.$created)->assertNotFound();
    $this->postJson($otherUrl, ['media_id' => $this->image->id, 'operation' => 'remove_background'])->assertNotFound();
    $this->actingAs(User::factory()->create())->getJson($this->url.'/'.$created)->assertNotFound();
});

test('Studio image jobs validate generated PNG and register an independent private output only once', function () {
    $created = $this->actingAs($this->user)->postJson($this->url, ['media_id' => $this->image->id, 'operation' => 'remove_background'])
        ->assertAccepted()->json('job.id');
    $before = hash('sha256', Storage::disk('local')->get($this->image->path));
    Process::fake(function ($process) {
        $payload = json_decode($process->input, true);
        $image = imagecreatetruecolor(512, 512);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagesetpixel($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagepng($image, $payload['output']);
        imagedestroy($image);

        return Process::result(output: json_encode(['ok' => true, 'width' => 512, 'height' => 512, 'format' => 'png', 'model_sha256' => $payload['model_sha256']]));
    });
    $level = DB::transactionLevel();
    $ioLevels = [];
    $disk = Storage::disk('local');
    $observed = Mockery::mock($disk);
    foreach (['readStream', 'put', 'exists', 'size', 'get'] as $operation) {
        $observed->shouldReceive($operation)->andReturnUsing(function (...$arguments) use ($disk, $operation, &$ioLevels) {
            $ioLevels[$operation][] = DB::transactionLevel();

            return $disk->{$operation}(...$arguments);
        });
    }
    Storage::set('local', $observed);
    app(StudioImages::class)->process($created);
    expect($ioLevels)->toHaveKeys(['readStream', 'put', 'exists', 'size', 'get'])
        ->and($ioLevels['put'])->toBe([$level])
        ->and(array_unique(array_merge(...array_values($ioLevels))))->toBe([$level]);
    Storage::set('local', $disk);
    $result = $this->getJson($this->url.'/'.$created)->assertOk()->assertJsonPath('job.status', 'ready')->json('job.output_asset');
    expect($result['id'])->not->toBe($this->image->id);
    expect($result['mime'])->toBe('image/png');
    expect([$result['width'], $result['height']])->toBe([512, 512]);
    expect(hash('sha256', Storage::disk('local')->get($this->image->path)))->toBe($before);
    app(StudioImages::class)->process($created);
    Process::assertRanTimes(fn () => true, 1);
});

test('Studio image jobs expose a bounded error and never forward private process details', function () {
    $created = $this->actingAs($this->user)->postJson($this->url, ['media_id' => $this->image->id, 'operation' => 'remove_background'])
        ->assertAccepted()->json('job.id');
    Process::fake(['*' => Process::result(exitCode: 1, output: '{"ok":false,"error_code":"model_checksum"}', errorOutput: 'private server path and credentials must not be exposed')]);

    app(StudioImages::class)->process($created);

    $this->getJson($this->url.'/'.$created)->assertOk()->assertJsonPath('job.status', 'failed')
        ->assertJsonPath('job.output_asset', null)->assertJsonPath('job.error_code', 'model_checksum')
        ->assertDontSee('private server path');
});

test('Studio image jobs discard staged bytes when registering the output rolls back', function () {
    $job = app(StudioImages::class)->create($this->user, $this->brand, ['media_id' => $this->image->id, 'operation' => 'remove_background']);
    Process::fake(function ($process) {
        $payload = json_decode($process->input, true);
        copy(base_path('tests/Fixtures/images/astronaut.png'), $payload['output']);

        return Process::result(output: json_encode(['ok' => true, 'width' => 512, 'height' => 512, 'format' => 'png', 'model_sha256' => $payload['model_sha256']]));
    });
    $staged = null;
    Event::listen('eloquent.creating: '.StudioAsset::class, function (StudioAsset $link) use (&$staged): void {
        $staged = MediaAsset::withoutUserScope()->findOrFail($link->media_asset_id);

        throw new RuntimeException('Registration failed');
    });

    app(StudioImages::class)->process($job->id);
    expect($staged)->toBeInstanceOf(MediaAsset::class)
        ->and($job->fresh()->only(['status', 'output_media_id']))->toBe(['status' => 'failed', 'output_media_id' => null]);
    $this->assertModelMissing($staged);
    Storage::disk('local')->assertMissing($staged->path)->assertExists($this->image->path);
});

test('Studio image jobs preserve published bytes when an observer throws after commit', function () {
    $job = app(StudioImages::class)->create($this->user, $this->brand, ['media_id' => $this->image->id, 'operation' => 'remove_background']);
    Process::fake(function ($process) {
        $payload = json_decode($process->input, true);
        copy(base_path('tests/Fixtures/images/astronaut.png'), $payload['output']);

        return Process::result(output: json_encode(['ok' => true, 'width' => 512, 'height' => 512, 'format' => 'png', 'model_sha256' => $payload['model_sha256']]));
    });
    Event::listen('eloquent.updated: '.StudioImageJob::class, function (StudioImageJob $row): void {
        if ($row->status === 'ready') {
            DB::afterCommit(fn () => throw new RuntimeException('Post-commit observer failed'));
        }
    });

    app(StudioImages::class)->process($job->id);
    $job->refresh();
    expect($job->status)->toBe('ready');
    $asset = app(StudioAssets::class)->owned($this->user, $this->brand, $job->output_media_id);
    Storage::disk($asset->disk)->assertExists($asset->path);
});

test('Studio image jobs wait for the caller transaction before starting storage work', function () {
    $job = app(StudioImages::class)->create($this->user, $this->brand, ['media_id' => $this->image->id, 'operation' => 'remove_background']);
    Process::fake();

    DB::beginTransaction();
    try {
        app(StudioImages::class)->process($job->id);
        expect($job->fresh()->status)->toBe('queued');
    } finally {
        DB::rollBack();
    }

    Process::assertNothingRan();
});

test('Studio image jobs reject forged output bytes without leaving a ready media row', function () {
    $created = $this->actingAs($this->user)->postJson($this->url, ['media_id' => $this->image->id, 'operation' => 'remove_background'])
        ->assertAccepted()->json('job.id');
    Process::fake(function ($process) {
        $payload = json_decode($process->input, true);
        file_put_contents($payload['output'], 'not a PNG');

        return Process::result(output: json_encode(['ok' => true, 'width' => 512, 'height' => 512, 'format' => 'png', 'model_sha256' => $payload['model_sha256']]));
    });

    app(StudioImages::class)->process($created);

    $this->getJson($this->url.'/'.$created)->assertOk()->assertJsonPath('job.status', 'failed')->assertJsonPath('job.output_asset', null);
    expect(MediaAsset::withoutUserScope()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('Studio image jobs report unavailable output without mutating the saved job', function (bool $hasPendingMedia) {
    $job = app(StudioImages::class)->create($this->user, $this->brand, ['media_id' => $this->image->id, 'operation' => 'remove_background']);
    if ($hasPendingMedia) {
        $this->image->update(['status' => MediaAsset::STATUS_PENDING]);
    }
    $job->forceFill(['status' => 'ready', 'output_media_id' => $hasPendingMedia ? $this->image->id : null])->save();
    $this->actingAs($this->user)->getJson($this->url.'/'.$job->id)->assertOk()
        ->assertJsonPath('job.status', 'failed')->assertJsonPath('job.error_code', 'output_removed')->assertJsonPath('job.output_asset', null);
    expect($job->fresh()->status)->toBe('ready');
})->with(['removed' => false, 'linked but pending' => true]);

test('Studio image jobs do not process changed source bytes', function () {
    $job = app(StudioImages::class)->create($this->user, $this->brand, ['media_id' => $this->image->id, 'operation' => 'remove_background']);
    Storage::disk('local')->put($this->image->path, 'changed source bytes');
    Process::fake();
    app(StudioImages::class)->process($job->id);
    expect($job->fresh()->status)->toBe('failed')->and($job->fresh()->error_code)->toBe('source_changed');
    Process::assertNothingRan();
});

test('Studio image jobs reject a source changed while processing before attaching output', function () {
    $job = app(StudioImages::class)->create($this->user, $this->brand, ['media_id' => $this->image->id, 'operation' => 'remove_background']);
    Process::fake(function ($process) {
        $payload = json_decode($process->input, true);
        copy(base_path('tests/Fixtures/images/astronaut.png'), $payload['output']);
        Storage::disk('local')->put($this->image->path, 'source changed during processing');

        return Process::result(output: json_encode(['ok' => true, 'width' => 512, 'height' => 512, 'format' => 'png', 'model_sha256' => $payload['model_sha256']]));
    });
    app(StudioImages::class)->process($job->id);
    expect($job->fresh()->status)->toBe('failed')->and($job->fresh()->error_code)->toBe('source_changed');
    expect(MediaAsset::withoutUserScope()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('Studio image jobs reject source metadata changed after the output is staged', function () {
    $job = app(StudioImages::class)->create($this->user, $this->brand, ['media_id' => $this->image->id, 'operation' => 'remove_background']);
    Process::fake(function ($process) {
        $payload = json_decode($process->input, true);
        copy(base_path('tests/Fixtures/images/astronaut.png'), $payload['output']);

        return Process::result(output: json_encode(['ok' => true, 'width' => 512, 'height' => 512, 'format' => 'png', 'model_sha256' => $payload['model_sha256']]));
    });
    $staged = null;
    $disk = Storage::disk('local');
    $observed = Mockery::mock($disk);
    $observed->shouldReceive('put')->once()->andReturnUsing(function ($path, $contents) use ($disk, &$staged) {
        $staged = $path;
        $stored = $disk->put($path, $contents);
        MediaAsset::withoutUserScope()->whereKey($this->image->id)->update(['width' => 511]);

        return $stored;
    });
    Storage::set('local', $observed);
    try {
        app(StudioImages::class)->process($job->id);
    } finally {
        Storage::set('local', $disk);
    }

    expect($job->fresh()->only(['status', 'error_code', 'output_media_id']))
        ->toBe(['status' => 'failed', 'error_code' => 'source_changed', 'output_media_id' => null])
        ->and($staged)->not->toBeNull()
        ->and(hash('sha256', $disk->get($this->image->path)))->toBe($job->input_sha256);
    $disk->assertMissing($staged);
});

test('Studio image jobs API separates read and write abilities and brand ownership', function () {
    $url = '/api/v1/brands/'.$this->brand->id.'/image-jobs';
    $read = $this->mcpKey($this->user, ['designs:read']);
    $write = $this->mcpKey($this->user, ['designs:write']);
    $payload = ['media_id' => $this->image->id, 'operation' => 'remove_background'];
    $this->withToken($read)->postJson($url, $payload)->assertForbidden();
    app('auth')->forgetGuards();
    $job = $this->withToken($write)->postJson($url, $payload)->assertAccepted()->json('job.id');
    app('auth')->forgetGuards();
    $this->withToken($write)->getJson($url.'/'.$job)->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($read)->getJson($url.'/'.$job)->assertOk()->assertJsonPath('job.status', 'queued');
    app('auth')->forgetGuards();
    $other = $this->mcpKey(User::factory()->create(), ['designs:read']);
    $this->withToken($other)->getJson($url.'/'.$job)->assertNotFound();
});

test('Studio image jobs MCP starts and reads a private branded job with action abilities', function () {
    $write = $this->mcpKey($this->user, ['designs:write']);
    $read = $this->mcpKey($this->user, ['designs:read']);
    $args = ['brand_id' => $this->brand->id, 'action' => 'start', 'media_id' => $this->image->id, 'operation' => 'remove_background'];
    [$error, $result] = $this->callTool($write, 'process_studio_image', $args);
    expect($error)->toBeFalse()->and($result['job']['status'])->toBe('queued');
    $get = ['brand_id' => $this->brand->id, 'action' => 'get', 'job_id' => $result['job']['id']];
    [$readError, $status] = $this->callTool($read, 'process_studio_image', $get);
    expect($readError)->toBeFalse()->and($status['job']['id'])->toBe($result['job']['id']);
    [$writeDenied] = $this->callTool($read, 'process_studio_image', $args);
    [$readDenied] = $this->callTool($write, 'process_studio_image', $get);
    expect($writeDenied)->toBeTrue()->and($readDenied)->toBeTrue();
    $get['brand_id'] = Brand::factory()->for($this->user)->create()->id;
    [$brandDenied] = $this->callTool($read, 'process_studio_image', $get);
    expect($brandDenied)->toBeTrue();
});

test('Studio image jobs discard output when the brand is deleted while processing', function () {
    $job = app(StudioImages::class)->create($this->user, $this->brand, ['media_id' => $this->image->id, 'operation' => 'remove_background']);
    Process::fake(function ($process) {
        $payload = json_decode($process->input, true);
        copy(base_path('tests/Fixtures/images/astronaut.png'), $payload['output']);
        $this->brand->delete();

        return Process::result(output: json_encode(['ok' => true, 'width' => 512, 'height' => 512, 'format' => 'png', 'model_sha256' => $payload['model_sha256']]));
    });
    app(StudioImages::class)->process($job->id);
    expect(MediaAsset::withoutUserScope()->where('user_id', $this->user->id)->count())->toBe(1);
    expect(Storage::disk('local')->allFiles('studio-image-jobs'))->toBe([]);
    expect(hash('sha256', Storage::disk('local')->get($this->image->path)))->toBe($job->input_sha256);
});

test('Studio image jobs reject mismatched runtime metadata and malformed JSON safely', function (string $failure) {
    $job = app(StudioImages::class)->create($this->user, $this->brand, ['media_id' => $this->image->id, 'operation' => 'remove_background']);
    Process::fake(function ($process) use ($failure) {
        $payload = json_decode($process->input, true);
        copy(base_path('tests/Fixtures/images/astronaut.png'), $payload['output']);
        $manifest = ['ok' => true, 'width' => 512, 'height' => 512, 'format' => 'png', 'model_sha256' => $payload['model_sha256']];
        if ($failure === 'dimensions') {
            $manifest['width'] = 100;
        }
        if ($failure === 'sha') {
            $manifest['model_sha256'] = str_repeat('0', 64);
        }

        return Process::result(output: $failure === 'json' ? 'not JSON with private path' : json_encode($manifest));
    });
    app(StudioImages::class)->process($job->id);
    expect($job->fresh()->status)->toBe('failed')->and($job->fresh()->error_code)->toBe('invalid_output');
    expect(MediaAsset::withoutUserScope()->where('user_id', $this->user->id)->count())->toBe(1);
    expect(Storage::disk('local')->allFiles('studio-image-jobs'))->toBe([]);
})->with(['dimensions', 'sha', 'json']);

test('Studio image jobs queue failure marks only inflight jobs with a safe error', function () {
    $job = app(StudioImages::class)->create($this->user, $this->brand, ['media_id' => $this->image->id, 'operation' => 'remove_background']);
    (new ProcessStudioImage($job->id))->failed(new RuntimeException('private exception'));
    expect($job->fresh()->status)->toBe('failed')->and($job->fresh()->error_code)->toBe('processing_interrupted');
    expect($job->fresh()->error)->not->toContain('private exception');
    $job->forceFill(['status' => 'ready'])->save();
    (new ProcessStudioImage($job->id))->failed(new RuntimeException('private exception'));
    expect($job->fresh()->status)->toBe('ready');
});

test('Studio image jobs queued before a model change run with the current model, not the saved one', function () {
    $created = $this->actingAs($this->user)->postJson($this->url, ['media_id' => $this->image->id, 'operation' => 'remove_background'])
        ->assertAccepted()->json('job.id');
    // El despliegue cambia de modelo con el trabajo ya encolado: el guardado apunta a un fichero que ya no existe.
    StudioImageJob::withoutUserScope()->whereKey($created)->update(['model_path' => storage_path('framework/testing/modelo-anterior.onnx'), 'model_sha256' => str_repeat('a', 64)]);
    $new = storage_path('framework/testing/image-job-test-nuevo.onnx');
    file_put_contents($new, 'modelo-vigente');
    config(['studio.images.model_path' => $new, 'studio.images.model_sha256' => hash_file('sha256', $new)]);
    $seen = [];
    Process::fake(function ($process) use (&$seen) {
        $payload = json_decode($process->input, true);
        $seen = [$payload['model'], $payload['model_sha256']];
        $image = imagecreatetruecolor(512, 512);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagesetpixel($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagepng($image, $payload['output']);
        imagedestroy($image);

        return Process::result(output: json_encode(['ok' => true, 'width' => 512, 'height' => 512, 'format' => 'png', 'model_sha256' => $payload['model_sha256']]));
    });
    app(StudioImages::class)->process($created);

    expect($seen)->toBe([$new, hash_file('sha256', $new)]);
    $this->actingAs($this->user)->getJson($this->url.'/'.$created)->assertOk()->assertJsonPath('job.status', 'ready')->assertJsonPath('job.model_sha256', hash_file('sha256', $new));
    @unlink($new);
});

test('Studio image jobs fail with a clear error when the current model is not installed', function () {
    $created = $this->actingAs($this->user)->postJson($this->url, ['media_id' => $this->image->id, 'operation' => 'remove_background'])
        ->assertAccepted()->json('job.id');
    config(['studio.images.model_path' => storage_path('framework/testing/no-existe.onnx')]);
    Process::fake();
    app(StudioImages::class)->process($created);

    Process::assertNothingRan();
    $this->actingAs($this->user)->getJson($this->url.'/'.$created)->assertOk()->assertJsonPath('job.status', 'failed')->assertJsonPath('job.error_code', 'model_unavailable');
});
