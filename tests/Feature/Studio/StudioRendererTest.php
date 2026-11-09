<?php

use App\Jobs\Studio\RenderStudioDesign;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAsset;
use App\Models\Studio\StudioFont;
use App\Models\User;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->document = ['schema_version' => 1, 'width' => 100, 'height' => 80, 'pages' => [
        ['id' => 'page_one', 'name' => 'Uno', 'background' => '#ffffff', 'duration' => 5, 'elements' => []],
    ]];
    $this->design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Render', 'document' => $this->document]);
});

test('renderer pins old content, validates generated pixels and is idempotent on duplicate delivery', function () {
    $export = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one']]);
    $changed = $this->document;
    $changed['pages'][0]['background'] = '#ff0000';
    app(StudioService::class)->update($this->user, $this->design, ['name' => 'New revision', 'document' => $changed, 'revision' => 1]);
    Process::fake(function ($process) {
        expect(json_decode($process->input, true)['document']['pages'][0]['background'])->toBe('#ffffff');
        $image = imagecreatetruecolor(100, 80);
        imagepng($image, $process->command[2].DIRECTORY_SEPARATOR.'page-001.png');
        imagedestroy($image);

        return Process::result(output: '{"files":[{"name":"page-001.png","mime":"image/png"}]}');
    });
    app(StudioRenderer::class)->render($export->id);
    expect($export->refresh()->status)->toBe('ready', $export->error ?? '')->and($export->media_ids)->toHaveCount(1);
    $media = MediaAsset::withoutUserScope()->findOrFail($export->media_ids[0]);
    expect($media->isReady())->toBeTrue()->and($media->width)->toBe(100)->and($media->height)->toBe(80);
    app(StudioRenderer::class)->render($export->id);
    Process::assertRanTimes(fn () => true, 1);
    expect(MediaAsset::withoutUserScope()->count())->toBe(1);
});

test('failed renderer never exposes a ready download and stores a useful error', function () {
    $export = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'pdf', 'pages' => ['page_one']]);
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'sensitive filesystem detail')]);
    app(StudioRenderer::class)->render($export->id);
    expect($export->refresh()->status)->toBe('failed')->and($export->error)->toContain('Node, Chromium')
        ->and($export->error)->not->toContain('sensitive');
    expect(app(StudioExports::class)->present($this->user, $export)['download_url'])->toBeNull();
});

test('renderer rejects an image with unexpected output dimensions', function () {
    $export = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one']]);
    Process::fake(function ($process) {
        $image = imagecreatetruecolor(20, 20);
        imagepng($image, $process->command[2].DIRECTORY_SEPARATOR.'page-001.png');
        imagedestroy($image);

        return Process::result(output: '{"files":[{"name":"page-001.png","mime":"image/png"}]}');
    });
    app(StudioRenderer::class)->render($export->id);
    expect($export->refresh()->status)->toBe('failed')->and($export->media_ids)->toBeNull();
});

test('signed downloads expire and bind both export and owner without leaking storage paths', function () {
    $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $this->design->id,
        'revision' => 1, 'format' => 'pdf', 'pages' => ['page_one'], 'status' => 'ready', 'disk' => 'local',
        'path' => 'studio-exports/'.$this->user->id.'/1/fake/page-001.pdf']);
    // The path must match its generated row ID.
    $export->update(['path' => 'studio-exports/'.$this->user->id.'/'.$export->id.'/fake/page-001.pdf']);
    Storage::disk('local')->put($export->path, '%PDF-1.7 test');
    $url = app(StudioExports::class)->present($this->user, $export)['download_url'];
    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(str_replace('/'.$export->id.'/'.$this->user->id.'?', '/'.$export->id.'/99999?', $url))->assertForbidden();
    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();
});

test('API export history does not requery every export while presenting the collection', function () {
    for ($i = 0; $i < 100; $i++) {
        DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $this->design->id,
            'revision' => 1, 'format' => 'png', 'pages' => ['page_one'], 'status' => 'pending']);
    }
    $token = $this->user->createToken('test-history', ['designs:read'])->plainTextToken;
    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->withToken($token)->getJson('/api/v1/designs/'.$this->design->id);
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains(strtolower($query['query']), 'select') && str_contains($query['query'], 'studio_design_exports'));
    DB::disableQueryLog();
    $response->assertOk()->assertJsonCount(50, 'exports');
    expect($queries->count())->toBeLessThanOrEqual(2);
});

test('render preparation batches private resource authorization rather than querying each asset', function () {
    $image = imagecreatetruecolor(1, 1);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);
    $elements = [];
    for ($i = 0; $i < 100; $i++) {
        $media = MediaAsset::factory()->for($this->user)->create(['mime' => 'image/png']);
        Storage::disk($media->disk)->put($media->path, $bytes);
        StudioAsset::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'media_asset_id' => $media->id]);
        $elements[] = ['id' => 'image_'.$i, 'type' => 'image', 'asset_id' => $media->id, 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10];
    }
    $document = $this->document;
    $document['pages'][0]['elements'] = $elements;
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Many assets', 'document' => $document]);
    $export = app(StudioRenderer::class)->export($this->user, $design, ['revision' => 1, 'format' => 'svg', 'pages' => ['page_one']]);
    Process::fake(function ($process) {
        expect(json_decode($process->input, true)['assets'])->toHaveCount(100);
        file_put_contents($process->command[2].DIRECTORY_SEPARATOR.'page-001.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        return Process::result(output: '{"files":[{"name":"page-001.svg","mime":"image/svg+xml"}]}');
    });
    DB::flushQueryLog();
    DB::enableQueryLog();
    app(StudioRenderer::class)->render($export->id);
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains(strtolower($query['query']), 'select') && (str_contains($query['query'], 'studio_assets') || str_contains($query['query'], 'media_assets')));
    DB::disableQueryLog();
    expect($export->refresh()->status)->toBe('ready')->and($queries->count())->toBeLessThanOrEqual(2);
});

test('server export queues a pinned revision with actual pending status', function () {
    $export = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one']]);
    expect($export)->toBeInstanceOf(DesignExport::class)
        ->and($export->status)->toBe('pending')
        ->and($export->revision)->toBe(1)
        ->and($export->media_ids)->toBeNull();
    Queue::assertPushed(RenderStudioDesign::class, fn ($job) => $job->exportId === $export->id);
});

test('server export rejects foreign owners, invented pages and excessive raster budgets', function () {
    try {
        app(StudioRenderer::class)->export(User::factory()->create(), $this->design, ['revision' => 1, 'format' => 'pdf', 'pages' => ['page_one']]);
        test()->fail('A foreign design was exported');
    } catch (ModelNotFoundException) {
    }
    foreach ([['pages' => ['invented']], ['pages' => ['page_one', 'page_one']], ['revision' => 2], ['format' => 'html']] as $invalid) {
        try {
            app(StudioRenderer::class)->export($this->user, $this->design, array_replace(['revision' => 1, 'format' => 'png', 'pages' => ['page_one']], $invalid));
            test()->fail('An invalid export was accepted');
        } catch (ValidationException) {
        }
    }
    expect(DesignExport::withoutUserScope()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('renderer packages only referenced private fonts with verified bytes and no storage path', function () {
    $bytes = file_get_contents(base_path('tests/Fixtures/fonts/SpaceGrotesk-Variable.woff2'));
    $font = StudioFont::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id,
        'name' => 'Space', 'family' => 'Space Grotesk', 'style' => 'normal', 'weight' => 400, 'format' => 'woff2', 'mime' => 'font/woff2',
        'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'source_sha256' => hash('sha256', $bytes), 'disk' => 'local', 'path' => 'private-font.woff2', 'status' => 'ready']);
    Storage::disk('local')->put($font->path, $bytes);
    $document = $this->document;
    $document['pages'][0]['elements'] = [['id' => 'caption', 'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 30,
        'text' => 'Marca', 'fontFamily' => $font->family, 'font_id' => $font->id]];
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Font render', 'document' => $document]);
    $export = app(StudioRenderer::class)->export($this->user, $design, ['revision' => 1, 'format' => 'svg', 'pages' => ['page_one']]);
    $packet = null;
    Process::fake(function ($process) use (&$packet) {
        $packet = json_decode($process->input, true);
        file_put_contents($process->command[2].DIRECTORY_SEPARATOR.'page-001.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        return Process::result(output: '{"files":[{"name":"page-001.svg","mime":"image/svg+xml"}]}');
    });
    app(StudioRenderer::class)->render($export->id);
    expect($export->refresh()->status)->toBe('ready', $export->error ?? '')
        ->and($packet['fonts'] ?? [])->toHaveCount(1)
        ->and($packet['fonts'][0]['file_url'])->toBe('data:font/woff2;base64,'.base64_encode($bytes))
        ->and($packet['fonts'][0])->not->toHaveKey('path')->not->toHaveKey('disk');
    Storage::disk('local')->put($font->path, str_repeat('x', strlen($bytes)));
    $failed = app(StudioRenderer::class)->export($this->user, $design, ['revision' => 1, 'format' => 'svg', 'pages' => ['page_one']]);
    app(StudioRenderer::class)->render($failed->id);
    expect($failed->refresh()->status)->toBe('failed');
    Process::assertRanTimes(fn () => true, 1);
});
