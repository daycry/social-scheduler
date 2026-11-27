<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAsset;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/StudioWatermarkFixtures.php';

/** Renderer falso: devuelve una imagen azul con las dimensiones del documento por la escala. */
function fakeBlueRenderer(): void
{
    Process::fake(function ($process) {
        $input = json_decode($process->input, true);
        $width = (int) round($input['document']['width'] * ($input['scale'] ?? 1));
        $height = (int) round($input['document']['height'] * ($input['scale'] ?? 1));
        $extension = ['png' => 'png', 'jpeg' => 'jpg', 'webp' => 'webp'][$input['format']];
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 255));
        $target = $process->command[2].DIRECTORY_SEPARATOR.'page-001.'.$extension;
        match ($input['format']) {
            'png' => imagepng($image, $target),
            'jpeg' => imagejpeg($image, $target, 100),
            'webp' => imagewebp($image, $target, 100),
        };

        return Process::result(output: json_encode(['files' => [['name' => 'page-001.'.$extension, 'mime' => 'image/'.$input['format']]]]));
    });
}

/** Color [r, g, b] del píxel (x, y) del primer archivo de la exportación. */
function exportPixel(DesignExport $export, int $x, int $y): array
{
    $media = MediaAsset::withoutUserScope()->findOrFail($export->refresh()->media_ids[0]);
    $image = imagecreatefromstring(Storage::disk($media->disk)->get($media->path));
    $color = imagecolorat($image, $x, $y);

    return [($color >> 16) & 255, ($color >> 8) & 255, $color & 255];
}

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->document = ['schema_version' => 1, 'width' => 200, 'height' => 100, 'pages' => [
        ['id' => 'page_one', 'name' => 'Uno', 'background' => '#ffffff', 'duration' => 5, 'elements' => []],
    ]];
    $this->design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Marca de agua', 'document' => $this->document]);
    $this->studio = app(StudioService::class);
});

test('the kit returns a disabled watermark with sensible defaults until configured', function () {
    $kit = $this->studio->kit($this->user, $this->brand);

    expect($kit['watermark'])->toBe(['enabled' => false, 'logo_id' => null, 'position' => 'bottom-right', 'opacity' => 70, 'size' => 20, 'margin' => 3]);
});

test('a brand configures its watermark from a logo of the kit and gets it back', function () {
    $logo = redLogo($this->user, $this->brand);
    $kit = $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id],
        'watermark' => ['enabled' => true, 'logo_id' => $logo->id, 'position' => 'top-left', 'opacity' => 40, 'size' => 25, 'margin' => 5]]);

    expect($kit['watermark'])->toBe(['enabled' => true, 'logo_id' => $logo->id, 'position' => 'top-left', 'opacity' => 40, 'size' => 25, 'margin' => 5]);
    // Un cambio posterior del kit que no toca la marca de agua la conserva.
    expect($this->studio->updateKit($this->user, $this->brand, ['voice' => 'cercana'])['watermark']['logo_id'])->toBe($logo->id);
});

test('the watermark rejects out of range values, unknown positions and logos that are not in the kit', function (array $watermark, string $field) {
    $logo = redLogo($this->user, $this->brand);
    $foreign = MediaAsset::factory()->create(['mime' => 'image/png']);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id]]);
    $watermark = array_map(fn ($value) => $value === 'FOREIGN' ? $foreign->id : $value, $watermark);

    expect(fn () => $this->studio->updateKit($this->user, $this->brand, ['watermark' => $watermark + ['enabled' => true, 'logo_id' => $logo->id]]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($field));
    expect($this->studio->kit($this->user, $this->brand)['watermark']['enabled'])->toBeFalse();
})->with([
    'opacity zero' => [['opacity' => 0], 'watermark.opacity'],
    'opacity above 100' => [['opacity' => 101], 'watermark.opacity'],
    'unknown position' => [['position' => 'diagonal'], 'watermark.position'],
    'size too small' => [['size' => 2], 'watermark.size'],
    'size too large' => [['size' => 80], 'watermark.size'],
    'margin too large' => [['margin' => 40], 'watermark.margin'],
    'foreign logo' => [['logo_id' => 'FOREIGN'], 'watermark.logo_id'],
    'missing logo' => [['logo_id' => 987654], 'watermark.logo_id'],
    'no logo while enabled' => [['logo_id' => null], 'watermark.logo_id'],
]);

test('a logo registered in the brand but not in the kit logos cannot be the watermark', function () {
    $logo = redLogo($this->user, $this->brand);

    expect(fn () => $this->studio->updateKit($this->user, $this->brand, ['watermark' => ['enabled' => true, 'logo_id' => $logo->id]]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('watermark.logo_id'));
});

test('removing the logo from the kit while the watermark uses it is rejected', function () {
    $logo = redLogo($this->user, $this->brand);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id], 'watermark' => ['enabled' => true, 'logo_id' => $logo->id]]);

    expect(fn () => $this->studio->updateKit($this->user, $this->brand, ['logos' => []]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('watermark.logo_id'));
});

test('the exported file carries the watermark in the configured corner when it is active', function () {
    $logo = redLogo($this->user, $this->brand);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id],
        'watermark' => ['enabled' => true, 'logo_id' => $logo->id, 'position' => 'bottom-right', 'opacity' => 100, 'size' => 20, 'margin' => 5]]);
    fakeBlueRenderer();

    $export = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one']]);
    app(StudioRenderer::class)->render($export->id);

    expect($export->refresh()->status)->toBe('ready', $export->error ?? '')
        ->and($export->options['watermark'])->toBe(['logo_id' => $logo->id, 'position' => 'bottom-right', 'opacity' => 100, 'size' => 20, 'margin' => 5])
        ->and(exportPixel($export, 175, 75))->toBeNearColor([255, 0, 0])
        ->and(exportPixel($export, 10, 10))->toBeNearColor([0, 0, 255])
        ->and(exportPixel($export, 100, 50))->toBeNearColor([0, 0, 255]);
    $media = MediaAsset::withoutUserScope()->findOrFail($export->media_ids[0]);
    expect($media->width)->toBe(200)->and($media->height)->toBe(100);
});

test('the watermark respects opacity and the position of every corner and the center', function () {
    $logo = redLogo($this->user, $this->brand);
    $places = ['top-left' => [25, 25], 'top-right' => [175, 25], 'bottom-left' => [25, 75], 'bottom-right' => [175, 75], 'center' => [100, 50]];
    fakeBlueRenderer();
    foreach ($places as $position => [$x, $y]) {
        $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id],
            'watermark' => ['enabled' => true, 'logo_id' => $logo->id, 'position' => $position, 'opacity' => 50, 'size' => 20, 'margin' => 5]]);
        $export = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one']]);
        app(StudioRenderer::class)->render($export->id);

        [$red, , $blue] = exportPixel($export, $x, $y);
        // Rojo sobre azul al 50 %: ambos canales intermedios, ninguno puro.
        expect($red)->toBeBetween(100, 160, $position)->and($blue)->toBeBetween(100, 160, $position);
    }
});

test('there is no watermark when the brand disabled it or the export opts out', function () {
    $logo = redLogo($this->user, $this->brand);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id],
        'watermark' => ['enabled' => false, 'logo_id' => $logo->id, 'opacity' => 100, 'size' => 20, 'margin' => 5]]);
    fakeBlueRenderer();
    $plain = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one']]);
    app(StudioRenderer::class)->render($plain->id);
    expect($plain->refresh()->options)->not->toHaveKey('watermark')->and(exportPixel($plain, 175, 75))->toBeNearColor([0, 0, 255]);

    $this->studio->updateKit($this->user, $this->brand, ['watermark' => ['enabled' => true]]);
    $optOut = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one'], 'watermark' => false]);
    app(StudioRenderer::class)->render($optOut->id);
    expect($optOut->refresh()->options)->not->toHaveKey('watermark')->and(exportPixel($optOut, 175, 75))->toBeNearColor([0, 0, 255]);

    // La opción por exportación también permite aplicarla con la marca desactivada.
    $this->studio->updateKit($this->user, $this->brand, ['watermark' => ['enabled' => false]]);
    $forced = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one'], 'watermark' => true]);
    app(StudioRenderer::class)->render($forced->id);
    expect(exportPixel($forced, 175, 75))->toBeNearColor([255, 0, 0]);
});

test('asking for a watermark without a configured logo or in a format without pixels is rejected', function () {
    expect(fn () => app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one'], 'watermark' => true]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('watermark'));

    $logo = redLogo($this->user, $this->brand);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id], 'watermark' => ['enabled' => true, 'logo_id' => $logo->id]]);
    expect(fn () => app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'pdf', 'pages' => ['page_one'], 'watermark' => true]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('watermark'));
    // Sin petición explícita, un formato sin píxeles se exporta sin marca de agua en vez de fallar.
    $pdf = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'pdf', 'pages' => ['page_one']]);
    expect($pdf->options)->not->toHaveKey('watermark');
});

test('jpeg and webp exports are watermarked too', function (string $format) {
    $logo = redLogo($this->user, $this->brand);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id],
        'watermark' => ['enabled' => true, 'logo_id' => $logo->id, 'opacity' => 100, 'size' => 20, 'margin' => 5]]);
    fakeBlueRenderer();
    $export = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => $format, 'pages' => ['page_one']]);
    app(StudioRenderer::class)->render($export->id);

    [$red, , $blue] = exportPixel($export, 175, 75);
    expect($export->refresh()->status)->toBe('ready', $export->error ?? '')->and($red)->toBeGreaterThan(200)->and($blue)->toBeLessThan(60);
})->with(['jpeg', 'webp']);

test('the watermark is pinned when the export is created, not when it renders', function () {
    $logo = redLogo($this->user, $this->brand);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id], 'watermark' => ['enabled' => true, 'logo_id' => $logo->id, 'opacity' => 100, 'size' => 20, 'margin' => 5]]);
    $export = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one']]);
    $this->studio->updateKit($this->user, $this->brand, ['watermark' => ['enabled' => false, 'position' => 'center']]);
    fakeBlueRenderer();
    app(StudioRenderer::class)->render($export->id);

    expect(exportPixel($export, 175, 75))->toBeNearColor([255, 0, 0]);
});

test('the kit endpoint saves the watermark and another user cannot touch the brand', function () {
    $logo = redLogo($this->user, $this->brand);
    $payload = ['logos' => [$logo->id], 'watermark' => ['enabled' => true, 'logo_id' => $logo->id, 'position' => 'center', 'opacity' => 60, 'size' => 30, 'margin' => 2]];

    $this->actingAs($this->user)->putJson(route('studio.kit.update', $this->brand), $payload)
        ->assertOk()->assertJsonPath('kit.watermark.position', 'center')->assertJsonPath('kit.watermark.opacity', 60);
    $this->actingAs($this->user)->putJson(route('studio.kit.update', $this->brand), ['watermark' => ['opacity' => 500]])
        ->assertUnprocessable()->assertJsonValidationErrors(['watermark.opacity']);
    $this->actingAs(User::factory()->create())->putJson(route('studio.kit.update', $this->brand), $payload)->assertNotFound();
});

test('the opacity multiplies the alpha of the logo so semitransparent pixels stay semitransparent', function () {
    $logo = MediaAsset::factory()->for($this->user)->create(['mime' => 'image/png', 'path' => 'media/'.$this->user->id.'/ghost.png', 'original_name' => 'ghost.png', 'width' => 10, 'height' => 10]);
    $image = imagecreatetruecolor(10, 10);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 0, 0, 64));
    ob_start();
    imagepng($image);
    Storage::disk('local')->put($logo->path, (string) ob_get_clean());
    app(StudioAssets::class)->attach($this->user, $this->brand, $logo->id);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id],
        'watermark' => ['enabled' => true, 'logo_id' => $logo->id, 'opacity' => 50, 'size' => 20, 'margin' => 5]]);
    fakeBlueRenderer();
    $export = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one']]);
    app(StudioRenderer::class)->render($export->id);

    // Cobertura (127 - 64) / 127 * 0,5 = 0,25: el rojo queda cerca de 64 y el azul cerca de 191.
    [$red, , $blue] = exportPixel($export, 175, 75);
    expect($red)->toBeBetween(45, 85)->and($blue)->toBeBetween(170, 210);
});

test('a logo with too many pixels is rejected when configured and a corrupt logo fails the export in a controlled way', function () {
    $huge = MediaAsset::factory()->for($this->user)->create(['mime' => 'image/png', 'path' => 'media/'.$this->user->id.'/huge.png', 'original_name' => 'huge.png', 'width' => 16001, 'height' => 16001]);
    Storage::disk('local')->put($huge->path, solidPng(10, 10, [255, 0, 0]));
    app(StudioAssets::class)->attach($this->user, $this->brand, $huge->id);
    expect(fn () => $this->studio->updateKit($this->user, $this->brand, ['logos' => [$huge->id], 'watermark' => ['enabled' => true, 'logo_id' => $huge->id]]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('watermark.logo_id'));

    $logo = redLogo($this->user, $this->brand);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id], 'watermark' => ['enabled' => true, 'logo_id' => $logo->id]]);
    // El fichero ya no es la imagen que declaraba el registro: ni basura ni cabeceras de otro tamaño llegan a decodificarse.
    Storage::disk('local')->put($logo->path, 'esto no es una imagen');
    fakeBlueRenderer();
    $broken = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one']]);
    app(StudioRenderer::class)->render($broken->id);
    expect($broken->refresh()->status)->toBe('failed')->and($broken->error)->toContain('logotipo');

    Storage::disk('local')->put($logo->path, solidPng(300, 300, [255, 0, 0]));
    config(['studio.watermark.logo_max_pixels' => 1000]);
    $big = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one']]);
    app(StudioRenderer::class)->render($big->id);
    expect($big->refresh()->status)->toBe('failed')->and($big->error)->toContain('logotipo');
});

test('with the watermark off a missing logo does not block unrelated kit updates', function () {
    $logo = redLogo($this->user, $this->brand);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id], 'watermark' => ['enabled' => false, 'logo_id' => $logo->id]]);
    StudioAsset::withoutUserScope()->where('media_asset_id', $logo->id)->delete();
    MediaAsset::ownedBy($this->user->id)->whereKey($logo->id)->delete();

    expect($this->studio->updateKit($this->user, $this->brand, ['voice' => 'cercana'])['voice'])->toBe('cercana');
});

test('a failure on a later page leaves no library files or directories behind', function () {
    $logo = redLogo($this->user, $this->brand);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id], 'watermark' => ['enabled' => true, 'logo_id' => $logo->id]]);
    $document = $this->document;
    $document['pages'][] = ['id' => 'page_two', 'name' => 'Dos', 'background' => '#ffffff', 'duration' => 5, 'elements' => []];
    $design = $this->studio->create($this->user, $this->brand, ['name' => 'Dos páginas', 'document' => $document]);
    Process::fake(function ($process) {
        $dir = $process->command[2];
        foreach ([1, 2] as $n) {
            $image = imagecreatetruecolor(200, 100);
            // La segunda página sale corrupta: GD no la puede decodificar.
            $n === 1 ? imagepng($image, $dir.DIRECTORY_SEPARATOR.'page-001.png') : file_put_contents($dir.DIRECTORY_SEPARATOR.'page-002.png', 'corrupta');
        }

        return Process::result(output: json_encode(['files' => [['name' => 'page-001.png', 'mime' => 'image/png'], ['name' => 'page-002.png', 'mime' => 'image/png']]]));
    });
    $export = app(StudioRenderer::class)->export($this->user, $design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_one', 'page_two']]);
    app(StudioRenderer::class)->render($export->id);

    expect($export->refresh()->status)->toBe('failed')->and($export->media_ids)->toBeNull();
    expect(MediaAsset::ownedBy($this->user->id)->whereKeyNot($logo->id)->count())->toBe(0)
        ->and(Storage::disk('local')->files('media/'.$this->user->id))->toBe(['media/'.$this->user->id.'/logo.png'])
        ->and(Storage::disk('local')->directories('studio-exports/'.$this->user->id.'/'.$export->id))->toBe([]);
});

test('a watermarked jpeg is rendered losslessly and encoded only once with the requested quality', function () {
    $logo = redLogo($this->user, $this->brand);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id], 'watermark' => ['enabled' => true, 'logo_id' => $logo->id, 'opacity' => 100, 'size' => 20, 'margin' => 5]]);
    fakeBlueRenderer();
    $marked = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'jpeg', 'pages' => ['page_one']]);
    app(StudioRenderer::class)->render($marked->id);
    $plain = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'jpeg', 'pages' => ['page_one'], 'watermark' => false]);
    app(StudioRenderer::class)->render($plain->id);

    Process::assertRanTimes(fn ($process) => json_decode($process->input, true)['format'] === 'png', 1);
    Process::assertRanTimes(fn ($process) => json_decode($process->input, true)['format'] === 'jpeg', 1);
    expect(MediaAsset::withoutUserScope()->findOrFail($marked->refresh()->media_ids[0])->mime)->toBe('image/jpeg')
        ->and(array_map('basename', Storage::disk('local')->allFiles('studio-exports/'.$this->user->id.'/'.$marked->id)))->toBe(['page-001.jpg']);
});

test('a watermarked jpeg flattens transparency onto white like the unmarked one and webp keeps its alpha', function (string $format, array $options, array $expected) {
    $logo = redLogo($this->user, $this->brand);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id], 'watermark' => ['enabled' => true, 'logo_id' => $logo->id, 'opacity' => 100, 'size' => 20, 'margin' => 5]]);
    Process::fake(function ($process) {
        $image = imagecreatetruecolor(200, 100);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagepng($image, $process->command[2].DIRECTORY_SEPARATOR.'page-001.png');

        return Process::result(output: json_encode(['files' => [['name' => 'page-001.png', 'mime' => 'image/png']]]));
    });
    $export = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => $format, 'pages' => ['page_one']] + $options);
    app(StudioRenderer::class)->render($export->id);

    $media = MediaAsset::withoutUserScope()->findOrFail($export->refresh()->media_ids[0]);
    $image = imagecreatefromstring(Storage::disk($media->disk)->get($media->path));
    imagealphablending($image, false);
    $color = imagecolorat($image, 10, 10);
    expect($export->status)->toBe('ready', $export->error ?? '')
        ->and([($color >> 16) & 255, ($color >> 8) & 255, $color & 255])->toBe($expected[0])
        ->and(($color >> 24) & 127)->toBeBetween($expected[1], $expected[2]);
})->with([
    'jpeg' => ['jpeg', [], [[255, 255, 255], 0, 0]],
    'jpeg transparent option' => ['jpeg', ['transparent' => true], [[255, 255, 255], 0, 0]],
    'webp' => ['webp', ['transparent' => true], [[0, 0, 0], 120, 127]],
]);

test('the size limit applies to the final encoded file and not to the lossless intermediate', function () {
    $logo = redLogo($this->user, $this->brand);
    $this->studio->updateKit($this->user, $this->brand, ['logos' => [$logo->id], 'watermark' => ['enabled' => true, 'logo_id' => $logo->id]]);
    fakeBlueRenderer();
    config(['studio.export.max_file_bytes' => 3000]);
    // El PNG intermedio de 200x100 pesa menos que el tope pero el límite se mide sobre el JPEG final: con un tope de 100 bytes falla.
    $ok = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'jpeg', 'pages' => ['page_one']]);
    app(StudioRenderer::class)->render($ok->id);
    expect($ok->refresh()->status)->toBe('ready', $ok->error ?? '');
    config(['studio.export.max_file_bytes' => 100]);
    $tooBig = app(StudioRenderer::class)->export($this->user, $this->design, ['revision' => 1, 'format' => 'jpeg', 'pages' => ['page_one']]);
    app(StudioRenderer::class)->render($tooBig->id);
    expect($tooBig->refresh()->status)->toBe('failed')->and($tooBig->error)->toContain('tamaño');
});
