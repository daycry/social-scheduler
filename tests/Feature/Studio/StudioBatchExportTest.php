<?php

use App\Jobs\Studio\RenderStudioDesign;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAsset;
use App\Models\User;
use App\Services\Studio\StudioBatchExport;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/StudioWatermarkFixtures.php';

/** Renderer falso: una imagen azul con las dimensiones del documento ya ajustado por la escala. */
function fakeBatchRenderer(?Closure $failWhen = null): void
{
    Process::fake(function ($process) use ($failWhen) {
        $input = json_decode($process->input, true);
        if ($failWhen && $failWhen($input)) {
            return Process::result(exitCode: 1, errorOutput: 'boom');
        }
        $extension = ['png' => 'png', 'jpeg' => 'jpg', 'webp' => 'webp'][$input['format']];
        $image = imagecreatetruecolor((int) round($input['document']['width'] * ($input['scale'] ?? 1)), (int) round($input['document']['height'] * ($input['scale'] ?? 1)));
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 255));
        $target = $process->command[2].DIRECTORY_SEPARATOR.'page-001.'.$extension;
        match ($input['format']) {
            'png' => imagepng($image, $target),
            'jpeg' => imagejpeg($image, $target, 90),
            'webp' => imagewebp($image, $target, 90),
        };

        return Process::result(output: json_encode(['files' => [['name' => 'page-001.'.$extension, 'mime' => 'image/'.$input['format']]]]));
    });
}

/** Ejecuta los trabajos de render encolados, como haría el worker. */
function runRenders(array $exports): void
{
    foreach ($exports as $export) {
        app(StudioRenderer::class)->render($export['id']);
    }
}

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->document = ['schema_version' => 1, 'width' => 200, 'height' => 100, 'pages' => [
        ['id' => 'page_one', 'name' => 'Uno', 'background' => '#ffffff', 'duration' => 5, 'elements' => []],
    ]];
    $this->design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Lote', 'document' => $this->document]);
    $this->batch = app(StudioBatchExport::class);
    $this->request = ['revision' => 1, 'pages' => ['page_one'], 'format' => 'png', 'mode' => 'cover',
        'formats' => [['width' => 100, 'height' => 100], ['width' => 80, 'height' => 100], ['width' => 90, 'height' => 160]]];
});

test('a batch creates one export per format and renders one library file at each size', function () {
    $result = $this->batch->start($this->user, $this->design, $this->request);

    expect($result['exports'])->toHaveCount(3)->and($result['created'])->toBe(3)->and($result['reused'])->toBe(0);
    Queue::assertPushed(RenderStudioDesign::class, 3);
    fakeBatchRenderer();
    runRenders($result['exports']);

    $media = MediaAsset::ownedBy($this->user->id)->orderBy('id')->get();
    expect($media)->toHaveCount(3)
        ->and($media->map(fn ($asset) => [$asset->width, $asset->height])->all())->toBe([[100, 100], [80, 100], [90, 160]]);
    foreach (DesignExport::ownedBy($this->user->id)->get() as $export) {
        expect($export->status)->toBe('ready', $export->error ?? '')->and($export->media_ids)->toHaveCount(1);
    }
});

test('retrying the same batch never duplicates exports or library files', function () {
    $first = $this->batch->start($this->user, $this->design, $this->request);
    fakeBatchRenderer();
    runRenders($first['exports']);

    $second = $this->batch->start($this->user, $this->design, $this->request);

    expect(array_column($second['exports'], 'id'))->toBe(array_column($first['exports'], 'id'))
        ->and($second['created'])->toBe(0)->and($second['reused'])->toBe(3);
    Queue::assertPushed(RenderStudioDesign::class, 3);
    expect(DesignExport::ownedBy($this->user->id)->count())->toBe(3)->and(MediaAsset::ownedBy($this->user->id)->count())->toBe(3);
});

test('a double submission while the batch is still rendering reuses the pending exports', function () {
    $first = $this->batch->start($this->user, $this->design, $this->request);
    $second = $this->batch->start($this->user, $this->design, $this->request);

    expect(array_column($second['exports'], 'id'))->toBe(array_column($first['exports'], 'id'));
    Queue::assertPushed(RenderStudioDesign::class, 3);
});

test('after a partial failure only the failed formats are rendered again and nothing is left orphaned', function () {
    $first = $this->batch->start($this->user, $this->design, $this->request);
    fakeBatchRenderer(fn (array $input) => $input['document']['width'] === 80);
    runRenders($first['exports']);
    $statuses = DesignExport::ownedBy($this->user->id)->orderBy('id')->pluck('status')->all();
    expect($statuses)->toBe(['ready', 'failed', 'ready'])->and(MediaAsset::ownedBy($this->user->id)->count())->toBe(2);

    $second = $this->batch->start($this->user, $this->design, $this->request);

    expect($second['created'])->toBe(1)->and($second['reused'])->toBe(2);
    Queue::assertPushed(RenderStudioDesign::class, 4);
    fakeBatchRenderer();
    app(StudioRenderer::class)->render(collect($second['exports'])->firstWhere('status', 'pending')['id']);
    expect(MediaAsset::ownedBy($this->user->id)->count())->toBe(3)
        ->and(DesignExport::ownedBy($this->user->id)->where('status', 'ready')->count())->toBe(3);
    // La exportación fallida no se vuelve a reutilizar ni aparece como parte del lote.
    expect(array_column($second['exports'], 'status'))->not->toContain('failed');
});

test('an export whose library file was deleted is rendered again instead of reused', function () {
    $first = $this->batch->start($this->user, $this->design, $this->request);
    fakeBatchRenderer();
    runRenders($first['exports']);
    $mediaId = DesignExport::ownedBy($this->user->id)->orderBy('id')->first()->media_ids[0];
    StudioAsset::withoutUserScope()->where('media_asset_id', $mediaId)->delete();
    MediaAsset::ownedBy($this->user->id)->whereKey($mediaId)->delete();

    $second = $this->batch->start($this->user, $this->design, $this->request);

    expect($second['created'])->toBe(1)->and($second['reused'])->toBe(2);
});

test('a different revision, mode, format or size is a different batch', function () {
    $this->batch->start($this->user, $this->design, $this->request);
    $this->batch->start($this->user, $this->design, ['mode' => 'contain'] + $this->request);
    $this->batch->start($this->user, $this->design, ['format' => 'jpeg'] + $this->request);
    $changed = $this->request;
    $changed['formats'][0]['width'] = 120;
    $result = $this->batch->start($this->user, $this->design, $changed);

    expect($result['created'])->toBe(1)->and($result['reused'])->toBe(2)->and(DesignExport::ownedBy($this->user->id)->count())->toBe(10);
});

test('the batch applies the brand watermark to every file when it is active', function () {
    $logo = redLogo($this->user, $this->brand);
    app(StudioService::class)->updateKit($this->user, $this->brand, ['logos' => [$logo->id],
        'watermark' => ['enabled' => true, 'logo_id' => $logo->id, 'position' => 'bottom-right', 'opacity' => 100, 'size' => 20, 'margin' => 5]]);
    $withMark = $this->batch->start($this->user, $this->design, $this->request);
    $without = $this->batch->start($this->user, $this->design, ['watermark' => false] + $this->request);
    fakeBatchRenderer();
    runRenders($withMark['exports']);
    runRenders($without['exports']);

    expect($withMark['created'])->toBe(3)->and($without['created'])->toBe(3);
    foreach (DesignExport::ownedBy($this->user->id)->orderBy('id')->get() as $index => $export) {
        $media = MediaAsset::withoutUserScope()->findOrFail($export->media_ids[0]);
        $image = imagecreatefromstring(Storage::disk($media->disk)->get($media->path));
        $corner = imagecolorat($image, $media->width - 8, $media->height - 8);
        $top = imagecolorat($image, 2, 2);
        expect($export->options['batch'])->toBeString()
            ->and(($corner >> 16) & 255)->toBe($index < 3 ? 255 : 0, "export {$export->id}")
            ->and($top & 255)->toBe(255);
    }
});

test('the batch is rejected for limits, repeated or invalid formats and unknown pages', function (array $change, string $field) {
    expect(fn () => $this->batch->start($this->user, $this->design, $change + $this->request))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field));
    expect(DesignExport::ownedBy($this->user->id)->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'no formats' => [['formats' => []], 'formats'],
    'repeated size' => [['formats' => [['width' => 100, 'height' => 100], ['width' => 100, 'height' => 100]]], 'formats'],
    'too many formats' => [['formats' => array_map(fn (int $i) => ['width' => 100 + $i, 'height' => 100], range(1, 9))], 'formats'],
    'tiny size' => [['formats' => [['width' => 10, 'height' => 100]]], 'formats.0.width'],
    'huge size' => [['formats' => [['width' => 100, 'height' => 9000]]], 'formats.0.height'],
    'video format' => [['format' => 'mp4'], 'format'],
    'unknown mode' => [['mode' => 'stretch'], 'mode'],
    'unknown page' => [['pages' => ['nope']], 'pages'],
    'unknown revision' => [['revision' => 99], 'revision'],
]);

test('a batch does not exceed the active export limit of the user', function () {
    config(['studio.batch.max_active' => 4]);
    $this->batch->start($this->user, $this->design, $this->request);

    expect(fn () => $this->batch->start($this->user, $this->design, ['mode' => 'contain'] + $this->request))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('formats'));
    expect(DesignExport::ownedBy($this->user->id)->count())->toBe(3);
});

test('a format that fails validation rolls the whole creation back so a batch is never half created', function () {
    $logo = redLogo($this->user, $this->brand);
    app(StudioService::class)->updateKit($this->user, $this->brand, ['logos' => [$logo->id], 'watermark' => ['enabled' => true, 'logo_id' => $logo->id]]);
    // El segundo formato (64 megapíxeles) no admite marca de agua: falla después de que el primero ya se creara dentro de la transacción.
    $request = ['formats' => [['width' => 100, 'height' => 100], ['width' => 8000, 'height' => 8000]]] + $this->request;

    expect(fn () => $this->batch->start($this->user, $this->design, $request))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('watermark'));
    expect(DesignExport::ownedBy($this->user->id)->count())->toBe(0);
});

test('another user can neither start nor see a batch of a design that is not theirs', function () {
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->postJson(route('studio.exports.batch', $this->design), $this->request)->assertNotFound();
    expect(fn () => $this->batch->start($stranger, $this->design, $this->request))->toThrow(ModelNotFoundException::class);
    expect(DesignExport::withoutUserScope()->count())->toBe(0);
});

test('the batch endpoint accepts the request and the export list shows the files of each format', function () {
    $response = $this->actingAs($this->user)->postJson(route('studio.exports.batch', $this->design), $this->request)
        ->assertStatus(202)->assertJsonCount(3, 'exports')->assertJsonPath('created', 3)->assertJsonPath('exports.0.status', 'pending');
    fakeBatchRenderer();
    runRenders($response->json('exports'));

    $this->actingAs($this->user)->postJson(route('studio.exports.batch', $this->design), $this->request)
        ->assertStatus(202)->assertJsonPath('created', 0)->assertJsonPath('reused', 3)->assertJsonPath('exports.0.status', 'ready');
    $this->actingAs($this->user)->postJson(route('studio.exports.batch', $this->design), ['formats' => 'x'] + $this->request)->assertUnprocessable();
});
