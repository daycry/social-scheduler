<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\StudioFont;
use App\Models\User;
use App\Services\Studio\StudioDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->otherBrand = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user);
    $this->fontsUrl = '/brands/'.$this->brand->id.'/studio/fonts';
});

test('Studio fonts: the private brand catalog is paginated and starts empty', function () {
    $this->getJson($this->fontsUrl.'?per_page=5')->assertOk()
        ->assertJsonPath('fonts', [])->assertJsonPath('pagination.total', 0)
        ->assertJsonPath('pagination.per_page', 5)->assertJsonPath('pagination.next_page', null);
});

test('Studio fonts: rejects corrupt font bytes without registering or storing a resource', function () {
    $response = $this->post($this->fontsUrl, ['file' => UploadedFile::fake()->createWithContent('pretend.ttf', 'not an actual font')], ['Accept' => 'application/json']);
    $response->assertUnprocessable()->assertJsonValidationErrors('file');
    expect(Storage::disk('local')->allFiles('studio-fonts'))->toBe([]);
});

function studioFontUpload(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('SpaceGrotesk.ttf', file_get_contents(base_path('tests/Fixtures/fonts/SpaceGrotesk-Variable.ttf')));
}

test('Studio fonts: uploads a real licensed font and serves normalized WOFF2 bytes privately', function () {
    $response = $this->post($this->fontsUrl, ['file' => studioFontUpload(), 'name' => 'Nuestra tipografía'], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('font.family', 'Space Grotesk')->assertJsonPath('font.format', 'woff2')
        ->assertJsonPath('font.mime', 'font/woff2')->assertJsonPath('font.status', 'ready')->assertJsonPath('font.name', 'Nuestra tipografía');
    $font = $response->json('font');
    expect($font['css_family'])->toBe('StudioFont_'.$font['id'].'_'.substr($font['sha256'], 0, 16))
        ->and($font)->not->toHaveKeys(['path', 'disk', 'user_id']);
    $file = $this->get($font['file_url'])->assertOk()->assertHeader('Content-Type', 'font/woff2')->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $bytes = $file->streamedContent();
    expect(substr($bytes, 0, 4))->toBe('wOF2')->and(hash('sha256', $bytes))->toBe($font['sha256'])->and(strlen($bytes))->toBe($font['size']);
});

function uploadedStudioFont($test): array
{
    return $test->post($test->fontsUrl, ['file' => studioFontUpload()], ['Accept' => 'application/json'])->assertCreated()->json('font');
}

test('Studio fonts: deleting a brand or owner cascades font references without blocking unrelated brands', function (string $target) {
    $font = uploadedStudioFont($this);
    $document = studioFontScene($font['id'], $font['family']);
    $this->postJson('/brands/'.$this->brand->id.'/studio/designs', ['name' => 'Con fuente', 'document' => $document])->assertCreated();
    $this->putJson('/brands/'.$this->brand->id.'/studio/brand-kit', ['fonts' => [$font['family']], 'font_ids' => [$font['id']]])->assertOk();
    $unrelated = Brand::factory()->for(User::factory()->create())->create();
    expect(DB::table('studio_font_usages')->where('font_id', $font['id'])->exists())->toBeTrue();
    ($target === 'brand' ? $this->brand : $this->user)->delete();
    expect(StudioFont::withoutUserScope()->find($font['id']))->toBeNull()
        ->and(Brand::withoutUserScope()->find($unrelated->id))->not->toBeNull()
        ->and(DB::table('studio_font_usages')->where('font_id', $font['id'])->exists())->toBeFalse()
        ->and(DB::table('studio_brand_kit_fonts')->where('font_id', $font['id'])->exists())->toBeFalse();
})->with(['brand', 'user']);

function studioFontScene(?int $fontId = null, string $family = 'Arial'): array
{
    $document = StudioDocument::blank();
    $element = ['id' => 'title', 'type' => 'text', 'x' => 10, 'y' => 10, 'width' => 600, 'height' => 80, 'text' => 'Texto de marca', 'fontFamily' => $family, 'fontSize' => 48];
    if ($fontId !== null) {
        $element['font_id'] = $fontId;
    }
    $document['pages'][0]['elements'] = [$element];

    return $document;
}

test('Studio fonts: private font references survive saving and are isolated by brand', function () {
    $font = uploadedStudioFont($this);
    $document = studioFontScene($font['id'], $font['family']);
    $created = $this->postJson('/brands/'.$this->brand->id.'/studio/designs', ['name' => 'Fuente propia', 'document' => $document])->assertCreated();
    $this->getJson('/studio/designs/'.$created->json('design.id'))->assertOk()->assertJsonPath('design.document', $document);
    $this->postJson('/brands/'.$this->otherBrand->id.'/studio/designs', ['name' => 'Ajena a marca', 'document' => $document])->assertUnprocessable();
    $this->get('/brands/'.$this->otherBrand->id.'/studio/fonts/'.$font['id'].'/file')->assertNotFound();
});

test('Studio fonts: archived fonts remain usable by history and deletion protects every revision', function () {
    $font = uploadedStudioFont($this);
    $document = studioFontScene($font['id'], $font['family']);
    $created = $this->postJson('/brands/'.$this->brand->id.'/studio/designs', ['name' => 'Con historial', 'document' => $document])->assertCreated()->json('design');
    $this->patchJson('/studio/designs/'.$created['id'], ['name' => 'Ahora local', 'revision' => 1, 'document' => studioFontScene()])->assertOk();
    $url = $this->fontsUrl.'/'.$font['id'];
    $this->deleteJson($url)->assertConflict();
    $this->postJson($url.'/archive')->assertOk()->assertJsonPath('font.archived_at', fn ($value) => is_string($value));
    $this->getJson($this->fontsUrl)->assertOk()->assertJsonCount(0, 'fonts');
    $this->getJson($this->fontsUrl.'?archived=true')->assertOk()->assertJsonPath('fonts.0.id', $font['id']);
    $this->get($font['file_url'])->assertOk();
    $this->postJson('/brands/'.$this->brand->id.'/studio/designs', ['name' => 'Copia histórica', 'document' => $document])->assertCreated();
    $this->postJson($url.'/restore')->assertOk()->assertJsonPath('font.archived_at', null);
});

test('Studio fonts: a reserved internal font family cannot bypass a private font reference', function () {
    $this->postJson('/brands/'.$this->brand->id.'/studio/designs', ['name' => 'No eludir', 'document' => studioFontScene(null, 'StudioFont_999_abcd')])->assertUnprocessable();
});

test('Studio images: a typed mask is stored while a crop outside natural pixels is rejected', function () {
    $asset = MediaAsset::factory()->for($this->user)->create(['width' => 200, 'height' => 100]);
    $this->postJson('/brands/'.$this->brand->id.'/studio/assets', ['media_id' => $asset->id])->assertCreated();
    $document = StudioDocument::blank();
    $document['pages'][0]['elements'] = [['id' => 'photo', 'type' => 'image', 'asset_id' => $asset->id, 'x' => 0, 'y' => 0,
        'width' => 100, 'height' => 100, 'cropX' => 10, 'mask' => ['type' => 'ellipse']]];
    $this->postJson('/brands/'.$this->brand->id.'/studio/designs', ['name' => 'Máscara', 'document' => $document])->assertCreated()->assertJsonPath('design.document', $document);
    unset($document['pages'][0]['elements'][0]['mask']);
    $document['pages'][0]['elements'][0]['cropX'] = 200;
    $this->postJson('/brands/'.$this->brand->id.'/studio/designs', ['name' => 'Fuera', 'document' => $document])->assertUnprocessable();
});

test('Studio images: crop origin cannot point outside the natural resource', function () {
    $asset = MediaAsset::factory()->for($this->user)->create(['width' => 200, 'height' => 100]);
    $this->postJson('/brands/'.$this->brand->id.'/studio/assets', ['media_id' => $asset->id])->assertCreated();
    $document = StudioDocument::blank();
    $document['pages'][0]['elements'] = [['id' => 'photo', 'type' => 'image', 'asset_id' => $asset->id, 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'cropX' => 200]];
    $this->postJson('/brands/'.$this->brand->id.'/studio/designs', ['name' => 'Fuera', 'document' => $document])->assertUnprocessable();
});

test('Studio fonts: kit font IDs stay aligned and apply together with the family', function () {
    $first = uploadedStudioFont($this);
    $second = uploadedStudioFont($this);
    $kitUrl = '/brands/'.$this->brand->id.'/studio/brand-kit';
    $this->putJson($kitUrl, ['fonts' => [$first['family']], 'font_ids' => [$first['id']]])->assertOk()->assertJsonPath('kit.font_ids.0', $first['id']);
    $created = $this->postJson('/brands/'.$this->brand->id.'/studio/designs', ['name' => 'Kit trazable', 'document' => studioFontScene($first['id'], $first['family'])])->assertCreated()->json('design');
    $this->putJson($kitUrl, ['fonts' => [$second['family']], 'font_ids' => [$second['id']]])->assertOk();
    $this->postJson('/studio/designs/'.$created['id'].'/apply-kit', ['revision' => 1])->assertOk()
        ->assertJsonPath('design.document.pages.0.elements.0.font_id', $second['id']);
});

test('Studio fonts: API requires abilities and returns bearer-readable font resources', function () {
    $font = uploadedStudioFont($this);
    app('auth')->forgetGuards();
    $apiUrl = '/api/v1/brands/'.$this->brand->id.'/design-fonts';
    $this->getJson($apiUrl)->assertUnauthorized();
    $reader = $this->mcpKey($this->user, ['designs:read']);
    $this->getJson($apiUrl, ['Authorization' => 'Bearer '.$reader])->assertOk()->assertJsonPath('fonts.0.id', $font['id']);
    $data = $this->getJson($apiUrl.'/'.$font['id'], ['Authorization' => 'Bearer '.$reader])->assertOk()->json('font');
    expect($data['file_url'])->toStartWith('/api/v1/');
    $this->get($data['file_url'], ['Authorization' => 'Bearer '.$reader])->assertOk();
    $this->post($apiUrl, ['file' => studioFontUpload()], ['Authorization' => 'Bearer '.$reader, 'Accept' => 'application/json'])->assertForbidden();
    $writer = $this->mcpKey($this->user, ['designs:write']);
    app('auth')->forgetGuards();
    $this->post($apiUrl, ['file' => studioFontUpload()], ['Authorization' => 'Bearer '.$writer, 'Accept' => 'application/json'])->assertCreated();
});

test('Studio fonts: MCP discovers and lists private fonts with exact read and write abilities', function () {
    $font = uploadedStudioFont($this);
    [$error, $data] = $this->callTool($this->mcpKey($this->user, ['designs:read']), 'manage_studio_font', ['action' => 'list', 'brand_id' => $this->brand->id]);
    expect($error)->toBeFalse()->and($data['fonts'][0]['id'])->toBe($font['id']);
    [$denied] = $this->callTool($this->mcpKey($this->user, ['designs:read']), 'manage_studio_font', ['action' => 'upload', 'brand_id' => $this->brand->id,
        'name' => 'No escribir', 'file_base64' => base64_encode(file_get_contents(base_path('tests/Fixtures/fonts/SpaceGrotesk-Variable.ttf')))]);
    expect($denied)->toBeTrue();
});

test('Studio fonts: unavailable parser returns an explicit retryable error and cleans probe bytes', function () {
    config(['studio.python' => base_path('missing-python-executable')]);
    $this->post($this->fontsUrl, ['file' => studioFontUpload()], ['Accept' => 'application/json'])->assertStatus(503);
    expect(Storage::disk('local')->allFiles('studio-font-probe'))->toBe([]);
});

test('Studio fonts: source WOFF2, literal search, kit retention and deletion use real bytes', function () {
    $file = UploadedFile::fake()->createWithContent('source.woff2', file_get_contents(base_path('tests/Fixtures/fonts/SpaceGrotesk-Variable.woff2')));
    $font = $this->post($this->fontsUrl, ['file' => $file, 'name' => '100%_brand'], ['Accept' => 'application/json'])->assertCreated()->json('font');
    $this->getJson($this->fontsUrl.'?query='.urlencode('%_'))->assertOk()->assertJsonCount(1, 'fonts');
    $this->getJson($this->fontsUrl.'?query=nonexistent')->assertJsonCount(0, 'fonts');
    $kit = '/brands/'.$this->brand->id.'/studio/brand-kit';
    $this->putJson($kit, ['fonts' => [$font['family']], 'font_ids' => [$font['id']]])->assertOk();
    $this->deleteJson($this->fontsUrl.'/'.$font['id'])->assertStatus(409);
    $this->putJson($kit, ['fonts' => ['Arial']])->assertOk()->assertJsonPath('kit.font_ids.0', null);
    $this->deleteJson($this->fontsUrl.'/'.$font['id'])->assertNoContent();
    $this->get($font['file_url'])->assertNotFound();
});

test('Studio fonts: kits reject misaligned, foreign and mismatched font resources', function () {
    $font = uploadedStudioFont($this);
    $kit = '/brands/'.$this->brand->id.'/studio/brand-kit';
    $this->putJson($kit, ['fonts' => [$font['family']], 'font_ids' => []])->assertUnprocessable();
    $this->putJson($kit, ['fonts' => ['Arial'], 'font_ids' => [$font['id']]])->assertUnprocessable();
    $this->putJson('/brands/'.$this->otherBrand->id.'/studio/brand-kit', ['fonts' => [$font['family']], 'font_ids' => [$font['id']]])->assertUnprocessable();
});

test('Studio fonts: index and mutations carry archived kit font metadata beyond the active catalog', function () {
    $font = uploadedStudioFont($this);
    $this->putJson('/brands/'.$this->brand->id.'/studio/brand-kit', ['fonts' => [$font['family']], 'font_ids' => [$font['id']]])->assertOk();
    $this->postJson($this->fontsUrl.'/'.$font['id'].'/archive')->assertOk();
    $this->getJson('/brands/'.$this->brand->id.'/studio/designs')->assertOk()->assertJsonPath('fonts.0.id', $font['id']);
    $design = $this->postJson('/brands/'.$this->brand->id.'/studio/designs', ['name' => 'Metadata', 'document' => studioFontScene($font['id'], $font['family'])])->assertCreated()->json('design');
    expect($design['fonts'][0]['id'])->toBe($font['id']);
    $this->getJson('/studio/designs/'.$design['id'])->assertOk()->assertJsonPath('fonts.0.id', $font['id']);
});

test('Studio fonts: fallback family lists cannot name reserved private CSS families', function () {
    $this->postJson('/brands/'.$this->brand->id.'/studio/designs', ['name' => 'Reserved fallback', 'document' => studioFontScene(null, 'Arial, StudioFont_999_abcd')])->assertUnprocessable();
});

test('Studio fonts: MCP uploads bounded real bytes and supports archive restore and unused deletion', function () {
    $key = $this->mcpKey($this->user, ['designs:write', 'designs:read']);
    [$error, $result] = $this->callTool($key, 'manage_studio_font', ['action' => 'upload', 'brand_id' => $this->brand->id,
        'name' => 'Agent font', 'file_base64' => base64_encode(file_get_contents(base_path('tests/Fixtures/fonts/SpaceGrotesk-Variable.ttf')))]);
    expect($error)->toBeFalse()->and($result['font']['family'])->toBe('Space Grotesk');
    $id = $result['font']['id'];
    [$error, $archived] = $this->callTool($key, 'manage_studio_font', ['action' => 'archive', 'brand_id' => $this->brand->id, 'font_id' => $id]);
    expect($error)->toBeFalse()->and($archived['font']['archived_at'])->not->toBeNull();
    [$error, $restored] = $this->callTool($key, 'manage_studio_font', ['action' => 'restore', 'brand_id' => $this->brand->id, 'font_id' => $id]);
    expect($error)->toBeFalse()->and($restored['font']['archived_at'])->toBeNull();
    [$error, $deleted] = $this->callTool($key, 'manage_studio_font', ['action' => 'delete', 'brand_id' => $this->brand->id, 'font_id' => $id]);
    expect($error)->toBeFalse()->and($deleted['deleted'])->toBeTrue();
    [$error] = $this->callTool($key, 'manage_studio_font', ['action' => 'upload', 'brand_id' => $this->brand->id, 'file_base64' => 'not base64!']);
    expect($error)->toBeTrue();
});
