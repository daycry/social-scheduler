<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\Design;
use App\Models\Studio\DesignRevision;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioDocument;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\McpClient;

uses(McpClient::class);

function automationDocument(string $text = 'Hola {{nombre}}'): array
{
    $document = StudioDocument::blank(400, 400);
    $document['pages'][0]['background'] = '#ffffff';
    $document['pages'][0]['elements'] = [
        ['id' => 'title', 'type' => 'text', 'x' => 40, 'y' => 80, 'width' => 300, 'height' => 100,
            'text' => $text, 'fill' => '#7c3aed', 'fontFamily' => 'Arial', 'fontSize' => 36],
    ];

    return $document;
}

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user);
    $this->source = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Original', 'document' => automationDocument()]);
    $this->url = '/studio/designs/'.$this->source->id;
});

test('Studio automation: saving a template makes an independent editable copy with discoverable fields', function () {
    $template = $this->postJson($this->url.'/template', ['revision' => 1, 'name' => 'Mi plantilla'])
        ->assertCreated()->assertJsonPath('design.is_template', true)->assertJsonPath('design.template_fields', ['nombre'])->json('design');
    expect($template['id'])->not->toBe($this->source->id)
        ->and($template['document']['pages'][0]['id'])->not->toBe('page_1')
        ->and($template['document']['pages'][0]['elements'][0]['id'])->not->toBe('title');
    $template['document']['pages'][0]['elements'][0]['text'] = 'Oferta {{producto}}';
    $this->patchJson('/studio/designs/'.$template['id'], ['revision' => 1, 'name' => 'Mi plantilla', 'document' => $template['document']])
        ->assertOk()->assertJsonPath('design.template_fields', ['producto']);
    $this->getJson('/brands/'.$this->brand->id.'/studio/designs?templates=1')->assertOk()->assertJsonCount(1, 'designs.designs');
    expect($this->source->fresh()->document)->toBe(automationDocument());
    $this->deleteJson('/studio/designs/'.$template['id'])->assertOk();
    $this->getJson('/brands/'.$this->brand->id.'/studio/designs?templates=1')->assertJsonCount(0, 'designs.designs');
});

test('Studio automation: instantiation substitutes fields literally and preserves template revision', function () {
    $template = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Plantilla', 'document' => automationDocument('{{nombre}} / {{nombre}} / {{otra}}'), 'is_template' => true]);
    $result = $this->postJson('/studio/designs/'.$template->id.'/instantiate', ['revision' => 1, 'name' => 'Ejemplar',
        'fields' => ['nombre' => '  <b>Árbol</b> {{otra}}  ', 'otra' => '']])
        ->assertCreated()->assertJsonPath('design.is_template', false)->assertJsonPath('design.revision', 1)->json('design');
    expect($result['document']['pages'][0]['elements'][0]['text'])->toBe('  <b>Árbol</b> {{otra}}   /   <b>Árbol</b> {{otra}}   / ')
        ->and($template->fresh()->revision)->toBe(1)
        ->and($template->fresh()->document['pages'][0]['elements'][0]['text'])->toBe('{{nombre}} / {{nombre}} / {{otra}}');
    $this->getJson('/studio/designs/'.$result['id'])->assertJsonPath('design.document', $result['document']);
});

test('Studio automation: resize scales root transforms uniformly and preserves nested geometry', function () {
    $document = automationDocument('Contenido real');
    $document['pages'][0]['elements'][0] = ['id' => 'group', 'type' => 'group', 'x' => 50, 'y' => 70, 'width' => 200, 'height' => 150,
        'scaleX' => 2, 'scaleY' => 1, 'rotation' => 35, 'elements' => $document['pages'][0]['elements']];
    $this->source = app(StudioService::class)->update($this->user, $this->source, ['revision' => 1, 'name' => 'Con grupo', 'document' => $document]);
    $result = $this->postJson($this->url.'/resize', ['revision' => 2, 'width' => 800, 'height' => 1200, 'mode' => 'contain'])
        ->assertOk()->assertJsonPath('design.revision', 3)->json('design.document');
    expect($result['width'])->toBe(800)->and($result['height'])->toBe(1200)
        ->and($result['pages'][0]['elements'][0]['x'])->toEqual(100)
        ->and($result['pages'][0]['elements'][0]['y'])->toEqual(340)
        ->and($result['pages'][0]['elements'][0]['scaleX'])->toEqual(4)
        ->and($result['pages'][0]['elements'][0]['scaleY'])->toEqual(2)
        ->and($result['pages'][0]['elements'][0]['rotation'])->toBe(35)
        ->and($result['pages'][0]['elements'][0]['elements'])->toBe($document['pages'][0]['elements'][0]['elements']);
    $this->postJson($this->url.'/resize', ['revision' => 3, 'width' => 900, 'height' => 900, 'mode' => 'canvas'])
        ->assertOk()->assertJsonPath('design.document.pages.0.elements', $result['pages'][0]['elements']);
    $this->postJson($this->url.'/resize', ['revision' => 2, 'width' => 200, 'height' => 200])->assertConflict();
});

test('Studio automation: brand kit remaps gradient and text slots from saved reference', function () {
    $document = automationDocument('Marca');
    $document['pages'][0]['background'] = ['type' => 'linear', 'angle' => 45, 'stops' => [['offset' => 0, 'color' => '#111827'], ['offset' => 1, 'color' => '#ffffff']]];
    app(StudioService::class)->update($this->user, $this->source, ['revision' => 1, 'name' => 'Kit', 'document' => $document]);
    app(StudioService::class)->updateKit($this->user, $this->brand, ['palette' => ['#ff0000', '#00ff00', '#0000ff'], 'fonts' => ['Georgia']]);
    $result = $this->postJson($this->url.'/apply-kit', ['revision' => 2])->assertOk()->assertJsonPath('design.revision', 3)->json('design');
    expect($result['document']['pages'][0]['background']['stops'][0]['color'])->toBe('#00ff00')
        ->and($result['document']['pages'][0]['background']['stops'][1]['color'])->toBe('#0000ff')
        ->and($result['document']['pages'][0]['elements'][0]['fill'])->toBe('#ff0000')
        ->and($result['document']['pages'][0]['elements'][0]['fontFamily'])->toBe('Georgia')
        ->and($result['kit_reference']['palette'])->toBe(['#ff0000', '#00ff00', '#0000ff']);
    $this->postJson($this->url.'/apply-kit', ['revision' => 2])->assertConflict();
});

test('Studio automation: variants are independent validated copies and invalid batches do not write', function () {
    $response = $this->postJson($this->url.'/variants', ['revision' => 1, 'fields' => ['nombre' => 'Árbol'], 'variants' => [
        ['name' => 'Cuadrada', 'width' => 800, 'height' => 800], ['name' => 'Vertical', 'width' => 600, 'height' => 1200, 'mode' => 'cover'],
    ]])->assertCreated()->assertJsonCount(2, 'designs');
    $copies = $response->json('designs');
    expect(array_unique(array_column($copies, 'id')))->toHaveCount(2)
        ->and($copies[0]['document']['pages'][0]['id'])->not->toBe($copies[1]['document']['pages'][0]['id'])
        ->and($copies[0]['document']['pages'][0]['elements'][0]['text'])->toBe('Hola Árbol')
        ->and($this->source->fresh()->document)->toBe(automationDocument());
    $before = Design::withoutUserScope()->count();
    $this->postJson($this->url.'/variants', ['revision' => 1, 'fields' => ['nombre' => 'Hola'], 'variants' => [
        ['width' => 800, 'height' => 800], ['width' => 1, 'height' => 800],
    ]])->assertUnprocessable();
    expect(Design::withoutUserScope()->count())->toBe($before)
        ->and(DesignRevision::withoutUserScope()->where('design_id', $this->source->id)->count())->toBe(1);
});

test('Studio automation MCP: template and instantiate share fields and whitespace semantics', function () {
    $key = $this->mcpKey($this->user, ['designs:read', 'designs:write']);
    [$error, $result] = $this->callTool($key, 'manage_design', ['action' => 'template', 'design_id' => $this->source->id, 'revision' => 1, 'name' => 'Plantilla MCP']);
    expect($error)->toBeFalse()->and($result['design']['template_fields'])->toBe(['nombre']);
    [$error, $instance] = $this->callTool($key, 'manage_design', ['action' => 'instantiate', 'design_id' => $result['design']['id'], 'revision' => 1, 'fields' => ['nombre' => '  ']]);
    expect($error)->toBeFalse()->and($instance['design']['document']['pages'][0]['elements'][0]['text'])->toBe('Hola   ');
    [$error, $empty] = $this->callTool($key, 'manage_design', ['action' => 'instantiate', 'design_id' => $result['design']['id'], 'revision' => 1, 'fields' => ['nombre' => '']]);
    expect($error)->toBeFalse()->and($empty['design']['document']['pages'][0]['elements'][0]['text'])->toBe('Hola ');
    $readKey = $this->mcpKey($this->user, ['designs:read']);
    [$error, $message] = $this->callTool($readKey, 'manage_design', ['action' => 'template', 'design_id' => $this->source->id, 'revision' => 1]);
    expect($error)->toBeTrue()->and($message)->toContain('designs:write');
});

test('Studio automation API: all mutations require write abilities and preserve ownership', function () {
    app('auth')->forgetGuards();
    $reader = $this->mcpKey($this->user, ['designs:read']);
    foreach (['template', 'instantiate', 'resize', 'variants', 'apply-kit'] as $action) {
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/designs/'.$this->source->id.'/'.$action, ['revision' => 1], ['Authorization' => 'Bearer '.$reader])->assertForbidden();
    }
    $writer = $this->mcpKey($this->user, ['designs:write']);
    app('auth')->forgetGuards();
    $template = $this->postJson('/api/v1/designs/'.$this->source->id.'/template', ['revision' => 1], ['Authorization' => 'Bearer '.$writer])->assertCreated()->json('design');
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/designs/'.$template['id'].'/instantiate', ['revision' => 1, 'fields' => ['nombre' => 'API']], ['Authorization' => 'Bearer '.$writer])
        ->assertCreated()->assertJsonPath('design.document.pages.0.elements.0.text', 'Hola API');
    $foreign = $this->mcpKey(User::factory()->create(), ['*']);
    foreach (['template', 'instantiate', 'resize', 'variants', 'apply-kit'] as $action) {
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/designs/'.$this->source->id.'/'.$action, ['revision' => 1], ['Authorization' => 'Bearer '.$foreign])->assertNotFound();
    }
});

test('Studio automation: fields and invalid geometry never leave partial copies', function () {
    $before = Design::withoutUserScope()->count();
    foreach ([[], ['nombre' => 'Hola', 'extra' => 'No'], ['nombre' => 123], ['nombre' => str_repeat('á', 10001)]] as $fields) {
        $this->postJson($this->url.'/variants', ['revision' => 1, 'fields' => $fields, 'variants' => [['width' => 500, 'height' => 500]]])->assertUnprocessable();
    }
    $this->postJson($this->url.'/variants', ['revision' => 1, 'fields' => ['nombre' => 'Hola'], 'variants' => array_fill(0, 13, ['width' => 500, 'height' => 500])])->assertUnprocessable();
    $this->postJson($this->url.'/variants', ['revision' => 1, 'fields' => ['nombre' => 'Hola'], 'variants' => [['width' => 500, 'height' => 500], ['width' => 500, 'height' => 500, 'evil' => true]]])->assertUnprocessable();
    $this->postJson($this->url.'/instantiate', ['revision' => 1, 'fields' => ['nombre' => 'Hola']])->assertUnprocessable();
    $this->postJson($this->url.'/resize', ['revision' => 1, 'width' => 500, 'height' => 500, 'arbitrary' => true])->assertUnprocessable();
    expect(Design::withoutUserScope()->count())->toBe($before)->and($this->source->fresh()->revision)->toBe(1);
});

test('Studio automation: explicit empty kits and overflowing resized transforms are rejected without mutation', function () {
    app(StudioService::class)->updateKit($this->user, $this->brand, ['palette' => [], 'fonts' => [], 'logos' => []]);
    $this->postJson($this->url.'/apply-kit', ['revision' => 1])->assertUnprocessable();
    $document = automationDocument('Grande');
    $document['pages'][0]['elements'][0]['scaleX'] = 100;
    app(StudioService::class)->update($this->user, $this->source, ['revision' => 1, 'name' => 'Grande', 'document' => $document]);
    $this->postJson($this->url.'/resize', ['revision' => 2, 'width' => 8000, 'height' => 8000])->assertUnprocessable();
    expect($this->source->fresh()->revision)->toBe(2)->and($this->source->fresh()->document)->toBe($document);
});

test('Studio automation: ordinary duplicates preserve kit slot references across later kit changes', function () {
    app(StudioService::class)->updateKit($this->user, $this->brand, ['palette' => ['#ff0000', '#00ff00', '#0000ff']]);
    $copy = app(StudioService::class)->duplicate($this->user, $this->source);
    $result = $this->postJson('/studio/designs/'.$copy->id.'/apply-kit', ['revision' => 1])->assertOk()->json('design');
    expect($result['document']['pages'][0]['elements'][0]['fill'])->toBe('#ff0000');
});

test('Studio automation: only logo slots change assets while preserving their visual box', function () {
    $old = MediaAsset::factory()->for($this->user)->create(['kind' => 'image', 'status' => 'listo', 'width' => 100, 'height' => 100]);
    $new = MediaAsset::factory()->for($this->user)->create(['kind' => 'image', 'status' => 'listo', 'width' => 200, 'height' => 50]);
    $photo = MediaAsset::factory()->for($this->user)->create(['kind' => 'image', 'status' => 'listo', 'width' => 100, 'height' => 100]);
    foreach ([$old, $new, $photo] as $asset) {
        app(StudioAssets::class)->attach($this->user, $this->brand, $asset->id);
    }
    app(StudioService::class)->updateKit($this->user, $this->brand, ['logos' => [$old->id]]);
    $document = StudioDocument::blank(400, 400);
    $document['pages'][0]['elements'] = [
        ['id' => 'logo', 'type' => 'image', 'x' => 10, 'y' => 20, 'width' => 100, 'height' => 100, 'asset_id' => $old->id],
        ['id' => 'photo', 'type' => 'image', 'x' => 110, 'y' => 20, 'width' => 100, 'height' => 100, 'asset_id' => $photo->id],
    ];
    $source = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Logo y foto', 'document' => $document]);
    app(StudioService::class)->updateKit($this->user, $this->brand, ['logos' => [$new->id]]);
    $result = $this->postJson('/studio/designs/'.$source->id.'/apply-kit', ['revision' => 1])->assertOk()->json('design.document.pages.0.elements');
    expect($result[0]['asset_id'])->toBe($new->id)->and($result[0]['width'] * $result[0]['scaleX'])->toEqual(100)
        ->and($result[0]['height'] * $result[0]['scaleY'])->toEqual(100)->and($result[1])->toBe($document['pages'][0]['elements'][1]);
});

test('Studio automation: nested fields are sorted and replacement expansion stays within document limits', function () {
    $document = automationDocument('{{zeta}} {{alfa}}');
    $document['pages'][0]['elements'][0] = ['id' => 'group', 'type' => 'group', 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 100, 'elements' => $document['pages'][0]['elements']];
    $source = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Anidada', 'document' => $document, 'is_template' => true]);
    $this->getJson('/studio/designs/'.$source->id)->assertJsonPath('design.template_fields', ['alfa', 'zeta']);
    $before = Design::withoutUserScope()->count();
    $this->postJson('/studio/designs/'.$source->id.'/instantiate', ['revision' => 1, 'fields' => ['zeta' => str_repeat('á', 6000), 'alfa' => str_repeat('Á', 6000)]])->assertUnprocessable();
    expect(Design::withoutUserScope()->count())->toBe($before);
});

test('Studio automation: aggregate variant payload budget is enforced before any copies persist', function () {
    $document = automationDocument(str_repeat('á', 10000));
    for ($i = 1; $i < 50; $i++) {
        $element = $document['pages'][0]['elements'][0];
        $element['id'] = 'text_'.$i;
        $document['pages'][0]['elements'][] = $element;
    }
    $source = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Documento grande', 'document' => $document]);
    $before = Design::withoutUserScope()->count();
    $this->postJson('/studio/designs/'.$source->id.'/variants', ['revision' => 1, 'variants' => array_fill(0, 12, ['width' => 400, 'height' => 400])])->assertUnprocessable();
    expect(Design::withoutUserScope()->count())->toBe($before);
});

test('Studio automation review: legacy copies expose an object-shaped empty kit reference', function () {
    $this->source->forceFill(['kit_reference' => null])->save();
    foreach (['template' => ['revision' => 1], 'variants' => ['revision' => 1, 'fields' => ['nombre' => 'Hola'], 'variants' => [['width' => 400, 'height' => 400]]]] as $operation => $data) {
        $result = $this->postJson($this->url.'/'.$operation, $data)->assertCreated();
        $copy = $operation === 'template' ? $result->json('design') : $result->json('designs.0');
        expect($copy['kit_reference'])->toBe(['palette' => [], 'fonts' => [], 'logos' => []]);
    }
    $duplicate = app(StudioService::class)->duplicate($this->user, $this->source);
    expect($duplicate->kit_reference)->toBe(['palette' => [], 'fonts' => [], 'logos' => []]);
});

test('Studio automation review: undo restores the historical kit reference before applying the next kit', function () {
    $original = $this->getJson($this->url)->assertOk()->json('design');
    app(StudioService::class)->updateKit($this->user, $this->brand, ['palette' => ['#222222', '#111827', '#ffffff']]);
    $this->postJson($this->url.'/apply-kit', ['revision' => 1])->assertOk()->assertJsonPath('design.document.pages.0.elements.0.fill', '#222222');
    $this->patchJson($this->url, ['revision' => 2, 'name' => $original['name'], 'document' => $original['document'], 'kit_revision' => 1])
        ->assertOk()->assertJsonPath('design.kit_reference.palette.0', '#7c3aed');
    app(StudioService::class)->updateKit($this->user, $this->brand, ['palette' => ['#333333', '#111827', '#ffffff']]);
    $this->postJson($this->url.'/apply-kit', ['revision' => 3])->assertOk()->assertJsonPath('design.document.pages.0.elements.0.fill', '#333333');
    $this->patchJson($this->url, ['revision' => 4, 'name' => $original['name'], 'document' => $original['document'], 'kit_revision' => 99999])->assertUnprocessable();
    expect($this->source->fresh()->revision)->toBe(4);
});

test('Studio automation review: fallback colors preserve the first canonical slot for equivalent hexadecimal colors', function () {
    $document = automationDocument('Marca');
    $document['pages'][0]['elements'][0]['fill'] = ['type' => 'linear', 'angle' => 0, 'stops' => [['offset' => 0, 'color' => '#abc'], ['offset' => 1, 'color' => '#aabbcc']]];
    $this->source->forceFill(['document' => $document, 'kit_reference' => null])->save();
    app(StudioService::class)->updateKit($this->user, $this->brand, ['palette' => ['#000000', '#112233', '#445566']]);
    $this->postJson($this->url.'/apply-kit', ['revision' => 1])->assertOk()
        ->assertJsonPath('design.document.pages.0.elements.0.fill.stops.0.color', '#112233')
        ->assertJsonPath('design.document.pages.0.elements.0.fill.stops.1.color', '#112233');
});

test('Studio automation review: substitution rejects excessive expansion before exhausting bounded process memory', function () {
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M', base_path('tests/Fixtures/studio-substitution-probe.php')], base_path());
    $process->setTimeout(20);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['rejected'])->toBeTrue();
});

test('Studio automation review: restoring kit references has the same API and MCP behavior', function () {
    app(StudioService::class)->updateKit($this->user, $this->brand, ['palette' => ['#222222', '#111827', '#ffffff']]);
    $this->postJson($this->url.'/apply-kit', ['revision' => 1])->assertOk();
    $writer = $this->mcpKey($this->user, ['designs:read', 'designs:write']);
    [$error, $result] = $this->callTool($writer, 'update_design', ['design_id' => $this->source->id, 'revision' => 2,
        'name' => 'Deshecho MCP', 'document' => automationDocument(), 'kit_revision' => 1]);
    expect($error)->toBeFalse()->and($result['kit_reference']['palette'][0])->toBe('#7c3aed');
    app('auth')->forgetGuards();
    $this->patchJson('/api/v1/designs/'.$this->source->id, ['revision' => 3, 'name' => 'Rehecho API',
        'document' => $this->source->fresh()->document, 'kit_revision' => 2], ['Authorization' => 'Bearer '.$writer])
        ->assertOk()->assertJsonPath('kit_reference.palette.0', '#222222');
});

test('Studio automation review: expansion preflight preserves Unicode and budgets JSON escaping globally', function () {
    $template = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Unicode', 'document' => automationDocument('{{nombre}}'), 'is_template' => true]);
    $value = str_repeat("\u{2028}", 10000);
    $this->postJson('/studio/designs/'.$template->id.'/instantiate', ['revision' => 1, 'fields' => ['nombre' => $value]])
        ->assertCreated()->assertJsonPath('design.document.pages.0.elements.0.text', $value);

    $document = automationDocument('{{nombre}}');
    for ($i = 1; $i < 225; $i++) {
        $element = $document['pages'][0]['elements'][0];
        $element['id'] = 'text_'.$i;
        $document['pages'][0]['elements'][] = $element;
    }
    $source = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Escapado', 'document' => $document, 'is_template' => true]);
    $before = Design::withoutUserScope()->count();
    $this->postJson('/studio/designs/'.$source->id.'/instantiate', ['revision' => 1, 'fields' => ['nombre' => str_repeat("\0", 2000)]])->assertUnprocessable();
    expect(Design::withoutUserScope()->count())->toBe($before);
});
