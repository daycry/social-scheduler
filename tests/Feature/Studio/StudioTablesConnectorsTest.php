<?php

use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\McpClient;

uses(McpClient::class);

function boardDocument(array $elements): array
{
    return ['schema_version' => 1, 'width' => 3200, 'height' => 1800, 'pages' => [
        ['id' => 'page_1', 'name' => 'Pizarra', 'background' => '#f8fafc', 'duration' => 5, 'elements' => $elements],
    ]];
}

function boardTable(array $table = []): array
{
    return ['id' => 'tabla_1', 'type' => 'table', 'name' => 'Tabla', 'x' => 40, 'y' => 40, 'width' => 900, 'height' => 240,
        'table' => $table + ['rows' => [['Concepto', 'Enero'], ['Ventas', '120']], 'header' => true, 'fontSize' => 28, 'align' => 'left']];
}

function boardConnector(array $patch = []): array
{
    return $patch + ['id' => 'conector_1', 'type' => 'connector', 'name' => 'Conector', 'x' => 100, 'y' => 100, 'width' => 300, 'height' => 120,
        'from' => 'tabla_1', 'to' => null, 'route' => 'elbow', 'arrow' => 'end', 'points' => [[0, 0], [150, 0], [150, 120], [300, 120]],
        'fill' => '#00000000', 'stroke' => '#7c3aed', 'strokeWidth' => 4];
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
    $this->actingAs($this->user);
    $this->createUrl = '/brands/'.$this->brand->id.'/studio/designs';
});

test('Studio: a board with a table and a connector is saved and reopened unchanged', function () {
    $document = boardDocument([boardTable(), boardConnector()]);
    $id = $this->postJson($this->createUrl, ['name' => 'Pizarra de ventas', 'document' => $document])
        ->assertCreated()->json('design.id');
    $this->getJson('/studio/designs/'.$id)->assertOk()->assertJsonPath('design.document', $document);
    $edited = $document;
    $edited['pages'][0]['elements'][0]['table']['rows'][1][1] = '140';
    $this->patchJson('/studio/designs/'.$id, ['name' => 'Pizarra de ventas', 'document' => $edited, 'revision' => 1])
        ->assertOk()->assertJsonPath('design.revision', 2);
    $this->getJson('/studio/designs/'.$id)->assertJsonPath('design.document.pages.0.elements.0.table.rows.1.1', '140');
    expect(Design::withoutUserScope()->count())->toBe(1);
});

test('Studio: rejects malformed tables', function (array $table, string $field) {
    $this->postJson($this->createUrl, ['name' => 'Mala', 'document' => boardDocument([boardTable($table)])])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'rows irregulares' => [['rows' => [['a', 'b'], ['c']]], 'document.pages.0.elements.0.table.rows.1'],
    'celda no texto' => [['rows' => [['a', 3]]], 'document.pages.0.elements.0.table.rows.0.1'],
    'celda larga' => [['rows' => [[str_repeat('x', 501)]]], 'document.pages.0.elements.0.table.rows.0.0'],
    'sin filas' => [['rows' => []], 'document.pages.0.elements.0.table.rows'],
    'demasiadas columnas' => [['rows' => [array_fill(0, 13, 'x')]], 'document.pages.0.elements.0.table.rows'],
    'color inválido' => [['borderColor' => 'rojo'], 'document.pages.0.elements.0.table.borderColor'],
    'alineación' => [['align' => 'justify'], 'document.pages.0.elements.0.table.align'],
    'clave desconocida' => [['css' => 'x'], 'document.pages.0.elements.0.table'],
]);

test('Studio: rejects malformed connectors', function (array $patch, string $field) {
    $this->postJson($this->createUrl, ['name' => 'Mala', 'document' => boardDocument([boardTable(), boardConnector($patch)])])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'ruta' => [['route' => 'curved'], 'document.pages.0.elements.1.route'],
    'flecha' => [['arrow' => 'start'], 'document.pages.0.elements.1.arrow'],
    'un punto' => [['points' => [[0, 0]]], 'document.pages.0.elements.1.points'],
    'punto no numérico' => [['points' => [[0, 'a'], [1, 1]]], 'document.pages.0.elements.1.points.0'],
    'extremo con css' => [['from' => 'a b;'], 'document.pages.0.elements.1.from'],
    'clave desconocida' => [['label' => 'x'], 'document.pages.0.elements.1'],
]);

test('Studio API: the same board document is accepted and returned through the bearer API', function () {
    if (! Route::has('api.studio.designs.index') && is_file(base_path('routes/studio-api.php'))) {
        Route::middleware('api')->group(base_path('routes/studio-api.php'));
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
    $key = $this->mcpKey($this->user, ['designs:read', 'designs:write']);
    $headers = ['Authorization' => 'Bearer '.$key];
    $document = boardDocument([boardTable(), boardConnector()]);
    $created = $this->postJson('/api/v1/brands/'.$this->brand->id.'/designs', ['name' => 'Pizarra API', 'document' => $document], $headers)->assertCreated()->json();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/designs/'.$created['id'], $headers)->assertOk()->assertJsonPath('document', $document);
    app('auth')->forgetGuards();
    $bad = $document;
    $bad['pages'][0]['elements'][1]['route'] = 'curved';
    $this->postJson('/api/v1/brands/'.$this->brand->id.'/designs', ['name' => 'Mala', 'document' => $bad], $headers)->assertUnprocessable();
});

function linkedBoard(): array
{
    $shape = ['id' => 'forma_1', 'type' => 'rect', 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 60, 'fill' => '#ffffff'];

    return boardDocument([$shape, boardTable(), boardConnector(['from' => 'forma_1', 'to' => 'tabla_1'])]);
}

function linkedEnds(array $document): array
{
    $elements = $document['pages'][0]['elements'];

    return [$elements, $elements[2]['from'], $elements[2]['to']];
}

test('Studio automation: template, instantiate and variants relink connectors to the new ids', function () {
    $source = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Pizarra', 'document' => linkedBoard()]);
    $check = function (array $design) {
        [$elements, $from, $to] = linkedEnds($design['document']);
        expect($from)->toBe($elements[0]['id'])->and($to)->toBe($elements[1]['id'])
            ->and($elements[0]['id'])->not->toBe('forma_1');
    };
    $url = '/studio/designs/'.$source->id;
    $template = $this->postJson($url.'/template', ['revision' => 1])->assertCreated()->json('design');
    $check($template);
    $check($this->postJson('/studio/designs/'.$template['id'].'/instantiate', ['revision' => 1])->assertCreated()->json('design'));
    foreach ($this->postJson($url.'/variants', ['revision' => 1, 'variants' => [['width' => 1600, 'height' => 900], ['width' => 800, 'height' => 800]]])->assertCreated()->json('designs') as $variant) {
        $check($variant);
    }
    app('auth')->forgetGuards();
    $key = $this->mcpKey($this->user, ['designs:read', 'designs:write']);
    [$error, $result] = $this->callTool($key, 'manage_design', ['action' => 'template', 'design_id' => $source->id, 'revision' => 1]);
    expect($error)->toBeFalse();
    $check($result['design']);
});

test('Studio automation: a connector pointing outside its page loses that end when copied', function () {
    $document = linkedBoard();
    $document['pages'][0]['elements'][2]['to'] = 'otra_pagina';
    $source = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Pizarra', 'document' => $document]);
    $design = $this->postJson('/studio/designs/'.$source->id.'/template', ['revision' => 1])->assertCreated()->json('design');
    [$elements, $from, $to] = linkedEnds($design['document']);
    expect($from)->toBe($elements[0]['id'])->and($to)->toBeNull();
});

test('Studio: connector ends must be shapes of the same page, never the connector itself', function () {
    $document = linkedBoard();
    $document['pages'][0]['elements'][2]['from'] = 'conector_1';
    $document['pages'][0]['elements'][2]['to'] = 'fantasma';
    $id = $this->postJson($this->createUrl, ['name' => 'Enlaces', 'document' => $document])->assertCreated()->json('design.id');
    [, $from, $to] = linkedEnds($this->getJson('/studio/designs/'.$id)->json('design.document'));
    expect($from)->toBeNull()->and($to)->toBeNull();
    $two = boardDocument([...boardDocument([])['pages'][0]['elements'], boardTable()]);
    $two['pages'][] = ['id' => 'page_2', 'name' => 'Dos', 'background' => '#ffffff', 'duration' => 5, 'elements' => [boardConnector(['id' => 'c_2', 'from' => 'tabla_1'])]];
    $id = $this->postJson($this->createUrl, ['name' => 'Cruzado', 'document' => $two])->assertCreated()->json('design.id');
    expect($this->getJson('/studio/designs/'.$id)->json('design.document.pages.1.elements.0.from'))->toBeNull();
});

test('Studio: a page may hold at most 2000 table cells', function () {
    $big = fn (string $id) => ['id' => $id] + boardTable(['rows' => array_fill(0, 30, array_fill(0, 12, 'x'))]);
    $this->postJson($this->createUrl, ['name' => 'Celdas', 'document' => boardDocument(array_map($big, ['t1', 't2', 't3', 't4', 't5', 't6']))])
        ->assertUnprocessable()->assertJsonValidationErrors('document.pages.0.elements');
    $this->postJson($this->createUrl, ['name' => 'Celdas', 'document' => boardDocument(array_map($big, ['t1', 't2', 't3', 't4', 't5']))])->assertCreated();
});

test('Studio automation: resizing scales connector points, not its transform', function () {
    $source = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Pizarra', 'document' => linkedBoard()]);
    $design = $this->postJson('/studio/designs/'.$source->id.'/resize', ['revision' => 1, 'width' => 6400, 'height' => 3600])->assertOk()->json('design');
    $connector = $design['document']['pages'][0]['elements'][2];
    expect($connector['points'])->toEqual([[0, 0], [300, 0], [300, 240], [600, 240]])
        ->and($connector['width'])->toEqual(600)->and($connector['height'])->toEqual(240)
        ->and($connector['x'])->toEqual(200)->and($connector['scaleX'] ?? 1)->toEqual(1)->and($connector['scaleY'] ?? 1)->toEqual(1);
    $shape = $design['document']['pages'][0]['elements'][0];
    expect($shape['scaleX'])->toEqual(2);
});

test('Studio automation: resizing scales the stroke width of connectors like the shapes', function () {
    $source = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Pizarra', 'document' => linkedBoard()]);
    $design = $this->postJson('/studio/designs/'.$source->id.'/resize', ['revision' => 1, 'width' => 6400, 'height' => 3600])->assertOk()->json('design');
    expect($design['document']['pages'][0]['elements'][2]['strokeWidth'])->toEqual(8);
});

test('Studio: a timeline document also clears connector ends that are missing or self-referencing', function () {
    $document = linkedBoard();
    $document['schema_version'] = 2;
    $document['timeline'] = ['fps' => 24, 'audio_tracks' => []];
    $document['pages'][0]['elements'][2]['from'] = 'conector_1';
    $document['pages'][0]['elements'][2]['to'] = 'fantasma';
    $id = $this->postJson($this->createUrl, ['name' => 'V2', 'document' => $document])->assertCreated()->json('design.id');
    $connector = $this->getJson('/studio/designs/'.$id)->json('design.document.pages.0.elements.2');
    expect($connector['from'])->toBeNull()->and($connector['to'])->toBeNull();
});
