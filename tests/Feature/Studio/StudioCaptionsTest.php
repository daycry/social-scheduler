<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAsset;
use App\Models\Studio\StudioFont;
use App\Models\User;
use App\Services\Studio\StudioAvProcess;
use App\Services\Studio\StudioCaptionCodec;
use App\Services\Studio\StudioCaptions;
use App\Services\Studio\StudioDocument;
use App\Services\Studio\StudioFonts;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\McpClient;

uses(McpClient::class);

function captionDocument(): array
{
    $doc = StudioDocument::blank(320, 180);
    $doc['schema_version'] = 2;
    $doc['timeline'] = ['fps' => 30, 'audio_tracks' => [], 'captions' => ['style' => ['fontSize' => 24, 'position' => 'bottom'],
        'cues' => [['id' => 'cue_1', 'start' => 0.5, 'end' => 1.5, 'text' => "Hola 🎵\nsegunda línea"]]]];

    return $doc;
}

test('captions document preserves Unicode and exact legacy absence through revisions', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $doc = captionDocument();
    $studio = app(StudioService::class);
    $design = $studio->create($user, $brand, ['name' => 'Subtítulos', 'document' => $doc]);
    expect($design->document)->toBe($doc);
    unset($doc['timeline']['captions']);
    $design = $studio->update($user, $design, ['name' => 'Sin pista', 'document' => $doc, 'revision' => 1]);
    expect($design->document)->toBe($doc)->and(array_key_exists('captions', $design->document['timeline']))->toBeFalse();
});

test('captions validator rejects invalid data before persistence', function (string $case) {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $doc = captionDocument();
    $caption = &$doc['timeline']['captions'];
    match ($case) {
        'numeric' => $caption['cues'][0]['start'] = '0.5',
        'empty' => $caption['cues'][0]['end'] = 0.5,
        'negative' => $caption['cues'][0]['start'] = -1,
        'outside' => $caption['cues'][0]['end'] = 100,
        'duplicate' => $caption['cues'][] = $caption['cues'][0],
        'overlap' => $caption['cues'] = array_map(fn ($i) => ['id' => 'cue_'.$i, 'start' => 0, 'end' => 1, 'text' => 'x'], range(1, 9)),
        'count' => $caption['cues'] = array_map(fn ($i) => ['id' => 'cue_'.$i, 'start' => 0, 'end' => 1, 'text' => 'x'], range(1, 5001)),
        'text' => $caption['cues'][0]['text'] = str_repeat('🎵', 501),
        'control' => $caption['cues'][0]['text'] = "a\0b",
        'style' => $caption['style']['url'] = 'https://foreign/font',
        'internal' => $caption['style']['fontFamily'] = 'StudioFont_1_fake',
        'geometry' => $caption['style']['margin'] = 0.3,
    };
    expect(fn () => app(StudioDocument::class)->validate($user, $brand, $doc))->toThrow(ValidationException::class);
})->with(['numeric', 'empty', 'negative', 'outside', 'duplicate', 'overlap', 'count', 'text', 'control', 'style', 'internal', 'geometry']);

test('captions private font participates in ownership and historical usage without text layers', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $font = StudioFont::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => 'Propia', 'family' => 'Caption Family', 'status' => 'ready',
        'style' => 'normal', 'weight' => 400, 'format' => 'woff2', 'mime' => 'font/woff2', 'size' => 64, 'sha256' => str_repeat('a', 64), 'source_sha256' => str_repeat('a', 64), 'disk' => 'local', 'path' => 'fixture.woff2']);
    $doc = captionDocument();
    $doc['timeline']['captions']['style'] += ['font_id' => $font->id, 'fontFamily' => $font->family];
    $studio = app(StudioService::class);
    $design = $studio->create($user, $brand, ['name' => 'Propia', 'document' => $doc]);
    expect(DB::table('studio_font_usages')->where('font_id', $font->id)->count())->toBe(1);
    expect($studio->present($user, $design)['fonts'][0]['id'])->toBe($font->id);
    expect(fn () => app(StudioFonts::class)->delete($user, $brand, $font->id))->toThrow(HttpException::class);
    $other = Brand::factory()->for($user)->create();
    expect(fn () => $studio->create($user, $other, ['name' => 'Ajena', 'document' => $doc]))->toThrow(ValidationException::class);
    $doc['timeline']['captions']['style']['fontFamily'] = 'Wrong Family';
    expect(fn () => $studio->create($user, $brand, ['name' => 'Familia incorrecta', 'document' => $doc]))->toThrow(ValidationException::class);
});

test('captions web import creates an optimistic revision with plain Unicode cues and pinned exports', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $doc = captionDocument();
    unset($doc['timeline']['captions']);
    $design = app(StudioService::class)->create($user, $brand, ['name' => 'Importar', 'document' => $doc]);
    $this->actingAs($user);
    $url = '/studio/designs/'.$design->id.'/captions';
    $text = "\xEF\xBB\xBF1\r\n00:00:00,500 --> 00:00:01,500\r\nHola 🎵\r\nsegunda línea\r\n";
    $result = $this->patchJson($url, ['action' => 'import', 'revision' => 1, 'format' => 'srt', 'text' => $text, 'mode' => 'replace'])->assertOk()->assertJsonPath('revision', 2)->json();
    expect($result['cues'][0]['text'])->toBe("Hola 🎵\nsegunda línea")->and($result['cues'][0]['start'])->toBe(0.5);
    $this->patchJson($url, ['action' => 'offset', 'revision' => 1, 'seconds' => 1])->assertConflict();
    $this->patchJson($url, ['action' => 'offset', 'revision' => 2, 'seconds' => 1])->assertOk()->assertJsonPath('revision', 3)->assertJsonPath('cues.0.start', 1.5);
    $this->get($url.'/export?revision=2&format=vtt')->assertOk()->assertSee('00:00:00.500 --> 00:00:01.500', false);
    $this->getJson($url.'?per_page=101')->assertUnprocessable();
    $this->patchJson($url, ['action' => 'offset', 'revision' => 3, 'seconds' => -2])->assertUnprocessable();
    $this->getJson($url)->assertOk()->assertJsonPath('revision', 3)->assertJsonPath('cues.0.start', 1.5);
});

test('captions codec preserves significant whitespace across plain SRT and VTT roundtrips', function () {
    $codec = app(StudioCaptionCodec::class);
    $cue = ['id' => 'cue_1', 'start' => 0.5, 'end' => 1.5, 'text' => "  Hola 🎵\n segunda línea  "];
    foreach (['srt', 'vtt'] as $format) {
        expect($codec->parse($codec->encode([$cue], $format), $format)['cues'])->toBe([$cue]);
    }
});

test('captions MCP supports dedicated shared operations and read write scopes', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $design = app(StudioService::class)->create($user, $brand, ['name' => 'MCP captions', 'document' => captionDocument()]);
    $read = $this->mcpKey($user, ['designs:read']);
    $write = $this->mcpKey($user, ['designs:write']);
    [$error, $get] = $this->callTool($read, 'manage_design_captions', ['action' => 'get', 'design_id' => $design->id]);
    expect($error)->toBeFalse()->and($get['cues'][0]['text'])->toBe("Hola 🎵\nsegunda línea");
    [$denied] = $this->callTool($read, 'manage_design_captions', ['action' => 'clear', 'design_id' => $design->id, 'revision' => 1]);
    expect($denied)->toBeTrue();
    [$error, $saved] = $this->callTool($write, 'manage_design_captions', ['action' => 'style', 'design_id' => $design->id, 'revision' => 1, 'style' => ['fontSize' => 36]]);
    expect($error)->toBeFalse()->and($saved['revision'])->toBe(2);
    [$denied] = $this->callTool($write, 'manage_design_captions', ['action' => 'export', 'design_id' => $design->id, 'format' => 'srt']);
    expect($denied)->toBeTrue();
    [$error, $file] = $this->callTool($read, 'manage_design_captions', ['action' => 'export', 'design_id' => $design->id, 'format' => 'vtt', 'revision' => 1]);
    expect($error)->toBeFalse()->and($file['text'])->toContain('WEBVTT', '00:00:00.500');
    [$foreign] = $this->callTool($this->mcpKey(User::factory()->create()), 'manage_design_captions', ['action' => 'get', 'design_id' => $design->id]);
    expect($foreign)->toBeTrue();
});

test('captions empty style serializes as an object that the JS document contract can reopen', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $doc = captionDocument();
    $doc['timeline']['captions']['style'] = [];
    $design = app(StudioService::class)->create($user, $brand, ['name' => 'Estilo vacío', 'document' => $doc]);
    $wire = json_decode(json_encode($design->document));
    expect($wire->timeline->captions->style)->toBeInstanceOf(stdClass::class);
});

test('captions codec rejects invalid intervals independently of a document handler', function () {
    expect(fn () => app(StudioCaptionCodec::class)->parse("1\n00:00:02,000 --> 00:00:01,000\nx\n", 'srt'))
        ->toThrow(ValidationException::class);
});

test('captions MCP import preserves trailing caption whitespace through middleware', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $design = app(StudioService::class)->create($user, $brand, ['name' => 'Espacios MCP', 'document' => captionDocument()]);
    [$error, $saved] = $this->callTool($this->mcpKey($user), 'manage_design_captions', ['action' => 'import', 'design_id' => $design->id, 'revision' => 1, 'format' => 'srt',
        'text' => "1\n00:00:00,500 --> 00:00:01,500\n Hola  \n"]);
    expect($error)->toBeFalse()->and($saved['pagination']['total'])->toBe(2)
        ->and(array_column($saved['cues'], 'text'))->toContain(' Hola  ');
});

test('captions API enforces abilities ownership explicit activation and every compact mutation', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $design = app(StudioService::class)->create($user, $brand, ['name' => 'API captions', 'document' => StudioDocument::blank(320, 180)]);
    $url = '/api/v1/designs/'.$design->id.'/captions';
    $read = $this->mcpKey($user, ['designs:read']);
    $write = $this->mcpKey($user, ['designs:write']);
    $headers = function ($key) {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer '.$key];
    };
    $this->getJson($url)->assertUnauthorized();
    $this->patchJson($url, ['action' => 'clear', 'revision' => 1], $headers($read))->assertForbidden();
    $this->getJson($url, $headers($write))->assertForbidden();
    $this->getJson($url, $headers($this->mcpKey(User::factory()->create())))->assertNotFound();
    $payload = ['action' => 'replace', 'revision' => 1, 'captions' => captionDocument()['timeline']['captions']];
    $this->patchJson($url, $payload, $headers($write))->assertUnprocessable();
    $this->patchJson($url, $payload + ['activate_temporal' => true], $headers($write))->assertOk()->assertJsonPath('revision', 2);
    $this->patchJson($url, ['action' => 'upsert', 'revision' => 2, 'cue' => ['id' => 'extra', 'start' => 2, 'end' => 3, 'text' => 'Otro']], $headers($write))
        ->assertOk()->assertJsonPath('pagination.total', 2)->assertJsonPath('revision', 3);
    $this->getJson($url.'?per_page=1&page=2', $headers($read))->assertOk()->assertJsonPath('cues.0.id', 'extra')->assertJsonPath('pagination.next_page', null);
    $this->getJson($url.'?query=🎵', $headers($read))->assertOk()->assertJsonPath('pagination.total', 1);
    $this->patchJson($url, ['action' => 'delete', 'revision' => 3, 'ids' => ['extra']], $headers($write))->assertOk()->assertJsonPath('revision', 4)->assertJsonPath('pagination.total', 1);
    $this->patchJson($url, ['action' => 'clear', 'revision' => 4], $headers($write))->assertOk()->assertJsonPath('has_captions', false)->assertJsonPath('revision', 5);
    $this->getJson($url.'?revision=2', $headers($read))->assertOk()->assertJsonPath('cues.0.text', "Hola 🎵\nsegunda línea");
});

test('captions pagination rejects extreme pages instead of overflowing slice arithmetic', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $design = app(StudioService::class)->create($user, $brand, ['name' => 'Página extrema', 'document' => captionDocument()]);
    $this->actingAs($user)->getJson('/studio/designs/'.$design->id.'/captions?page=9223372036854775807')->assertUnprocessable();
});

test('captions AV snapshot metadata satisfies the private font renderer contract', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $font = StudioFont::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => 'Propia', 'family' => 'Caption Family', 'status' => 'ready',
        'style' => 'normal', 'weight' => 400, 'format' => 'woff2', 'mime' => 'font/woff2', 'size' => 64, 'sha256' => str_repeat('a', 64), 'source_sha256' => str_repeat('a', 64), 'disk' => 'local', 'path' => 'fixture.woff2']);
    $doc = captionDocument();
    $doc['timeline']['captions']['style'] += ['font_id' => $font->id, 'fontFamily' => $font->family];
    $design = app(StudioService::class)->create($user, $brand, ['name' => 'Fuente AV', 'document' => $doc]);
    $export = DesignExport::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'design_id' => $design->id, 'revision' => 1, 'format' => 'mp4', 'pages' => ['page_1'], 'status' => 'pending']);
    $method = new ReflectionMethod(StudioAvProcess::class, 'metadata');
    $metadata = $method->invoke(app(StudioAvProcess::class), $export, $doc);
    expect($metadata['fonts'][0]['format'] ?? null)->toBe('woff2');
});

test('captions static server supplies private fonts and packages every selected frame', function (int $pageCount) {
    Storage::fake('local');
    Queue::fake();
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $bytes = file_get_contents(base_path('tests/Fixtures/fonts/SpaceGrotesk-Variable.woff2'));
    $path = 'studio-fonts/'.$user->id.'/'.$brand->id.'/caption.woff2';
    Storage::disk('local')->put($path, $bytes);
    $font = StudioFont::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => 'Propia', 'family' => 'Space Grotesk', 'status' => 'ready',
        'style' => 'normal', 'weight' => 400, 'format' => 'woff2', 'mime' => 'font/woff2', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'source_sha256' => hash('sha256', $bytes), 'disk' => 'local', 'path' => $path]);
    $doc = captionDocument();
    $doc['timeline']['captions']['style'] += ['font_id' => $font->id, 'fontFamily' => $font->family];
    if ($pageCount === 2) {
        $doc['pages'][] = [...$doc['pages'][0], 'id' => 'second'];
    }
    $design = app(StudioService::class)->create($user, $brand, ['name' => 'Fuente SVG', 'document' => $doc]);
    $export = app(StudioRenderer::class)->export($user, $design, ['revision' => 1, 'format' => 'svg', 'pages' => array_column($doc['pages'], 'id'), 'frame_times' => [['page_id' => 'page_1', 'time' => 0.75]]]);
    Process::fake(function ($process) use ($font, $pageCount) {
        if (str_ends_with($process->command[2], 'archive.py')) {
            $native = new Symfony\Component\Process\Process($process->command);
            $native->setTimeout(10);
            $native->run();

            return Process::result(output: $native->getOutput(), errorOutput: $native->getErrorOutput(), exitCode: $native->getExitCode());
        }
        $input = json_decode($process->input, true);
        expect($input['fonts'][0]['id'] ?? null)->toBe($font->id);
        expect($input['fonts'][0]['format'] ?? null)->toBe('woff2');
        $files = [];
        for ($i = 1; $i <= $pageCount; $i++) {
            $name = sprintf('page-%03d.svg', $i);
            file_put_contents($process->command[2].DIRECTORY_SEPARATOR.$name, '<svg xmlns="http://www.w3.org/2000/svg"><text>Hola</text></svg>');
            $files[] = ['name' => $name, 'mime' => 'image/svg+xml'];
        }

        return Process::result(output: json_encode(['files' => $files]));
    });
    app(StudioRenderer::class)->render($export->id);
    expect($export->refresh()->status)->toBe('ready', $export->error ?? '');
})->with([1, 2]);

test('captions restricted codec rejects unsupported or malformed plain files', function (string $text, string $format) {
    expect(fn () => app(StudioCaptionCodec::class)->parse($text, $format))->toThrow(ValidationException::class);
})->with([
    ["WEBVTT\n\nSTYLE\n::cue {color:red}\n", 'vtt'], ["WEBVTT\n\n00:00.500 --> 00:01.500 align:end\nx\n", 'vtt'],
    ["1\n00:00:00,500 --> 00:00:01,500\n<b>x</b>\n", 'srt'], ["1\n00:61:00,500 --> 00:62:01,500\nx\n", 'srt'],
    ["1\n00:00:00,500 --> 00:00:01,500\n\xFF\n", 'srt'], [str_repeat('x', 524289), 'srt'],
]);

function reviewCaptionText(): string
{
    return "1\n00:00:00,500 --> 00:00:01,000\nUno\n\n2\n00:00:01,500 --> 00:00:02,000\nDos\n\n3\n00:00:02,500 --> 00:00:03,000\nTres\n";
}
function reviewCaptionDesign(bool $collisions = false): array
{
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $doc = StudioDocument::blank(320, 180);
    $doc['schema_version'] = 2;
    $doc['timeline'] = ['fps' => 30, 'audio_tracks' => [], 'captions' => ['style' => ['fontFamily' => 'Arial'], 'cues' => [['id' => 'keep', 'start' => 0, 'end' => 0.25, 'text' => 'Conservar']]]];
    if ($collisions) {
        $doc['pages'][0]['id'] = 'cue_1';
        $doc['pages'][0]['elements'] = [['id' => 'import_1', 'type' => 'rect', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10], ['id' => 'group', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 20, 'height' => 20, 'elements' => [['id' => 'cue_2', 'type' => 'rect', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10]]]];
        $audio = MediaAsset::factory()->for($user)->create(['kind' => 'audio', 'mime' => 'audio/wav', 'duration' => 2, 'audio_codec' => 'pcm_s16le']);
        StudioAsset::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'media_asset_id' => $audio->id]);
        $doc['timeline']['audio_tracks'] = [['id' => 'cue_3', 'asset_id' => $audio->id, 'start' => 0, 'duration' => 1]];
    }

    return [$user, app(StudioService::class)->create($user, $brand, ['name' => 'Revisión1', 'document' => $doc])];
}
test('A1 default import appends without deleting existing cues', function () {
    [$user,$design] = reviewCaptionDesign();
    $result = app(StudioCaptions::class)->mutate($user, $design, ['action' => 'import', 'revision' => 1, 'format' => 'srt', 'text' => reviewCaptionText()]);
    expect($result['pagination']['total'])->toBe(4)->and(array_column($result['cues'], 'id'))->toContain('keep');
});
test('B1 blank boundary lines reject export but remain valid document text', function (string $format, string $text) {
    $cue = ['id' => 'cue', 'start' => 0.5, 'end' => 1.5, 'text' => $text];
    $before = $cue;
    [$user,$design] = reviewCaptionDesign();
    $document = $design->document;
    $document['timeline']['captions']['cues'] = [$cue];
    $brand = Brand::withoutUserScope()->findOrFail($design->brand_id);
    expect(app(StudioDocument::class)->validate($user, $brand, $document)['timeline']['captions']['cues'][0]['text'])->toBe($text);
    expect(fn () => app(StudioCaptionCodec::class)->encode([$cue], $format))->toThrow(ValidationException::class);
    expect($cue)->toBe($before);
})->with(array_merge(...array_map(fn ($format) => array_map(fn ($text) => [$format, $text], ["\nHola", "Hola\n", " \t\nHola", "Hola\n \t", "Hola\n \t\nMundo"]), ['srt', 'vtt'])));
test('B2 parses complete runs of blank cue separators', function (string $format, int $count, string $newline) {
    $comma = $format === 'srt' ? ',' : '.';
    $header = $format === 'vtt' ? "WEBVTT\n\n" : '';
    $text = $header."1\n00:00:00{$comma}500 --> 00:00:01{$comma}000\nUno".str_repeat("\n", $count)."2\n00:00:01{$comma}500 --> 00:00:02{$comma}000\nDos\n";
    $parsed = app(StudioCaptionCodec::class)->parse(str_replace("\n", $newline, $text), $format);
    expect(array_column($parsed['cues'], 'text'))->toBe(['Uno', 'Dos']);
})->with(array_merge(...array_map(fn ($format) => array_merge(...array_map(fn ($count) => array_map(fn ($newline) => [$format, $count, $newline], ["\n", "\r\n"]), [2, 3, 4, 5])), ['srt', 'vtt'])));
test('B3 imported IDs avoid the global document namespace', function (string $mode) {
    [$user,$design] = reviewCaptionDesign(true);
    $result = app(StudioCaptions::class)->mutate($user, $design, ['action' => 'import', 'revision' => 1, 'format' => 'srt', 'text' => reviewCaptionText(), 'mode' => $mode]);
    $incoming = array_values(array_filter($result['cues'], fn ($cue) => $cue['text'] !== 'Conservar'));
    expect($incoming)->toHaveCount(3);
    foreach ($incoming as $cue) {
        expect(in_array($cue['id'], ['cue_1', 'cue_2', 'cue_3', 'import_1', 'group'], true))->toBeFalse();
    }
})->with(['append', 'replace']);

test('review import web and MCP default to append and replace only when explicitly requested', function () {
    [$user,$design] = reviewCaptionDesign(true);
    $this->actingAs($user);
    $url = '/studio/designs/'.$design->id.'/captions';
    $this->patchJson($url, ['action' => 'import', 'revision' => 1, 'format' => 'srt', 'text' => reviewCaptionText()])->assertOk()->assertJsonPath('pagination.total', 4)->assertJsonPath('revision', 2);
    [$error,$saved] = $this->callTool($this->mcpKey($user, ['designs:write']), 'manage_design_captions', ['action' => 'import', 'design_id' => $design->id, 'revision' => 2, 'format' => 'srt', 'text' => reviewCaptionText()]);
    expect($error)->toBeFalse()->and($saved['pagination']['total'])->toBe(7)->and(array_column($saved['cues'], 'id'))->toContain('keep');
    [$error,$replaced] = $this->callTool($this->mcpKey($user, ['designs:write']), 'manage_design_captions', ['action' => 'import', 'design_id' => $design->id, 'revision' => 3, 'format' => 'srt', 'text' => reviewCaptionText(), 'mode' => 'replace']);
    expect($error)->toBeFalse()->and($replaced['pagination']['total'])->toBe(3)->and(array_column($replaced['cues'], 'id'))->not->toContain('keep');
    expect($design->refresh()->document['pages'][0]['id'])->toBe('cue_1');
});

test('review explicit typed IDs remain strict and do not modify the saved revision', function (string $action) {
    [$user,$design] = reviewCaptionDesign(true);
    $cue = ['id' => 'cue_1', 'start' => 0.5, 'end' => 1, 'text' => 'Explícito'];
    $payload = ['action' => $action, 'revision' => 1] + ($action === 'upsert' ? ['cue' => $cue] : ['captions' => ['style' => [], 'cues' => [$cue]]]);
    expect(fn () => app(StudioCaptions::class)->mutate($user, $design, $payload))->toThrow(ValidationException::class);
    expect($design->refresh()->revision)->toBe(1);
})->with(['upsert', 'replace']);
