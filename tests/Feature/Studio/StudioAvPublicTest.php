<?php

use App\Jobs\Studio\ProcessStudioAvPhase;
use App\Jobs\Studio\RenderStudioDesign;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAsset;
use App\Models\Studio\StudioAvLease;
use App\Models\User;
use App\Services\Studio\StudioAvQueue;
use App\Services\Studio\StudioAvState;
use App\Services\Studio\StudioAvStorage;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    if (! Route::has('api.studio.designs.index')) {
        Route::middleware('api')->group(base_path('routes/studio-api.php'));
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
    $this->realQueue = Queue::getFacadeRoot();
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Public native', 'document' => [
        'schema_version' => 2, 'width' => 100, 'height' => 80, 'timeline' => ['fps' => 12, 'audio_tracks' => []],
        'pages' => [['id' => 'one', 'duration' => 1, 'background' => '#ffffff', 'elements' => []]]]]);
    $this->key = $this->mcpKey($this->user, ['designs:read', 'designs:write', 'posts:write']);
    $this->payload = ['revision' => 1, 'format' => 'mp4', 'pages' => ['one']];
    $this->headers = function (?string $key = null) {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer '.($key ?? $this->key)];
    };
    $this->native = function () {
        $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $this->design->id,
            'revision' => 1, 'format' => 'mp4', 'pages' => ['one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);

        return app(StudioAvState::class)->initialize($this->user, $export);
    };
});

test('AV public API submits MP4 and GIF to the dedicated resumable queue without a static renderer job', function (string $format) {
    $response = $this->postJson('/api/v1/designs/'.$this->design->id.'/exports', array_replace($this->payload, ['format' => $format]), ($this->headers)())->assertAccepted();
    $export = DesignExport::withoutUserScope()->findOrFail($response->json('id'));
    expect($export->format)->toBe($format)->and($export->render_state)->toBeArray();
    Queue::assertPushed(ProcessStudioAvPhase::class, 1);
    Queue::assertNotPushed(RenderStudioDesign::class);
    expect($response->json())->not->toHaveKey('render_state')->not->toHaveKey('path');
})->with(['mp4', 'gif']);

test('AV public controls cancel idempotently and retry the pinned revision with a new generation', function () {
    $export = ($this->native)();
    app(StudioAvQueue::class)->enqueue($export->id);
    $before = $export->refresh()->render_state;
    $this->postJson('/api/v1/design-exports/'.$export->id.'/cancel', [], ($this->headers)())->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson('/api/v1/design-exports/'.$export->id.'/cancel', [], ($this->headers)())->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson('/api/v1/design-exports/'.$export->id.'/retry', [], ($this->headers)())->assertAccepted();
    expect($export->refresh()->render_state['generation'])->not->toBe($before['generation'])->and($export->revision)->toBe(1);
});

test('AV public mutation controls require design write and hide another owners export', function () {
    $export = ($this->native)();
    $read = $this->mcpKey($this->user, ['designs:read']);
    $other = $this->mcpKey(User::factory()->create(), ['*']);
    foreach (['cancel', 'retry'] as $action) {
        $url = '/api/v1/design-exports/'.$export->id.'/'.$action;
        $this->postJson($url, [], ($this->headers)($read))->assertForbidden();
        $this->postJson($url, [], ($this->headers)($other))->assertNotFound();
    }
    expect($export->refresh()->status)->toBe('pending');
});

test('AV MCP renders native formats and manages one export without exposing internal state', function () {
    [$error, $created] = $this->callTool($this->key, 'export_design', ['design_id' => $this->design->id] + $this->payload);
    expect($error)->toBeFalse()->and($created['format'])->toBe('mp4');
    [$error, $cancelled] = $this->callTool($this->key, 'manage_design_export', ['action' => 'cancel', 'export_id' => $created['id']]);
    expect($error)->toBeFalse()->and($cancelled['status'])->toBe('cancelled')->and($cancelled)->not->toHaveKey('render_state');
    [$error, $retried] = $this->callTool($this->key, 'manage_design_export', ['action' => 'retry', 'export_id' => $created['id']]);
    expect($error)->toBeFalse()->and($retried['id'])->toBe($created['id']);
});

test('AV native request rejects static frame snapshots and transparency before creating work', function (array $extra, string $field) {
    $this->postJson('/api/v1/designs/'.$this->design->id.'/exports', array_replace($this->payload, $extra), ($this->headers)())
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(DesignExport::withoutUserScope()->count())->toBe(0);
})->with([[['frame_times' => [['page_id' => 'one', 'time' => 0]]], 'frame_times'], [['transparent' => true], 'transparent']]);

test('AV web cancellation can signal its own native manifest while the worker holds the workspace lock', function () {
    $export = ($this->native)();
    app(StudioAvQueue::class)->enqueue($export->id);
    $leaseId = $export->refresh()->render_state['lease'];
    $storage = app(StudioAvStorage::class);
    $storage->locked($export->render_state['allocation_id'], function ($workspace) use ($storage, $leaseId, $export) {
        $storage->manifest($workspace, $leaseId, ['test' => true]);
        $this->actingAs($this->user)->postJson('/studio/exports/'.$export->id.'/cancel', ['workspace' => '../other', 'lease_id' => 'other'])
            ->assertOk()->assertJsonPath('export.status', 'cancelled');
        expect(is_file($storage->checkedPath($workspace, 'work/'.$leaseId.'.cancel')))->toBeTrue();
    });
});

test('AV export draft requires both capabilities and stays in the export brand without scheduling', function () {
    $media = MediaAsset::factory()->for($this->user)->video(1, 100, 80, 12)->create();
    StudioAsset::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'media_asset_id' => $media->id]);
    $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $this->design->id,
        'revision' => 1, 'format' => 'mp4', 'pages' => ['one'], 'options' => [], 'status' => 'ready', 'media_ids' => [$media->id]]);
    $writeOnly = $this->mcpKey($this->user, ['designs:write']);
    $postOnly = $this->mcpKey($this->user, ['posts:write']);
    foreach ([$writeOnly, $postOnly] as $key) {
        $this->postJson('/api/v1/design-exports/'.$export->id.'/post', [], ($this->headers)($key))->assertForbidden();
        [$error] = $this->callTool($key, 'manage_design_export', ['action' => 'post', 'export_id' => $export->id]);
        expect($error)->toBeTrue();
    }
    $response = $this->postJson('/api/v1/design-exports/'.$export->id.'/post', ['text' => 'Draft'], ($this->headers)())
        ->assertCreated()->assertJsonPath('post.status', 'borrador');
    $this->assertDatabaseHas('posts', ['id' => $response->json('post.id'), 'user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'status' => 'borrador']);
    [$error, $draft] = $this->callTool($this->key, 'manage_design_export', ['action' => 'post', 'export_id' => $export->id, 'text' => 'MCP draft']);
    expect($error)->toBeFalse()->and($draft['post']['status'])->toBe('borrador');
});

test('AV public request crosses the actual database worker to native download and draft', function (string $format, string $transport) {
    Queue::swap($this->realQueue);
    $payload = array_replace($this->payload, ['format' => $format]);
    if ($transport === 'api') {
        $dto = $this->postJson('/api/v1/designs/'.$this->design->id.'/exports', $payload, ($this->headers)())->assertAccepted()->json();
    } else {
        [$error, $dto] = $this->callTool($this->key, 'export_design', ['design_id' => $this->design->id] + $payload);
        expect($error)->toBeFalse();
    }
    $export = DesignExport::withoutUserScope()->findOrFail($dto['id']);
    for ($delivery = 0; $delivery < 12 && in_array($export->refresh()->status, ['pending', 'processing'], true); $delivery++) {
        expect($export->render_state['lease'])->not->toBeNull();
        $id = $export->render_state['lease'];
        expect(Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'studio', '--once' => true, '--tries' => 1, '--timeout' => 100, '--sleep' => 0]))->toBe(0);
        expect(StudioAvLease::findOrFail($id)->status)->toBeIn(['accepted', 'abandoned']);
    }
    expect($export->refresh()->status)->toBe('ready', $export->error ?? '')->and($export->media_ids)->toHaveCount(1);
    $dto = app(StudioExports::class)->present($this->user, $export);
    $download = $this->get($dto['download_url'])->assertOk();
    $hash = app(StudioAvState::class)->checkpoints($export, ['assemble'])->sole()->result['sha256'];
    expect(hash('sha256', $download->streamedContent()))->toBe($hash);
    $draft = $this->postJson('/api/v1/design-exports/'.$export->id.'/post', ['text' => 'Native draft'], ($this->headers)())
        ->assertCreated()->assertJsonPath('post.status', 'borrador')->json('post.id');
    $this->assertDatabaseHas('posts', ['id' => $draft, 'brand_id' => $this->brand->id, 'status' => 'borrador']);
    expect(Post::withoutUserScope()->findOrFail($draft)->media->pluck('id')->all())->toBe($export->media_ids);
})->with([['mp4', 'api'], ['gif', 'api'], ['mp4', 'mcp']])->skip(fn () => getenv('STUDIO_AV_NATIVE_TEST') !== '1', 'Explicit managed native runtime opt-in; no ONNX.');

test('AV MCP read capability polls but cannot mutate or inspect another owners work', function () {
    $export = ($this->native)();
    $read = $this->mcpKey($this->user, ['designs:read']);
    [$error, $dto] = $this->callTool($read, 'manage_design_export', ['action' => 'get', 'export_id' => $export->id]);
    expect($error)->toBeFalse()->and($dto['id'])->toBe($export->id)->and($dto)->not->toHaveKey('render_state');
    foreach (['cancel', 'retry'] as $action) {
        [$error] = $this->callTool($read, 'manage_design_export', ['action' => $action, 'export_id' => $export->id]);
        expect($error)->toBeTrue();
    }
    [$error] = $this->callTool($this->mcpKey(User::factory()->create(), ['*']), 'manage_design_export', ['action' => 'get', 'export_id' => $export->id]);
    expect($error)->toBeTrue()->and($export->refresh()->status)->toBe('pending');
});

test('AV public controls preserve ready output and reject static or active retry', function () {
    $native = ($this->native)();
    $this->postJson('/api/v1/design-exports/'.$native->id.'/retry', [], ($this->headers)())->assertConflict();
    $native->update(['status' => 'ready']);
    foreach (['cancel', 'retry'] as $action) {
        $this->postJson('/api/v1/design-exports/'.$native->id.'/'.$action, [], ($this->headers)())->assertConflict();
    }
    $native->update(['format' => 'png']);
    foreach (['cancel', 'retry'] as $action) {
        $this->postJson('/api/v1/design-exports/'.$native->id.'/'.$action, [], ($this->headers)())->assertUnprocessable();
    }
    expect($native->refresh()->status)->toBe('ready');
});

test('AV public cancellation commits fencing even if native signalling is unavailable and hides internal errors', function () {
    $export = ($this->native)();
    app(StudioAvQueue::class)->enqueue($export->id);
    $lease = $export->refresh()->render_state['lease'];
    $this->partialMock(StudioAvStorage::class, function ($mock) {
        $mock->shouldReceive('signalCancellation')->once()->andThrow(new RuntimeException('private/path secret-bucket'));
    });
    $response = $this->postJson('/api/v1/design-exports/'.$export->id.'/cancel', [], ($this->headers)())->assertStatus(503);
    expect($response->getContent())->not->toContain('private/path')->not->toContain('secret-bucket');
    expect($export->refresh()->status)->toBe('cancelled')->and(StudioAvLease::findOrFail($lease)->status)->toBe('abandoned');
});

test('AV web submission uses the shared native queue and exposes only the public export', function () {
    $response = $this->actingAs($this->user)->postJson('/studio/designs/'.$this->design->id.'/render', $this->payload)->assertAccepted();
    expect($response->json('export.format'))->toBe('mp4')->and($response->json('export'))->not->toHaveKey('render_state');
    Queue::assertPushed(ProcessStudioAvPhase::class, 1);
    Queue::assertNotPushed(RenderStudioDesign::class);
});

test('AV draft API rejects publication controls and never creates a post for unfinished output', function () {
    $export = ($this->native)();
    $before = Post::withoutUserScope()->count();
    $this->postJson('/api/v1/design-exports/'.$export->id.'/post', ['schedule' => true, 'brand_id' => $this->brand->id], ($this->headers)())
        ->assertUnprocessable();
    $this->postJson('/api/v1/design-exports/'.$export->id.'/post', [], ($this->headers)())->assertUnprocessable();
    expect(Post::withoutUserScope()->count())->toBe($before);
});
