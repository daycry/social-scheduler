<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignRevision;
use App\Models\User;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function studioDocument(array $elements = []): array
{
    return ['schema_version' => 1, 'width' => 1080, 'height' => 1080, 'pages' => [
        ['id' => 'page_1', 'name' => 'Primera', 'background' => '#ffffff', 'duration' => 5, 'elements' => $elements],
    ]];
}

function studioText(string $id = 'title'): array
{
    return ['id' => $id, 'type' => 'text', 'x' => 20, 'y' => 30, 'width' => 600, 'height' => 100, 'text' => 'Hola, marca', 'fill' => '#111827', 'fontFamily' => 'Arial', 'fontSize' => 64];
}

beforeEach(function () {
    if (! Route::has('studio.index') && is_file(base_path('routes/studio.php'))) {
        Route::middleware('web')->group(base_path('routes/studio.php'));
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->otherBrand = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user);
    $this->createUrl = '/brands/'.$this->brand->id.'/studio/designs';
});

test('Studio: creates and reloads a native document with its first revision', function () {
    $document = studioDocument([studioText()]);
    $response = $this->postJson($this->createUrl, ['name' => 'Campaña de marca', 'document' => $document]);
    $response->assertCreated()->assertJsonPath('design.name', 'Campaña de marca')->assertJsonPath('design.revision', 1);
    $id = $response->json('design.id');
    $this->getJson('/studio/designs/'.$id)->assertOk()->assertJsonPath('design.document', $document);
    expect(DesignRevision::withoutUserScope()->where('design_id', $id)->count())->toBe(1);
});

test('Studio: saves a revision atomically and rejects a stale writer without overwriting', function () {
    $created = $this->postJson($this->createUrl, ['name' => 'Inicial', 'document' => studioDocument()])->json('design');
    $changed = studioDocument([studioText()]);
    $url = '/studio/designs/'.$created['id'];
    $this->patchJson($url, ['name' => 'Guardado', 'document' => $changed, 'revision' => 1])->assertOk()->assertJsonPath('design.revision', 2);
    $this->patchJson($url, ['name' => 'Viejo', 'document' => studioDocument(), 'revision' => 1])->assertStatus(409);
    $this->getJson($url)->assertJsonPath('design.name', 'Guardado')->assertJsonPath('design.document', $changed);
    expect(DesignRevision::withoutUserScope()->where('design_id', $created['id'])->pluck('revision')->all())->toBe([1, 2]);
});

test('Studio: document JSON preserves empty text and an absent stroke', function () {
    $element = studioText();
    $element['text'] = '';
    $element['stroke'] = '';
    $document = studioDocument([$element]);
    $this->postJson($this->createUrl, ['name' => 'Vacíos válidos', 'document' => $document])->assertCreated()->assertJsonPath('design.document', $document);
});

test('Studio: document JSON preserves leading and trailing typographic whitespace', function () {
    $element = studioText();
    $element['text'] = "  Hola,\nmarca  ";
    $document = studioDocument([$element]);
    $this->postJson($this->createUrl, ['name' => 'Espacios tipográficos', 'document' => $document])->assertCreated()->assertJsonPath('design.document', $document);
});

test('Studio: searches literal percent and underscore characters and returns lightweight brand summaries', function () {
    foreach (['Oferta 100%', 'Oferta 1000', 'Oferta_azul'] as $name) {
        $this->postJson($this->createUrl, ['name' => $name, 'document' => studioDocument()])->assertCreated();
    }
    $this->postJson('/brands/'.$this->otherBrand->id.'/studio/designs', ['name' => 'Oferta 100%', 'document' => studioDocument()])->assertCreated();
    $response = $this->getJson($this->createUrl.'?query='.urlencode('100%'))->assertOk()->assertJsonCount(1, 'designs.designs');
    expect($response->json('designs.designs.0'))->not->toHaveKey('document')
        ->and($response->json('designs.designs.0.width'))->toBe(1080)
        ->and($response->json('designs.designs.0.page_count'))->toBe(1);
    $this->getJson($this->createUrl.'?query='.urlencode('_'))->assertJsonCount(1, 'designs.designs');
});

test('Studio: an update can explicitly remove its folder association', function () {
    $folder = $this->postJson('/brands/'.$this->brand->id.'/studio/folders', ['name' => 'Carpeta'])->json('folder');
    $design = $this->postJson($this->createUrl, ['name' => 'Con carpeta', 'document' => studioDocument(), 'folder_id' => $folder['id']])->json('design');
    $this->patchJson('/studio/designs/'.$design['id'], ['name' => 'Sin carpeta', 'document' => studioDocument(), 'revision' => 1, 'folder_id' => null])
        ->assertOk()->assertJsonPath('design.folder_id', null);
});

test('Studio: private brands and designs are not visible or mutable to another user', function () {
    $created = $this->postJson($this->createUrl, ['name' => 'Privado', 'document' => studioDocument()])->json('design');
    $this->actingAs(User::factory()->create());
    $this->getJson('/brands/'.$this->brand->id.'/studio/designs')->assertNotFound();
    $this->postJson($this->createUrl, ['name' => 'Ataque', 'document' => studioDocument()])->assertNotFound();
    $this->getJson('/studio/designs/'.$created['id'])->assertNotFound();
    $this->patchJson('/studio/designs/'.$created['id'], ['name' => 'Ataque', 'document' => studioDocument(), 'revision' => 1])->assertNotFound();
    $this->deleteJson('/studio/designs/'.$created['id'])->assertNotFound();
});

test('Studio: unsupported or executable document values cannot be persisted', function (string $case) {
    $document = studioDocument([studioText()]);
    match ($case) {
        'version' => $document['schema_version'] = 9,
        'duplicate ids' => $document['pages'][0]['elements'][] = studioText(),
        'external image' => $document['pages'][0]['elements'] = [['id' => 'image', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'src' => 'https://outside.invalid/tracker.png']],
        'unknown class' => $document['pages'][0]['elements'][0]['type'] = 'FabricExecutable',
        'geometry' => $document['pages'][0]['elements'][0]['x'] = 900000,
        'font css' => $document['pages'][0]['elements'][0]['fontFamily'] = 'url(https://outside.invalid/font)',
        'gradient url' => $document['pages'][0]['background'] = 'url(https://outside.invalid/image)',
        'unknown field' => $document['pages'][0]['elements'][0]['onSelect'] = 'evil()',
    };
    $this->postJson($this->createUrl, ['name' => 'Inválido', 'document' => $document])->assertUnprocessable();
    expect(Design::withoutUserScope()->count())->toBe(0);
})->with(['version', 'duplicate ids', 'external image', 'unknown class', 'geometry', 'font css', 'gradient url', 'unknown field']);

test('Studio: an asset must be ready, owned, and registered in the same brand', function () {
    $asset = MediaAsset::factory()->for($this->user)->create();
    $image = ['id' => 'image', 'type' => 'image', 'asset_id' => $asset->id, 'x' => 0, 'y' => 0, 'width' => 1080, 'height' => 1080];
    $payload = ['name' => 'Imagen', 'document' => studioDocument([$image])];
    $this->postJson($this->createUrl, $payload)->assertUnprocessable();
    $this->postJson('/brands/'.$this->otherBrand->id.'/studio/assets', ['media_id' => $asset->id])->assertCreated();
    $this->postJson($this->createUrl, $payload)->assertUnprocessable();
    $this->postJson('/brands/'.$this->brand->id.'/studio/assets', ['media_id' => $asset->id])->assertCreated();
    $this->postJson($this->createUrl, $payload)->assertCreated();
    $foreign = MediaAsset::factory()->create();
    $this->postJson('/brands/'.$this->brand->id.'/studio/assets', ['media_id' => $foreign->id])->assertNotFound();
    $pending = MediaAsset::factory()->for($this->user)->create(['status' => 'pendiente']);
    $this->postJson('/brands/'.$this->brand->id.'/studio/assets', ['media_id' => $pending->id])->assertUnprocessable();
});

test('Studio: optional typed fields reject explicit null values', function (string $field) {
    $element = studioText();
    $element[$field] = null;
    $this->postJson($this->createUrl, ['name' => 'Tipo inválido', 'document' => studioDocument([$element])])->assertUnprocessable();
    expect(Design::withoutUserScope()->count())->toBe(0);
})->with(['fontFamily', 'shadow']);

test('Studio: video default end is nullable and survives create and update without losing trim state', function () {
    $video = MediaAsset::factory()->for($this->user)->video(12)->create();
    $this->postJson('/brands/'.$this->brand->id.'/studio/assets', ['media_id' => $video->id])->assertCreated();
    $element = ['id' => 'clip', 'type' => 'video', 'asset_id' => $video->id, 'x' => 0, 'y' => 0, 'width' => 500, 'height' => 500,
        'trimStart' => 2, 'trimEnd' => null, 'volume' => 1, 'muted' => false];
    $payload = ['name' => 'Clip sin recorte final', 'document' => studioDocument([$element])];
    $id = $this->postJson($this->createUrl, $payload)->assertCreated()->json('design.id');
    $this->patchJson('/studio/designs/'.$id, $payload + ['revision' => 1])->assertOk()->assertJsonPath('design.document.pages.0.elements.0.trimEnd', null);
});

test('Studio: shadow maximum shared with editor accepts blur 500', function () {
    $element = studioText();
    $element['shadow'] = ['color' => '#00000080', 'blur' => 500, 'offsetX' => 2000, 'offsetY' => -2000];
    $this->postJson($this->createUrl, ['name' => 'Sombra', 'document' => studioDocument([$element])])->assertCreated();
});

test('Studio: document size uses canonical UTF-8 bytes like the browser', function (string $character) {
    $elements = [];
    for ($i = 0; $i < 40; $i++) {
        $element = studioText('unicode_'.$i);
        $element['text'] = str_repeat($character, 10000);
        $elements[] = $element;
    }
    $document = studioDocument($elements);
    expect(strlen(json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS)))->toBeLessThan(2 * 1024 * 1024);
    $this->postJson($this->createUrl, ['name' => 'Unicode', 'document' => $document])->assertCreated();
})->with(['CJK' => ['界'], 'line separator' => ["\u{2028}"], 'paragraph separator' => ["\u{2029}"]]);

test('Studio: rejects an empty group before saving a document the editor cannot reopen', function () {
    $group = ['id' => 'empty_group', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'elements' => []];
    $this->postJson($this->createUrl, ['name' => 'Vacío', 'document' => studioDocument([$group])])->assertUnprocessable();
    expect(Design::withoutUserScope()->count())->toBe(0);
});

test('Studio: grouped vector geometry and skew roundtrip without flattening', function () {
    $child = studioText('child');
    $child['skewX'] = 23.5;
    $child['skewY'] = -11;
    $child['shadow'] = ['color' => '#00000080', 'blur' => 8, 'offsetX' => -2, 'offsetY' => 4];
    $document = studioDocument([['id' => 'group', 'type' => 'group', 'x' => 10, 'y' => 20, 'width' => 800, 'height' => 600,
        'rotation' => 35, 'scaleX' => 2, 'scaleY' => 0.8, 'elements' => [$child,
            ['id' => 'vector', 'type' => 'path', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'path' => [['M', 0, 0], ['C', 10, 20, 30, 40, 50, 60], ['Z']],
                'fill' => ['type' => 'linear', 'angle' => 90, 'stops' => [['offset' => 0, 'color' => '#ffffff'], ['offset' => 1, 'color' => '#7c3aed']]]],
        ]]]);
    $id = $this->postJson($this->createUrl, ['name' => 'Grupo', 'document' => $document])->assertCreated()->json('design.id');
    $this->getJson('/studio/designs/'.$id)->assertOk()->assertJsonPath('design.document', $document);
});

test('Studio: asset bytes use an authorized same-origin proxy and keep the media private', function () {
    $asset = MediaAsset::factory()->for($this->user)->create(['mime' => 'image/png']);
    Storage::disk('local')->put($asset->path, 'private-image-fixture');
    $response = $this->postJson('/brands/'.$this->brand->id.'/studio/assets', ['media_id' => $asset->id])->assertCreated();
    $preview = $response->json('asset.preview_url');
    expect($preview)->toContain('/studio/assets/');
    $this->get($preview)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get('/brands/'.$this->otherBrand->id.'/studio/assets/'.$asset->id.'/file')->assertNotFound();
    $this->actingAs(User::factory()->create())->get($preview)->assertNotFound();
});

test('Studio: private asset proxy supports a single bounded range and rejects invalid ranges', function () {
    $asset = MediaAsset::factory()->for($this->user)->create(['mime' => 'image/png']);
    Storage::disk('local')->put($asset->path, 'private-image-fixture');
    $url = $this->postJson('/brands/'.$this->brand->id.'/studio/assets', ['media_id' => $asset->id])->json('asset.preview_url');
    $part = $this->get($url, ['Range' => 'bytes=2-5'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-5/21')->assertHeader('Content-Length', '4');
    expect($part->streamedContent())->toBe('ivat');
    $suffix = $this->get($url, ['Range' => 'bytes=-7'])->assertStatus(206);
    expect($suffix->streamedContent())->toBe('fixture');
    $this->get($url, ['Range' => 'bytes=100-200'])->assertStatus(416)->assertHeader('Content-Range', 'bytes */21');
    $this->get($url, ['Range' => 'bytes=0-1,4-5'])->assertStatus(416);
});

test('Studio: services retain explicit ownership without an authenticated web session', function () {
    Auth::logout();
    $service = app(StudioService::class);
    $design = $service->create($this->user, $this->brand, ['name' => 'Desde MCP', 'document' => studioDocument()]);
    expect($service->present($this->user, $design)['name'])->toBe('Desde MCP');
    expect(fn () => $service->present(User::factory()->create(), $design))->toThrow(NotFoundHttpException::class);
});

test('Studio: kit and folders are scoped to brand and require registered own logos', function () {
    $base = '/brands/'.$this->brand->id.'/studio';
    $folder = $this->postJson($base.'/folders', ['name' => 'Lanzamientos'])->assertCreated()->json('folder');
    $otherFolder = $this->postJson('/brands/'.$this->otherBrand->id.'/studio/folders', ['name' => 'Otra'])->json('folder');
    $this->postJson($this->createUrl, ['name' => 'Mala carpeta', 'document' => studioDocument(), 'folder_id' => $otherFolder['id']])->assertUnprocessable();
    $this->postJson($this->createUrl, ['name' => 'Plantilla', 'document' => studioDocument(), 'folder_id' => $folder['id'], 'is_template' => true])->assertCreated();
    $this->getJson($this->createUrl.'?templates=1&folder_id='.$folder['id'])->assertOk()->assertJsonCount(1, 'designs.designs');
    $logo = MediaAsset::factory()->for($this->user)->create();
    $kit = ['palette' => ['#7c3aed', '#ffffff'], 'fonts' => ['Arial'], 'logos' => [$logo->id], 'voice' => 'Cercana', 'guidelines' => 'Usar marca'];
    $this->putJson($base.'/brand-kit', $kit)->assertUnprocessable();
    $this->postJson($base.'/assets', ['media_id' => $logo->id])->assertCreated();
    $this->putJson($base.'/brand-kit', $kit)->assertOk()->assertJsonPath('kit.palette', $kit['palette']);
    $this->getJson('/brands/'.$this->otherBrand->id.'/studio/brand-kit')->assertOk()->assertJsonPath('kit.logos', []);
});

test('Studio: duplicate and archive are independent and folder deletion preserves documents', function () {
    $folder = $this->postJson('/brands/'.$this->brand->id.'/studio/folders', ['name' => 'Carpeta'])->json('folder');
    $created = $this->postJson($this->createUrl, ['name' => 'Original', 'document' => studioDocument([studioText()]), 'folder_id' => $folder['id']])->json('design');
    $copy = $this->postJson('/studio/designs/'.$created['id'].'/duplicate', ['name' => 'Copia'])->assertCreated()->json('design');
    expect($copy['id'])->not->toBe($created['id']);
    $this->patchJson('/studio/designs/'.$copy['id'], ['name' => 'Cambio', 'document' => studioDocument(), 'revision' => 1])->assertOk();
    $this->getJson('/studio/designs/'.$created['id'])->assertJsonCount(1, 'design.document.pages.0.elements');
    $this->deleteJson('/studio/folders/'.$folder['id'])->assertOk();
    $this->getJson('/studio/designs/'.$created['id'])->assertJsonPath('design.folder_id', null);
    $this->deleteJson('/studio/designs/'.$created['id'])->assertOk();
    $this->getJson('/studio/designs/'.$created['id'])->assertNotFound();
    $this->getJson($this->createUrl.'?archived=1')->assertJsonCount(1, 'designs.designs');
    $this->postJson('/studio/designs/'.$created['id'].'/restore')->assertOk();
    $this->getJson('/studio/designs/'.$created['id'])->assertOk();
});

test('Studio: export records pin revision and page order and create only a draft', function () {
    $created = $this->postJson($this->createUrl, ['name' => 'Exportable', 'document' => studioDocument()])->json('design');
    $asset = MediaAsset::factory()->for($this->user)->create(['mime' => 'image/png']);
    $url = '/studio/designs/'.$created['id'].'/exports';
    $payload = ['revision' => 1, 'format' => 'png', 'pages' => ['page_1'], 'media_ids' => [$asset->id]];
    $export = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('export.status', 'ready')->json('export');
    $this->patchJson('/studio/designs/'.$created['id'], ['name' => 'Nueva versión', 'document' => studioDocument([studioText()]), 'revision' => 1])->assertOk();
    $this->getJson($url)->assertJsonPath('exports.0.revision', 1)->assertJsonPath('exports.0.media_ids', [$asset->id]);
    $draft = $this->postJson('/studio/exports/'.$export['id'].'/post', ['text' => 'Publicación'])->assertCreated()->json('post');
    expect($draft['status'])->toBe('borrador');
    $payload['pages'] = ['missing'];
    $this->postJson($url, $payload)->assertUnprocessable();
    $payload['pages'] = ['page_1'];
    $payload['revision'] = 999;
    $this->postJson($url, $payload)->assertUnprocessable();
});

test('Studio: export registration rejects another owner, wrong format and wrong dimensions', function () {
    $design = $this->postJson($this->createUrl, ['name' => 'Exportable', 'document' => studioDocument()])->json('design');
    $url = '/studio/designs/'.$design['id'].'/exports';
    foreach ([MediaAsset::factory()->create(['mime' => 'image/png']),
        MediaAsset::factory()->for($this->user)->create(['mime' => 'image/jpeg']),
        MediaAsset::factory()->for($this->user)->create(['mime' => 'image/png', 'width' => 500]),
    ] as $asset) {
        $this->postJson($url, ['revision' => 1, 'format' => 'png', 'pages' => ['page_1'], 'media_ids' => [$asset->id]])->assertUnprocessable();
    }
    expect(DesignExport::withoutUserScope()->count())->toBe(0);
});
