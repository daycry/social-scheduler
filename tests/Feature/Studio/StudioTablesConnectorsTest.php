<?php

use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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
    $key = (new class
    {
        use Tests\Support\McpClient;

        public function key(User $user, array $abilities): string
        {
            return $this->mcpKey($user, $abilities);
        }
    })->key($this->user, ['designs:read', 'designs:write']);
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
