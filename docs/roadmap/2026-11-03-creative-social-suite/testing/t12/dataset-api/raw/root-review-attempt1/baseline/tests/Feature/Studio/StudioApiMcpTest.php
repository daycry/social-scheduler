<?php

use App\Jobs\Studio\RenderStudioDesign;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\User;
use App\Services\Studio\StudioDocument;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    if (! Route::has('api.studio.designs.index') && is_file(base_path('routes/studio-api.php'))) {
        Route::middleware('api')->group(base_path('routes/studio-api.php'));
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Diseño API', 'document' => StudioDocument::blank()]);
    $this->key = $this->mcpKey($this->user, ['designs:read', 'designs:write', 'stats:read']);
});

function studioApiHeaders(string $key): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$key];
}

test('Studio API: bearer and scoped abilities protect shared design services', function () {
    $url = '/api/v1/brands/'.$this->brand->id.'/designs';
    $this->getJson($url)->assertUnauthorized();
    $readKey = $this->mcpKey($this->user, ['designs:read']);
    $this->getJson($url, studioApiHeaders($readKey))->assertOk()->assertJsonPath('data.0.name', 'Diseño API');
    $this->postJson($url, ['name' => 'Denegado', 'document' => StudioDocument::blank()], studioApiHeaders($readKey))->assertForbidden();
    $created = $this->postJson($url, ['name' => 'Creado', 'document' => StudioDocument::blank()], studioApiHeaders($this->key))
        ->assertCreated()->assertJsonPath('revision', 1)->json();
    $this->patchJson('/api/v1/designs/'.$created['id'], ['name' => 'Actualizado', 'document' => StudioDocument::blank(), 'revision' => 1], studioApiHeaders($this->key))->assertOk()->assertJsonPath('revision', 2);
    $this->patchJson('/api/v1/designs/'.$created['id'], ['name' => 'Viejo', 'document' => StudioDocument::blank(), 'revision' => 1], studioApiHeaders($this->key))->assertConflict();
});

test('Studio API: ownership, kit, registered assets and archive follow the native contract', function () {
    $url = '/api/v1/designs/'.$this->design->id;
    $foreign = $this->mcpKey(User::factory()->create(), ['*']);
    $this->getJson($url, studioApiHeaders($foreign))->assertNotFound();
    $this->getJson($url, studioApiHeaders($this->key))->assertOk()->assertJsonPath('document', StudioDocument::blank())->assertJsonPath('exports', []);
    $kitUrl = '/api/v1/brands/'.$this->brand->id.'/brand-kit';
    $this->putJson($kitUrl, ['palette' => ['#ff0000']], studioApiHeaders($this->key))->assertOk()->assertJsonPath('palette', ['#ff0000']);
    $this->getJson('/api/v1/brands/'.$this->brand->id.'/design-assets', studioApiHeaders($this->key))->assertOk()->assertJsonPath('assets', []);
    $this->deleteJson($url, [], studioApiHeaders($this->key))->assertOk();
    $this->postJson($url.'/restore', [], studioApiHeaders($this->key))->assertOk()->assertJsonPath('id', $this->design->id);
    $this->postJson($url.'/duplicate', [], studioApiHeaders($this->key))->assertCreated()->assertJsonPath('revision', 1);
});

test('Studio API: boolean query filters accept their OpenAPI wire representation', function () {
    app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Plantilla', 'document' => StudioDocument::blank(), 'is_template' => true]);
    $url = '/api/v1/brands/'.$this->brand->id.'/designs?templates=true&archived=false';
    $this->getJson($url, studioApiHeaders($this->key))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Plantilla');
});

test('Studio API: analytics shares metrics service and preserves unavailable values', function () {
    $url = '/api/v1/brands/'.$this->brand->id.'/analytics?from=2026-10-01&to=2026-10-05';
    $this->getJson($url, studioApiHeaders($this->mcpKey($this->user, ['designs:read'])))->assertForbidden();
    $response = $this->getJson($url, studioApiHeaders($this->key))->assertOk()->assertJsonPath('period.from', '2026-10-01');
    expect(collect($response->json('cards'))->firstWhere('key', 'views')['total'])->toBeNull();
    $this->getJson(str_replace('2026-10-05', '2026-09-01', $url), studioApiHeaders($this->key))->assertUnprocessable();
});

test('Studio MCP: native tools are discoverable across tools list pagination', function () {
    $names = [];
    $cursor = null;
    do {
        $page = $this->rpc($this->key, 'tools/list', $cursor ? ['cursor' => $cursor] : [])->assertOk()->json('result');
        if ($names === []) {
            expect($page['tools'])->toHaveCount(30)->and($page['nextCursor'])->not->toBeNull();
        }
        $names = [...$names, ...array_column($page['tools'], 'name')];
        $cursor = $page['nextCursor'] ?? null;
    } while ($cursor !== null);
    expect($names)->toHaveCount(41)->toContain('list_designs', 'get_design', 'create_design', 'update_design', 'export_design', 'manage_brand_kit', 'attach_design_asset', 'manage_design', 'manage_design_export', 'manage_design_captions', 'manage_design_folder', 'manage_studio_font', 'process_studio_image', 'manage_studio_audio')
        ->and(count($names))->toBe(count(array_unique($names)))->and(array_count_values($names)['get_stats'])->toBe(1);
    $instructions = $this->rpc($this->key, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']])->assertOk()->json('result.instructions');
    expect($instructions)->toContain('list_designs', 'attach_design_asset', 'nextCursor', 'manage_studio_font', 'process_studio_image', 'font_id');
});

test('Studio MCP: management duplicates independent revisions and restores only the owned archived design', function () {
    [$copyError, $copy] = $this->callTool($this->key, 'manage_design', ['action' => 'duplicate', 'design_id' => $this->design->id, 'name' => 'Copia MCP']);
    expect($copyError)->toBeFalse()->and($copy['id'])->not->toBe($this->design->id)->and($copy['revision'])->toBe(1);
    [, $changed] = $this->callTool($this->key, 'update_design', ['design_id' => $copy['id'], 'name' => 'Copia modificada', 'revision' => 1, 'document' => StudioDocument::blank()]);
    expect($changed['revision'])->toBe(2)->and($this->design->refresh()->revision)->toBe(1);
    Queue::fake();
    [, $export] = $this->callTool($this->key, 'export_design', ['design_id' => $copy['id'], 'revision' => 1, 'format' => 'png', 'pages' => ['page_1']]);
    [$archiveError, $archived] = $this->callTool($this->key, 'manage_design', ['action' => 'archive', 'design_id' => $copy['id']]);
    expect($archiveError)->toBeFalse()->and($archived['archived'])->toBeTrue();
    [$hidden] = $this->callTool($this->key, 'get_design', ['design_id' => $copy['id']]);
    expect($hidden)->toBeTrue();
    [$foreignRestore] = $this->callTool($this->mcpKey(User::factory()->create()), 'manage_design', ['action' => 'restore', 'design_id' => $copy['id']]);
    expect($foreignRestore)->toBeTrue();
    [$restoreError, $restored] = $this->callTool($this->key, 'manage_design', ['action' => 'restore', 'design_id' => $copy['id']]);
    expect($restoreError)->toBeFalse()->and($restored['revision'])->toBe(2);
    [, $read] = $this->callTool($this->key, 'get_design', ['design_id' => $copy['id']]);
    expect($read['exports'][0]['id'])->toBe($export['id'])->and($read['exports'][0]['revision'])->toBe(1);
    [$readOnlyError] = $this->callTool($this->mcpKey($this->user, ['designs:read']), 'manage_design', ['action' => 'archive', 'design_id' => $copy['id']]);
    [$invalidAction] = $this->callTool($this->key, 'manage_design', ['action' => 'publish', 'design_id' => $copy['id']]);
    expect($readOnlyError)->toBeTrue()->and($invalidAction)->toBeTrue();
});

test('Studio API: folders are private per brand and deletion keeps archived designs and snapshots', function () {
    $url = '/api/v1/brands/'.$this->brand->id.'/design-folders';
    $folder = $this->postJson($url, ['name' => 'Lanzamientos'], studioApiHeaders($this->key))->assertCreated()->json('folder');
    $this->getJson($url, studioApiHeaders($this->key))->assertOk()->assertJsonPath('folders.0.id', $folder['id']);
    $this->getJson($url, studioApiHeaders($this->mcpKey(User::factory()->create())))->assertNotFound();
    $this->postJson($url, ['name' => 'Denegada'], studioApiHeaders($this->mcpKey($this->user, ['designs:read'])))->assertForbidden();
    $created = $this->postJson('/api/v1/brands/'.$this->brand->id.'/designs', ['name' => 'En carpeta', 'folder_id' => $folder['id'], 'document' => StudioDocument::blank()], studioApiHeaders($this->key))->assertCreated()->json();
    $this->deleteJson('/api/v1/designs/'.$created['id'], [], studioApiHeaders($this->key))->assertOk();
    $delete = '/api/v1/design-folders/'.$folder['id'];
    $this->deleteJson($delete, [], studioApiHeaders($this->mcpKey(User::factory()->create())))->assertNotFound();
    $this->deleteJson($delete, [], studioApiHeaders($this->key))->assertOk();
    $this->postJson('/api/v1/designs/'.$created['id'].'/restore', [], studioApiHeaders($this->key))->assertOk()->assertJsonPath('folder_id', null)->assertJsonPath('revision', 1);
});

test('Studio MCP: folder management checks brand ownership and removes associations without deleting designs', function () {
    [$error, $folder] = $this->callTool($this->key, 'manage_design_folder', ['action' => 'create', 'brand_id' => $this->brand->id, 'name' => 'MCP']);
    expect($error)->toBeFalse();
    [$listError, $list] = $this->callTool($this->mcpKey($this->user, ['designs:read']), 'manage_design_folder', ['action' => 'list', 'brand_id' => $this->brand->id]);
    expect($listError)->toBeFalse()->and($list['folders'][0]['id'])->toBe($folder['id']);
    [, $design] = $this->callTool($this->key, 'create_design', ['brand_id' => $this->brand->id, 'name' => 'Con carpeta', 'document' => StudioDocument::blank(), 'folder_id' => $folder['id']]);
    $otherBrand = Brand::factory()->for($this->user)->create();
    [$wrongBrand] = $this->callTool($this->key, 'manage_design_folder', ['action' => 'delete', 'brand_id' => $otherBrand->id, 'folder_id' => $folder['id']]);
    [$wrongUser] = $this->callTool($this->mcpKey(User::factory()->create()), 'manage_design_folder', ['action' => 'delete', 'brand_id' => $this->brand->id, 'folder_id' => $folder['id']]);
    [$readOnly] = $this->callTool($this->mcpKey($this->user, ['designs:read']), 'manage_design_folder', ['action' => 'delete', 'brand_id' => $this->brand->id, 'folder_id' => $folder['id']]);
    expect($wrongBrand)->toBeTrue()->and($wrongUser)->toBeTrue()->and($readOnly)->toBeTrue();
    [$deleted, $result] = $this->callTool($this->key, 'manage_design_folder', ['action' => 'delete', 'brand_id' => $this->brand->id, 'folder_id' => $folder['id']]);
    expect($deleted)->toBeFalse()->and($result['deleted'])->toBeTrue();
    [, $read] = $this->callTool($this->key, 'get_design', ['design_id' => $design['id']]);
    expect($read['folder_id'])->toBeNull()->and($read['revision'])->toBe(1);
});

test('Studio API contract: nullable properties emitted by real responses agree with OpenAPI', function () {
    Queue::fake();
    $export = $this->postJson('/api/v1/designs/'.$this->design->id.'/exports', ['revision' => 1, 'format' => 'png', 'pages' => ['page_1']], studioApiHeaders($this->key))->assertAccepted()->json();
    $kit = $this->putJson('/api/v1/brands/'.$this->brand->id.'/brand-kit', ['guidelines' => null, 'voice' => null], studioApiHeaders($this->key))->assertOk()->json();
    $design = $this->getJson('/api/v1/designs/'.$this->design->id, studioApiHeaders($this->key))->assertOk()->json();
    $source = file_get_contents(base_path('docs/api/creative-suite.openapi.yaml'));
    // Contract regression for nullable fields: inspect the canonical inline property declarations,
    // then validate the values actually returned by the endpoint, rather than fabricated samples.
    foreach ([['Export', $export, ['media_ids', 'error', 'download_url']], ['BrandKit', $kit, ['guidelines', 'voice']], ['CreateDesign', $design, ['folder_id']]] as [$component, $response, $fields]) {
        expect(preg_match('/^    '.preg_quote($component, '/').':\R(.*?)(?=^    [A-Za-z]|\z)/ms', $source, $block))->toBe(1);
        foreach ($fields as $field) {
            expect(array_key_exists($field, $response))->toBeTrue();
            expect(preg_match('/^        '.preg_quote($field, '/').':\s*\{([^\r\n]+)\}/m', $block[1], $property))->toBe(1);
            if ($response[$field] === null) {
                expect(preg_match('/\bnullable:\s*true\b/', $property[1]))->toBe(1, $component.'.'.$field.' rejects a null emitted by the real API');
            } else {
                $type = match (true) {
                    is_int($response[$field]) => 'integer', is_array($response[$field]) => 'array', default => 'string'
                };
                expect(preg_match('/\btype:\s*'.preg_quote($type, '/').'\b/', $property[1]))->toBe(1);
            }
        }
    }
});

test('Studio API and MCP: export history is bounded and pages do not duplicate ids', function () {
    for ($i = 0; $i < 55; $i++) {
        DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $this->design->id,
            'revision' => 1, 'format' => 'png', 'pages' => ['page_1'], 'status' => 'pending']);
    }
    $url = '/api/v1/designs/'.$this->design->id;
    $first = $this->getJson($url, studioApiHeaders($this->key))->assertOk()->assertJsonCount(50, 'exports')->assertJsonPath('exports_pagination.total', 55)->json('exports');
    $second = $this->getJson($url.'/exports?page=2', studioApiHeaders($this->key))->assertOk()->assertJsonCount(5, 'exports')->assertJsonPath('pagination.current_page', 2)->json('exports');
    expect(array_intersect(array_column($first, 'id'), array_column($second, 'id')))->toBe([]);
    [, $mcpFirst] = $this->callTool($this->key, 'get_design', ['design_id' => $this->design->id]);
    [, $mcpSecond] = $this->callTool($this->key, 'get_design', ['design_id' => $this->design->id, 'exports_page' => 2]);
    expect($mcpFirst['exports'])->toHaveCount(50)->and($mcpSecond['exports'])->toHaveCount(5)->and($mcpSecond['exports_pagination']['current_page'])->toBe(2)
        ->and(array_intersect(array_column($mcpFirst['exports'], 'id'), array_column($mcpSecond['exports'], 'id')))->toBe([]);
    $this->getJson($url.'/exports?per_page=101', studioApiHeaders($this->key))->assertUnprocessable();
});

test('Studio MCP: list_designs honors the explicit page argument', function () {
    for ($i = 0; $i < 25; $i++) {
        app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Paginado '.$i, 'document' => StudioDocument::blank()]);
    }
    [$error, $first] = $this->callTool($this->key, 'list_designs', ['brand_id' => $this->brand->id, 'page' => 1]);
    [$secondError, $second] = $this->callTool($this->key, 'list_designs', ['brand_id' => $this->brand->id, 'page' => 2]);
    expect($error)->toBeFalse()->and($secondError)->toBeFalse()->and($second['current_page'])->toBe(2)
        ->and($second['data'])->toHaveCount(2)->and(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')))->toBe([]);
});

test('Studio MCP: own ready media can be registered for a native image design end to end', function () {
    $asset = MediaAsset::factory()->for($this->user)->create(['mime' => 'image/png']);
    [$error, $attached] = $this->callTool($this->key, 'attach_design_asset', ['brand_id' => $this->brand->id, 'media_id' => $asset->id]);
    expect($error)->toBeFalse()->and($attached['id'])->toBe($asset->id);
    $document = StudioDocument::blank();
    $document['pages'][0]['elements'] = [['id' => 'image', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 1080, 'height' => 1080, 'asset_id' => $asset->id]];
    [$createdError, $design] = $this->callTool($this->key, 'create_design', ['brand_id' => $this->brand->id, 'name' => 'Imagen MCP', 'document' => $document]);
    expect($createdError)->toBeFalse()->and($design['document'])->toBe($document);
    [$foreignError] = $this->callTool($this->key, 'attach_design_asset', ['brand_id' => $this->brand->id, 'media_id' => MediaAsset::factory()->create()->id]);
    expect($foreignError)->toBeTrue();
});

test('Studio API and MCP: renderer requests return genuine pending jobs and never invented media', function () {
    Queue::fake();
    $payload = ['revision' => 1, 'format' => 'png', 'pages' => ['page_1']];
    $export = $this->postJson('/api/v1/designs/'.$this->design->id.'/exports', $payload, studioApiHeaders($this->key))
        ->assertAccepted()->assertJsonPath('status', 'pending')->json();
    expect($export['media_ids'])->toBeNull();
    $this->getJson('/api/v1/design-exports/'.$export['id'], studioApiHeaders($this->key))->assertOk()->assertJsonPath('status', 'pending');
    [$error, $mcpExport] = $this->callTool($this->key, 'export_design', ['design_id' => $this->design->id] + $payload);
    expect($error)->toBeFalse()->and($mcpExport['status'])->toBe('pending')->and($mcpExport['media_ids'])->toBeNull();
    Queue::assertPushed(RenderStudioDesign::class, 2);
    $this->postJson('/api/v1/designs/'.$this->design->id.'/exports', ['revision' => 999, 'format' => 'png', 'pages' => ['page_1']], studioApiHeaders($this->key))->assertUnprocessable();
});

test('Studio API: private asset bytes require bearer read scope with no session fallback', function () {
    Storage::fake('local');
    $asset = MediaAsset::factory()->for($this->user)->create(['mime' => 'image/png']);
    Storage::disk('local')->put($asset->path, 'private-bytes');
    $url = $this->postJson('/api/v1/brands/'.$this->brand->id.'/design-assets', ['media_id' => $asset->id], studioApiHeaders($this->key))->assertCreated()->json('asset.file_url');
    $this->get($url, studioApiHeaders($this->key))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->getJson($url, studioApiHeaders($this->mcpKey(User::factory()->create())))->assertNotFound();
    $this->withHeader('Authorization', '')->actingAs($this->user)->getJson($url)->assertUnauthorized();
});

test('Studio MCP: create update read and kit use explicit token ownership and abilities', function () {
    [$error, $created] = $this->callTool($this->key, 'create_design', ['brand_id' => $this->brand->id, 'name' => 'Desde MCP', 'document' => StudioDocument::blank()]);
    expect($error)->toBeFalse()->and($created['revision'])->toBe(1);
    [, $updated] = $this->callTool($this->key, 'update_design', ['design_id' => $created['id'], 'name' => 'Guardado', 'revision' => 1, 'document' => StudioDocument::blank()]);
    expect($updated['revision'])->toBe(2);
    [$stale, $message] = $this->callTool($this->key, 'update_design', ['design_id' => $created['id'], 'name' => 'Viejo', 'revision' => 1, 'document' => StudioDocument::blank()]);
    expect($stale)->toBeTrue()->and($message)->toContain('409');
    [$denied] = $this->callTool($this->mcpKey($this->user, ['designs:read']), 'create_design', ['brand_id' => $this->brand->id, 'name' => 'No', 'document' => StudioDocument::blank()]);
    expect($denied)->toBeTrue();
    [$foreign] = $this->callTool($this->mcpKey(User::factory()->create()), 'get_design', ['design_id' => $created['id']]);
    expect($foreign)->toBeTrue();
    [$kitError, $kit] = $this->callTool($this->key, 'manage_brand_kit', ['brand_id' => $this->brand->id, 'kit' => ['voice' => 'Clara']]);
    expect($kitError)->toBeFalse()->and($kit['voice'])->toBe('Clara');
});
