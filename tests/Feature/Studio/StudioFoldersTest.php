<?php

use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\Studio\DesignFolder;
use App\Models\Studio\DesignRevision;
use App\Models\User;
use App\Services\Studio\StudioDocument;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Route;
use Tests\Support\McpClient;

uses(McpClient::class);

// T-33: renombrar, borrar (los diseños pasan a «Sin carpeta») y mover a carpeta, con autorizacion por recurso en web, API y MCP.

beforeEach(function () {
    foreach ([['studio.index', 'web', 'studio.php'], ['api.studio.designs.index', 'api', 'studio-api.php']] as [$name, $middleware, $file]) {
        if (! Route::has($name) && is_file(base_path('routes/'.$file))) {
            Route::middleware($middleware)->group(base_path('routes/'.$file));
            Route::getRoutes()->refreshNameLookups();
            Route::getRoutes()->refreshActionLookups();
        }
    }
    $this->user = User::factory()->create();
    $this->stranger = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->otherBrand = Brand::factory()->for($this->user)->create();
    $studio = app(StudioService::class);
    $this->folderA = $studio->createFolder($this->user, $this->brand, 'Lanzamientos');
    $this->folderB = $studio->createFolder($this->user, $this->brand, 'Archivo');
    $this->foreignFolder = $studio->createFolder($this->stranger, Brand::factory()->for($this->stranger)->create(), 'Ajena');
    $this->otherBrandFolder = $studio->createFolder($this->user, $this->otherBrand, 'Otra marca');
    $this->design = $studio->create($this->user, $this->brand, ['name' => 'Diseño', 'document' => StudioDocument::blank(), 'folder_id' => $this->folderA->id]);
});

test('renombrar una carpeta la deja con el nombre limpio y valida el nombre', function () {
    $this->actingAs($this->user)->patchJson(route('studio.folders.update', $this->folderA->id), ['name' => '  Campañas 2027  '])
        ->assertOk()->assertJsonPath('folder.name', 'Campañas 2027');
    expect($this->folderA->fresh()->name)->toBe('Campañas 2027');

    $this->actingAs($this->user)->patchJson(route('studio.folders.update', $this->folderA->id), ['name' => '   '])->assertUnprocessable();
    $this->actingAs($this->user)->patchJson(route('studio.folders.update', $this->folderA->id), ['name' => str_repeat('a', 256)])->assertUnprocessable();
});

test('una carpeta o un diseño ajeno responde 404 al renombrar, mover o borrar (sin IDOR)', function () {
    $this->actingAs($this->stranger);
    $this->patchJson(route('studio.folders.update', $this->folderA->id), ['name' => 'Hackeada'])->assertNotFound();
    $this->deleteJson(route('studio.folders.destroy', $this->folderA->id))->assertNotFound();
    $this->patchJson(route('studio.designs.move', $this->design->id), ['folder_id' => $this->foreignFolder->id])->assertNotFound();

    $this->actingAs($this->user);
    $this->patchJson(route('studio.designs.move', $this->design->id), ['folder_id' => $this->foreignFolder->id])->assertUnprocessable();
    expect($this->folderA->fresh()->name)->toBe('Lanzamientos')->and($this->design->fresh()->folder_id)->toBe($this->folderA->id);
});

test('mover un diseño entre carpetas y a Sin carpeta no crea revision y exige una carpeta de su marca', function () {
    $revisions = DesignRevision::withoutUserScope()->where('design_id', $this->design->id)->count();
    $this->actingAs($this->user);

    $this->patchJson(route('studio.designs.move', $this->design->id), ['folder_id' => $this->folderB->id])->assertOk()->assertJsonPath('design.folder_id', $this->folderB->id);
    $this->patchJson(route('studio.designs.move', $this->design->id), ['folder_id' => $this->otherBrandFolder->id])->assertUnprocessable();
    $this->patchJson(route('studio.designs.move', $this->design->id), [])->assertUnprocessable(); // folder_id es obligatorio aunque sea nulo
    $this->patchJson(route('studio.designs.move', $this->design->id), ['folder_id' => null])->assertOk()->assertJsonPath('design.folder_id', null);

    expect($this->design->fresh()->revision)->toBe(1)->and(DesignRevision::withoutUserScope()->where('design_id', $this->design->id)->count())->toBe($revisions);
});

test('borrar una carpeta con diseños los conserva en Sin carpeta, incluidos los archivados, y avisa de cuantos', function () {
    $second = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Otro', 'document' => StudioDocument::blank(), 'folder_id' => $this->folderA->id]);
    app(StudioService::class)->archive($this->user, $second);

    $this->actingAs($this->user)->getJson(route('studio.folders.index', $this->brand->id))->assertOk()->assertJsonPath('folders.1.designs_count', 1)->assertJsonPath('folders.0.designs_count', 0); // ordenadas por nombre; la archivada no cuenta

    $this->actingAs($this->user)->deleteJson(route('studio.folders.destroy', $this->folderA->id))->assertOk()->assertJsonPath('deleted', true)->assertJsonPath('designs_moved', 2);

    expect(DesignFolder::withoutUserScope()->whereKey($this->folderA->id)->exists())->toBeFalse()
        ->and($this->design->fresh()->folder_id)->toBeNull()
        ->and(Design::withoutUserScope()->withTrashed()->whereKey($second->id)->value('folder_id'))->toBeNull();
});

test('un autoguardado del editor no pisa un cambio de carpeta ni falla con una carpeta borrada', function () {
    $this->actingAs($this->user);
    $this->patchJson(route('studio.designs.move', $this->design->id), ['folder_id' => $this->folderB->id])->assertOk();
    $payload = ['name' => 'Diseño', 'document' => StudioDocument::blank(), 'revision' => 1, 'folder_id' => $this->folderA->id, 'autosave' => true];

    $this->patchJson(route('studio.designs.update', $this->design->id), $payload)->assertOk()->assertJsonPath('design.folder_id', $this->folderB->id);

    $this->deleteJson(route('studio.folders.destroy', $this->folderB->id))->assertOk();
    $this->patchJson(route('studio.designs.update', $this->design->id), ['revision' => 2, 'folder_id' => $this->folderB->id] + $payload)->assertOk()->assertJsonPath('design.folder_id', null);
});

test('API: renombrar, mover y borrar respetan abilities y propietario', function () {
    $key = $this->mcpKey($this->user, ['designs:read', 'designs:write']);
    $read = $this->mcpKey($this->user, ['designs:read']);
    $headers = fn (string $k) => (function () use ($k) {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer '.$k];
    })();

    $this->patchJson('/api/v1/design-folders/'.$this->folderA->id, ['name' => 'Nueva'], $headers($read))->assertForbidden();
    $this->patchJson('/api/v1/design-folders/'.$this->folderA->id, ['name' => 'Nueva'], $headers($key))->assertOk()->assertJsonPath('folder.name', 'Nueva');
    $this->patchJson('/api/v1/designs/'.$this->design->id.'/folder', ['folder_id' => $this->folderB->id], $headers($key))->assertOk()->assertJsonPath('design.folder_id', $this->folderB->id);
    $this->patchJson('/api/v1/designs/'.$this->design->id.'/folder', ['folder_id' => $this->foreignFolder->id], $headers($key))->assertUnprocessable();

    $foreign = $this->mcpKey($this->stranger, ['designs:read', 'designs:write']);
    $this->patchJson('/api/v1/design-folders/'.$this->folderA->id, ['name' => 'X'], $headers($foreign))->assertNotFound();
    $this->patchJson('/api/v1/designs/'.$this->design->id.'/folder', ['folder_id' => null], $headers($foreign))->assertNotFound();
    $this->deleteJson('/api/v1/design-folders/'.$this->folderA->id, [], $headers($foreign))->assertNotFound();

    $this->deleteJson('/api/v1/design-folders/'.$this->folderB->id, [], $headers($key))->assertOk()->assertJsonPath('designs_moved', 1);
});

test('MCP: rename y move comprueban marca, propietario y abilities', function () {
    $key = $this->mcpKey($this->user, ['designs:read', 'designs:write']);

    [$err, $renamed] = $this->callTool($key, 'manage_design_folder', ['action' => 'rename', 'brand_id' => $this->brand->id, 'folder_id' => $this->folderA->id, 'name' => 'MCP nueva']);
    expect($err)->toBeFalse()->and($renamed['name'])->toBe('MCP nueva');

    [$err, $moved] = $this->callTool($key, 'manage_design_folder', ['action' => 'move', 'brand_id' => $this->brand->id, 'design_id' => $this->design->id, 'folder_id' => $this->folderB->id]);
    expect($err)->toBeFalse()->and($moved['folder_id'])->toBe($this->folderB->id);
    [$err, $moved] = $this->callTool($key, 'manage_design_folder', ['action' => 'move', 'brand_id' => $this->brand->id, 'design_id' => $this->design->id]);
    expect($err)->toBeFalse()->and($moved['folder_id'])->toBeNull();

    [$wrongBrand] = $this->callTool($key, 'manage_design_folder', ['action' => 'move', 'brand_id' => $this->otherBrand->id, 'design_id' => $this->design->id]);
    [$foreign] = $this->callTool($key, 'manage_design_folder', ['action' => 'move', 'brand_id' => $this->brand->id, 'design_id' => $this->design->id, 'folder_id' => $this->foreignFolder->id]);
    [$readOnly] = $this->callTool($this->mcpKey($this->user, ['designs:read']), 'manage_design_folder', ['action' => 'rename', 'brand_id' => $this->brand->id, 'folder_id' => $this->folderA->id, 'name' => 'x']);
    [$stranger] = $this->callTool($this->mcpKey($this->stranger), 'manage_design_folder', ['action' => 'rename', 'brand_id' => $this->brand->id, 'folder_id' => $this->folderA->id, 'name' => 'x']);
    expect($wrongBrand)->toBeTrue()->and($foreign)->toBeTrue()->and($readOnly)->toBeTrue()->and($stranger)->toBeTrue();
});
