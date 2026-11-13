<?php

use App\Mcp\Abilities;
use App\Models\Brand;
use App\Models\Canva\CanvaJob;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\McpClient;

uses(McpClient::class);

require_once __DIR__.'/CanvaTestSupport.php';

// T-16 · Paridad API REST / MCP de Canva: abilities canva:read y canva:write, aislamiento por usuario y el mismo servicio que la web.

beforeEach(function () {
    canvaEnvironment();
    [$this->user, $this->brand] = canvaUserAndBrand();
    $this->connection = canvaConnect($this->user, $this->brand);
    $this->api = '/api/v1/brands/'.$this->brand->id.'/canva';
});

/** Cada peticion es independiente: sin el usuario de Sanctum que dejo la anterior en el mismo test. */
function canvaApi($test, string $key)
{
    app('auth')->forgetGuards();

    return $test->withToken($key);
}

function canvaApiFakes(): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'api.canva.com/rest/v1/designs*' => Http::response(['items' => [['id' => 'DAF123abcde', 'title' => 'Rebajas', 'urls' => ['edit_url' => 'https://www.canva.com/api/design/t/edit']]]]),
        'api.canva.com/rest/v1/exports' => Http::response(['job' => ['id' => 'job-1', 'status' => 'in_progress']]),
        'api.canva.com/rest/v1/exports/job-1' => Http::response(['job' => ['id' => 'job-1', 'status' => 'success', 'urls' => ['https://export-download.canva.com/a/x.png?sig=1']]]),
        'export-download.canva.com/*' => fn () => Http::response(canvaPng(), 200, ['Content-Type' => 'image/png']),
    ]);
}

test('API: estado y disenos con canva:read; sin la ability o con otro usuario no se ve nada', function () {
    canvaApiFakes();
    $read = $this->mcpKey($this->user, ['canva:read']);

    canvaApi($this, $read)->getJson($this->api)->assertOk()->assertJsonPath('connected', true)->assertJsonPath('features.0.key', 'designs');
    canvaApi($this, $read)->getJson($this->api.'/designs?query=rebajas')->assertOk()->assertJsonPath('items.0.id', 'DAF123abcde');
    canvaApi($this, $this->mcpKey($this->user, ['posts:read']))->getJson($this->api)->assertForbidden();
    canvaApi($this, $this->mcpKey(User::factory()->create(), ['canva:read']))->getJson($this->api)->assertNotFound();
    app('auth')->forgetGuards();
    $this->flushHeaders()->getJson($this->api)->assertUnauthorized();
});

test('API: exportar exige canva:write (canva:read no basta) y el trabajo termina en la biblioteca con origen Canva', function () {
    canvaApiFakes();
    $write = $this->mcpKey($this->user, ['canva:write', 'canva:read']);

    canvaApi($this, $this->mcpKey($this->user, ['canva:read']))->postJson($this->api.'/exports', ['design_id' => 'DAF123abcde', 'format' => 'png'])->assertForbidden();
    $job = canvaApi($this, $write)->postJson($this->api.'/exports', ['design_id' => 'DAF123abcde', 'format' => 'png', 'title' => 'Rebajas'])->assertStatus(202)->json('job');
    $done = canvaApi($this, $write)->getJson($this->api.'/jobs/'.$job['id'])->assertOk()->json('job');

    expect($done['status'])->toBe('ready')->and($done['media'][0]['origin']['label'])->toBe('Canva')->and($done['media'][0]['source'])->toBe('canva');
    canvaApi($this, $this->mcpKey(User::factory()->create(), ['canva:read']))->getJson($this->api.'/jobs/'.$job['id'])->assertNotFound();
});

test('MCP: manage_canva status, designs, export y get con sus abilities y sin tokens en la salida', function () {
    canvaApiFakes();
    $key = $this->mcpKey($this->user, ['canva:read', 'canva:write']);

    [$e1, $status] = $this->callTool($key, 'manage_canva', ['brand_id' => $this->brand->id, 'action' => 'status']);
    [$e2, $designs] = $this->callTool($key, 'manage_canva', ['brand_id' => $this->brand->id, 'action' => 'designs', 'query' => 'rebajas']);
    [$e3, $export] = $this->callTool($key, 'manage_canva', ['brand_id' => $this->brand->id, 'action' => 'export', 'design_id' => 'DAF123abcde', 'format' => 'png']);
    [$e4, $get] = $this->callTool($key, 'manage_canva', ['brand_id' => $this->brand->id, 'action' => 'get', 'job_id' => $export['job']['id']]);

    expect([$e1, $e2, $e3, $e4])->toBe([false, false, false, false])
        ->and($status['canva']['connected'])->toBeTrue()->and($designs['items'][0]['id'])->toBe('DAF123abcde')
        ->and($get['job']['status'])->toBe('ready')->and($get['job']['media'])->toHaveCount(1)
        ->and(json_encode([$status, $designs, $export, $get]))->not->toContain('at-live')->not->toContain('rt-live')->not->toContain('test-secret-value');
});

test('MCP: abilities separadas, marca ajena y errores de dominio en español', function () {
    canvaApiFakes();
    $read = $this->mcpKey($this->user, ['canva:read']);
    $other = $this->mcpKey(User::factory()->create(), ['canva:read', 'canva:write']);

    [$denied] = $this->callTool($read, 'manage_canva', ['brand_id' => $this->brand->id, 'action' => 'export', 'design_id' => 'DAF123abcde', 'format' => 'png']);
    [$foreign] = $this->callTool($other, 'manage_canva', ['brand_id' => $this->brand->id, 'action' => 'status']);
    $free = Brand::factory()->for($this->user)->create();
    [$disconnected, $message] = $this->callTool($read, 'manage_canva', ['brand_id' => $free->id, 'action' => 'designs']);
    [$noWrite] = $this->callTool($this->mcpKey($this->user, ['canva:write']), 'manage_canva', ['brand_id' => $this->brand->id, 'action' => 'status']);

    expect($denied)->toBeTrue()->and($foreign)->toBeTrue()->and($disconnected)->toBeTrue()->and($message)->toContain('no está conectada')->and($noWrite)->toBeTrue();
});

test('MCP: send sube un recurso de la biblioteca como trabajo y los abilities aparecen en la lista de permisos', function () {
    Queue::fake();
    $media = MediaAsset::factory()->for($this->user)->create(['status' => 'listo', 'kind' => 'image', 'size' => 100]);
    $key = $this->mcpKey($this->user, ['canva:write']);

    [$error, $result] = $this->callTool($key, 'manage_canva', ['brand_id' => $this->brand->id, 'action' => 'send', 'media_id' => $media->id]);

    expect($error)->toBeFalse()->and($result['job']['kind'])->toBe('upload')->and(CanvaJob::withoutUserScope()->where('media_id', $media->id)->exists())->toBeTrue();
    expect(array_keys(Abilities::all()))->toContain('canva:read', 'canva:write');
});

test('API: plantillas de marca con canva:read y Autofill con canva:write; sin la capacidad del plan responde 403 y no llama a Canva', function () {
    $this->connection->forceFill(['scopes' => CANVA_BT_SCOPES, 'capabilities' => ['brand_template', 'autofill']])->save();
    canvaBrandTemplateFakes();
    $read = $this->mcpKey($this->user, ['canva:read']);
    $write = $this->mcpKey($this->user, ['canva:write', 'canva:read']);

    canvaApi($this, $read)->getJson($this->api.'/templates')->assertOk()->assertJsonPath('items.0.id', 'BT123abcdef');
    canvaApi($this, $read)->getJson($this->api.'/templates/BT123abcdef/dataset')->assertOk()->assertJsonPath('fields.0.name', 'CITY');
    canvaApi($this, $read)->postJson($this->api.'/autofills', ['brand_template_id' => 'BT123abcdef', 'fields' => ['CITY' => 'Sydney']])->assertForbidden();
    $job = canvaApi($this, $write)->postJson($this->api.'/autofills', ['brand_template_id' => 'BT123abcdef', 'fields' => ['CITY' => 'Sydney']])->assertStatus(202)->json('job');
    canvaApi($this, $write)->getJson($this->api.'/jobs/'.$job['id'])->assertOk()->assertJsonPath('job.status', 'ready')->assertJsonPath('job.design_id', 'DAFnew00001');

    $this->connection->forceFill(['capabilities' => []])->save();
    canvaApi($this, $read)->getJson($this->api.'/templates')->assertStatus(403)->assertJsonPath('code', 'capability_required');
});

test('MCP: manage_canva templates (lista y campos) y autofill con sus abilities', function () {
    $this->connection->forceFill(['scopes' => CANVA_BT_SCOPES, 'capabilities' => ['brand_template', 'autofill']])->save();
    canvaBrandTemplateFakes();
    $key = $this->mcpKey($this->user, ['canva:read', 'canva:write']);

    [$e1, $list] = $this->callTool($key, 'manage_canva', ['brand_id' => $this->brand->id, 'action' => 'templates']);
    [$e2, $dataset] = $this->callTool($key, 'manage_canva', ['brand_id' => $this->brand->id, 'action' => 'templates', 'template_id' => 'BT123abcdef']);
    [$e3, $autofill] = $this->callTool($key, 'manage_canva', ['brand_id' => $this->brand->id, 'action' => 'autofill', 'template_id' => 'BT123abcdef', 'fields' => ['CITY' => 'Sydney']]);
    [$denied] = $this->callTool($this->mcpKey($this->user, ['canva:read']), 'manage_canva', ['brand_id' => $this->brand->id, 'action' => 'autofill', 'template_id' => 'BT123abcdef', 'fields' => ['CITY' => 'x']]);

    expect([$e1, $e2, $e3])->toBe([false, false, false])->and($list['items'][0]['id'])->toBe('BT123abcdef')->and($dataset['fields'][0])->toMatchArray(['name' => 'CITY', 'supported' => true])
        ->and($autofill['job']['kind'])->toBe('autofill')->and($denied)->toBeTrue();
});
