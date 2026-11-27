<?php

use App\Models\Brand;
use App\Models\User;
use App\Services\Studio\StudioBatchExport;
use App\Services\Studio\StudioDocument;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user);
    $this->url = '/brands/'.$this->brand->id.'/studio/designs';
});

function animatedScene(array $animation, ?array $timing = ['start' => 1, 'duration' => 3]): array
{
    $doc = StudioDocument::blank(320, 180);
    $doc['schema_version'] = 2;
    $doc['timeline'] = ['fps' => 30, 'audio_tracks' => []];
    $element = ['id' => 'title', 'type' => 'text', 'text' => 'Hola', 'x' => 10, 'y' => 10, 'width' => 200, 'height' => 40, 'animation' => $animation];
    if ($timing !== null) {
        $element['timing'] = $timing;
    }
    $doc['pages'][0]['elements'] = [$element];

    return $doc;
}

test('an animation per object persists exactly and survives a later revision', function () {
    $animation = ['in' => ['type' => 'slide_left', 'duration' => 0.5, 'delay' => 0.25, 'easing' => 'ease_out'], 'out' => ['type' => 'fade', 'duration' => 1]];
    $doc = animatedScene($animation);
    $created = $this->postJson($this->url, ['name' => 'Animado', 'document' => $doc])->assertCreated()->json('design');
    expect($created['document'])->toBe($doc);
    $this->patchJson('/studio/designs/'.$created['id'], ['name' => 'Animado', 'document' => $doc, 'revision' => 1])
        ->assertOk()->assertJsonPath('design.document.pages.0.elements.0.animation', $animation);
});

test('cropped phases preserve their original duration and signed anchoring delay', function (array $animation) {
    $doc = animatedScene($animation, ['start' => 1, 'duration' => 0.5]);
    $this->postJson($this->url, ['name' => 'Recorte', 'document' => $doc])->assertCreated()
        ->assertJsonPath('design.document.pages.0.elements.0.animation', $animation);
})->with([
    'entrance in progress' => [['in' => ['type' => 'slide_left', 'duration' => 2, 'delay' => -0.75, 'clipped' => true]]],
    'exit already finished' => [['out' => ['type' => 'zoom', 'duration' => 1, 'delay' => 2, 'clipped' => true]]],
]);

test('every animation type and easing is accepted, also on an element without its own timing window', function (string $type) {
    foreach (['linear', 'ease_in', 'ease_out', 'ease_in_out'] as $easing) {
        $doc = animatedScene(['in' => ['type' => $type, 'duration' => 1, 'easing' => $easing]], null);
        $this->postJson($this->url, ['name' => "$type $easing", 'document' => $doc])->assertCreated();
    }
})->with(['fade', 'slide_left', 'slide_right', 'slide_up', 'slide_down', 'zoom']);

test('invalid animations are rejected', function (array $animation) {
    $this->postJson($this->url, ['name' => 'Mala', 'document' => animatedScene($animation)])->assertUnprocessable();
})->with([
    'unknown type' => [['in' => ['type' => 'spin', 'duration' => 1]]],
    'unknown easing' => [['in' => ['type' => 'fade', 'duration' => 1, 'easing' => 'bounce']]],
    'unknown phase' => [['middle' => ['type' => 'fade', 'duration' => 1]]],
    'unknown field' => [['in' => ['type' => 'fade', 'duration' => 1, 'script' => 'x']]],
    'empty animation' => [[]],
    'duration too short' => [['in' => ['type' => 'fade', 'duration' => 0.01]]],
    'duration too long' => [['in' => ['type' => 'fade', 'duration' => 11]]],
    'negative delay' => [['in' => ['type' => 'fade', 'duration' => 1, 'delay' => -1]]],
    'string duration' => [['in' => ['type' => 'fade', 'duration' => '1']]],
    'missing duration' => [['in' => ['type' => 'fade']]],
    'phase is a list' => [['in' => [['type' => 'fade', 'duration' => 1]]]],
    'in does not fit the object life' => [['in' => ['type' => 'fade', 'duration' => 2, 'delay' => 1.5]]],
    'in and out overlap' => [['in' => ['type' => 'fade', 'duration' => 2], 'out' => ['type' => 'zoom', 'duration' => 1.5]]],
    'cropped flag must be true' => [['in' => ['type' => 'fade', 'duration' => 1, 'clipped' => false]]],
    'cropped delay below the limit' => [['in' => ['type' => 'fade', 'duration' => 1, 'delay' => -11, 'clipped' => true]]],
    'cropped phase already over before the clip starts' => [['in' => ['type' => 'fade', 'duration' => 1, 'delay' => -5, 'clipped' => true]]],
    'cropped phases overlap' => [['in' => ['type' => 'fade', 'duration' => 2, 'delay' => -0.25, 'clipped' => true], 'out' => ['type' => 'zoom', 'duration' => 2, 'clipped' => true]]],
]);

test('an object that lives too little cannot carry the animation, but an exact fit is valid', function () {
    $short = animatedScene(['in' => ['type' => 'fade', 'duration' => 1]], ['start' => 0, 'duration' => 0.5]);
    $this->postJson($this->url, ['name' => 'Corto', 'document' => $short])->assertUnprocessable();
    $exact = animatedScene(['in' => ['type' => 'fade', 'duration' => 1, 'delay' => 1], 'out' => ['type' => 'fade', 'duration' => 1]], ['start' => 0, 'duration' => 3]);
    $this->postJson($this->url, ['name' => 'Justo', 'document' => $exact])->assertCreated();
});

test('animations also validate inside groups and v1 documents keep rejecting them', function () {
    $doc = animatedScene(['in' => ['type' => 'fade', 'duration' => 1]]);
    $child = $doc['pages'][0]['elements'][0];
    $doc['pages'][0]['elements'] = [['id' => 'box', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 100, 'elements' => [$child]]];
    $this->postJson($this->url, ['name' => 'Grupo', 'document' => $doc])->assertCreated();
    $doc['pages'][0]['elements'][0]['elements'][0]['animation']['in']['duration'] = 99;
    $this->postJson($this->url, ['name' => 'Grupo malo', 'document' => $doc])->assertUnprocessable();
    $v1 = animatedScene(['in' => ['type' => 'fade', 'duration' => 1]]);
    unset($v1['timeline'], $v1['pages'][0]['elements'][0]['timing']);
    $v1['schema_version'] = 1;
    $this->postJson($this->url, ['name' => 'v1', 'document' => $v1])->assertUnprocessable();
});

test('a design without animations is stored exactly as before', function () {
    $doc = animatedScene(['in' => ['type' => 'fade', 'duration' => 1]]);
    unset($doc['pages'][0]['elements'][0]['animation']);
    $created = $this->postJson($this->url, ['name' => 'Sin animar', 'document' => $doc])->assertCreated()->json('design');
    expect($created['document'])->toBe($doc)->and(json_encode($created['document']))->not->toContain('animation');
});

test('server rendering of a frame at a chosen instant receives the animated document untouched', function () {
    $doc = animatedScene(['in' => ['type' => 'zoom', 'duration' => 0.5], 'out' => ['type' => 'slide_right', 'duration' => 0.5]]);
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Render', 'document' => $doc]);
    $export = app(StudioRenderer::class)->export($this->user, $design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_1'], 'frame_times' => [['page_id' => 'page_1', 'time' => 1.25]]]);
    Process::fake(function ($process) use ($doc) {
        $sent = json_decode($process->input, true);
        expect($sent['document']['pages'][0]['elements'][0]['animation'])->toBe($doc['pages'][0]['elements'][0]['animation']);
        $image = imagecreatetruecolor(320, 180);
        imagepng($image, $process->command[2].DIRECTORY_SEPARATOR.'page-001.png');
        imagedestroy($image);

        return Process::result(output: '{"files":[{"name":"page-001.png","mime":"image/png"}]}');
    });
    app(StudioRenderer::class)->render($export->id);
    expect($export->refresh()->status)->toBe('ready', $export->error ?? '');
});

/** Renderer falso que anota lo que recibe Chromium y devuelve una imagen del tamaño del documento. */
function fakeAnimationRenderer(array &$inputs): void
{
    Process::fake(function ($process) use (&$inputs) {
        $input = json_decode($process->input, true);
        $inputs[] = $input;
        $extension = $input['format'] === 'jpeg' ? 'jpg' : 'png';
        $image = imagecreatetruecolor((int) round($input['document']['width'] * ($input['scale'] ?? 1)), (int) round($input['document']['height'] * ($input['scale'] ?? 1)));
        $target = $process->command[2].DIRECTORY_SEPARATOR.'page-001.'.$extension;
        $extension === 'jpg' ? imagejpeg($image, $target, 90) : imagepng($image, $target);

        return Process::result(output: json_encode(['files' => [['name' => 'page-001.'.$extension, 'mime' => $extension === 'jpg' ? 'image/jpeg' : 'image/png']]]));
    });
}

test('static outputs without an explicit instant reach the renderer with no frame time, so animations render settled', function () {
    $doc = animatedScene(['in' => ['type' => 'fade', 'duration' => 1]]);
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Reposo', 'document' => $doc]);
    $inputs = [];
    fakeAnimationRenderer($inputs);

    // Miniatura del listado.
    app(StudioRenderer::class)->previewPage($this->user, $this->brand, $doc, 'page_1', 200, 'studio-exports/'.$this->user->id.'/'.$design->id.'/'.Str::uuid());
    // Imagen nativa y PPTX sin frame_times.
    foreach (['png', 'pptx'] as $format) {
        $export = app(StudioRenderer::class)->export($this->user, $design, ['revision' => 1, 'format' => $format, 'pages' => ['page_1']]);
        app(StudioRenderer::class)->render($export->id);
    }
    // Exportación en lote.
    $batch = app(StudioBatchExport::class)->start($this->user, $design, ['revision' => 1, 'pages' => ['page_1'], 'format' => 'png', 'mode' => 'cover', 'formats' => [['width' => 160, 'height' => 90]]]);
    app(StudioRenderer::class)->render($batch['exports'][0]['id']);

    expect($inputs)->toHaveCount(4);
    foreach ($inputs as $input) {
        expect($input['frame_times'] ?? [])->toBe([])
            ->and(json_encode($input['document']))->toContain('"animation"');
    }
});

test('an explicit instant is forwarded so that frame shows the animation in progress', function () {
    $doc = animatedScene(['in' => ['type' => 'fade', 'duration' => 1]]);
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Instante', 'document' => $doc]);
    $inputs = [];
    fakeAnimationRenderer($inputs);
    $times = [['page_id' => 'page_1', 'time' => 1.5]];
    $export = app(StudioRenderer::class)->export($this->user, $design, ['revision' => 1, 'format' => 'png', 'pages' => ['page_1'], 'frame_times' => $times]);
    app(StudioRenderer::class)->render($export->id);

    expect($inputs[0]['frame_times'])->toBe($times);
});

test('only the owner can store or edit a design with animations', function () {
    $doc = animatedScene(['in' => ['type' => 'fade', 'duration' => 1]]);
    $design = $this->postJson($this->url, ['name' => 'Mío', 'document' => $doc])->assertCreated()->json('design');
    $intruder = User::factory()->create();
    $this->actingAs($intruder);
    $this->patchJson('/studio/designs/'.$design['id'], ['name' => 'Robado', 'document' => $doc, 'revision' => 1])->assertNotFound();
    $this->postJson($this->url, ['name' => 'Ajena', 'document' => $doc])->assertNotFound();
});
