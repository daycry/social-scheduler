<?php

use App\Models\Canva\CanvaJob;
use App\Services\Canva\CanvaCapabilities;
use App\Services\Canva\CanvaJobs;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/CanvaTestSupport.php';

// T-27 · Brand Templates y Autofill siguen las capacidades del plan de Canva (`/users/me/capabilities`) y los permisos concedidos, y el flujo de
// Autofill sigue la API (plantilla -> campos -> trabajo asincrono -> diseño). Http::fake: ninguna peticion real a Canva.
// Contrato: https://www.canva.dev/docs/apps/rest-apis/autofill-guide/ (2026-11-12).

beforeEach(function () {
    canvaEnvironment();
    [$this->user, $this->brand] = canvaUserAndBrand();
});

function canvaFeature(array $caps, array $scopes, string $key): array
{
    return collect(CanvaCapabilities::features($caps, $scopes))->firstWhere('key', $key);
}

test('Brand Templates y Autofill solo se ofrecen si el plan tiene la capacidad Y la conexión los permisos; cada caso explica el motivo', function () {
    $planReason = fn (array $f) => $f['reason'];

    $none = canvaFeature([], CANVA_BT_SCOPES, 'brand_templates');
    expect($none['available'])->toBeFalse()->and($planReason($none))->toContain('plan de Canva')->not->toContain('todavía no lo usa');

    expect(canvaFeature(['brand_template'], CANVA_BT_SCOPES, 'brand_templates')['available'])->toBeTrue()
        ->and(canvaFeature(['brand_template'], CANVA_BT_SCOPES, 'autofill')['available'])->toBeFalse() // Autofill exige ademas su capacidad
        ->and(canvaFeature(['brand_template', 'autofill'], CANVA_BT_SCOPES, 'autofill')['available'])->toBeTrue()
        ->and(canvaFeature(['autofill'], CANVA_BT_SCOPES, 'autofill')['available'])->toBeFalse() // y la de plantillas de marca
        ->and(canvaFeature(['brand_template', 'autofill'], CANVA_BT_SCOPES, 'autofill')['reason'])->toBeNull();

    $noScope = canvaFeature(['brand_template', 'autofill'], ['design:content:write'], 'autofill');
    expect($noScope['available'])->toBeFalse()->and($noScope['reason'])->toContain('brandtemplate:meta:read');
});

test('si la instalación no pide los permisos de plantillas, el motivo lo dice (no sirve reconectar)', function () {
    config(['services.canva.scopes' => ['profile:read', 'design:meta:read']]);

    $feature = canvaFeature(['brand_template'], ['profile:read'], 'brand_templates');

    expect($feature['available'])->toBeFalse()->and($feature['reason'])->toContain('CANVA_BRAND_TEMPLATES');
});

test('el estado de la API refleja las capacidades reales del plan, no un false fijo', function () {
    canvaConnect($this->user, $this->brand, CANVA_BT_SCOPES, ['brand_template', 'autofill']);

    $features = collect($this->actingAs($this->user)->getJson(route('canva.status', $this->brand))->assertOk()->json('features'))->keyBy('key');

    expect($features['brand_templates']['available'])->toBeTrue()->and($features['autofill']['available'])->toBeTrue()->and($features)->not->toHaveKey('templates');
});

test('sin la capacidad del plan no se consulta ni se rellena nada en Canva', function () {
    canvaConnect($this->user, $this->brand, CANVA_BT_SCOPES, ['export_png_transparency']);
    canvaBrandTemplateFakes();

    $this->actingAs($this->user)->getJson(route('canva.templates.index', $this->brand))->assertStatus(403)->assertJsonPath('code', 'capability_required');
    $this->actingAs($this->user)->getJson(route('canva.templates.dataset', [$this->brand, 'BT123abcdef']))->assertStatus(403);
    $this->actingAs($this->user)->postJson(route('canva.autofills.store', $this->brand), ['brand_template_id' => 'BT123abcdef', 'fields' => ['CITY' => 'Sydney']])->assertStatus(403)->assertJsonPath('code', 'capability_required');

    Http::assertNothingSent();
    expect(CanvaJob::withoutUserScope()->count())->toBe(0);
});

test('con la capacidad: lista las plantillas de marca y sus campos, marcando cuáles se pueden rellenar con texto', function () {
    canvaConnect($this->user, $this->brand, CANVA_BT_SCOPES, ['brand_template', 'autofill']);
    canvaBrandTemplateFakes();

    $list = $this->actingAs($this->user)->getJson(route('canva.templates.index', [$this->brand, 'query' => 'ciudad']))->assertOk()->json();
    expect($list['items'][0])->toMatchArray(['id' => 'BT123abcdef', 'title' => 'Ciudad del día', 'view_url' => 'https://www.canva.com/brand/t/view'])
        ->and($list['items'][0]['thumbnail'])->toBe('https://document-export.canva.com/t.png')->and($list['continuation'])->toBe('tok');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/brand-templates?') && $r['query'] === 'ciudad' && $r->hasHeader('Authorization', 'Bearer at-live'));

    $fields = $this->actingAs($this->user)->getJson(route('canva.templates.dataset', [$this->brand, 'BT123abcdef']))->assertOk()->json('fields');
    expect($fields)->toBe([
        ['name' => 'CITY', 'type' => 'text', 'supported' => true],
        ['name' => 'BACKGROUND', 'type' => 'image', 'supported' => false],
        ['name' => 'SALES', 'type' => 'chart', 'supported' => false],
    ]);
    $this->actingAs($this->user)->getJson(route('canva.templates.dataset', [$this->brand, 'no valido!']))->assertNotFound();
});

test('flujo de Autofill: crea el trabajo, sondea y deja el diseño nuevo; solo se envían los campos de texto rellenados', function () {
    canvaConnect($this->user, $this->brand, CANVA_BT_SCOPES, ['brand_template', 'autofill']);
    canvaBrandTemplateFakes([
        ['job' => ['id' => 'af-1', 'status' => 'in_progress']],
        ['job' => ['id' => 'af-1', 'status' => 'success', 'result' => ['type' => 'create_design', 'design' => ['id' => 'DAFnew00001', 'title' => 'Ciudad de hoy', 'urls' => ['edit_url' => 'https://www.canva.com/api/design/t/edit']]]]],
    ]);

    $res = $this->actingAs($this->user)->postJson(route('canva.autofills.store', $this->brand), [
        'brand_template_id' => 'BT123abcdef', 'title' => 'Ciudad de hoy', 'fields' => ['CITY' => 'Sydney', 'TEMPERATURE' => ''],
    ])->assertStatus(202)->assertJsonPath('job.kind', 'autofill');

    $job = $this->actingAs($this->user)->getJson(route('canva.jobs.show', [$this->brand, $res->json('job.id')]))->assertOk()->json('job');
    expect($job['status'])->toBe('ready')->and($job['design_id'])->toBe('DAFnew00001')->and($job['title'])->toBe('Ciudad de hoy');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rest/v1/autofills') && $r['brand_template_id'] === 'BT123abcdef' && $r['title'] === 'Ciudad de hoy'
        && $r['data'] === ['CITY' => ['type' => 'text', 'text' => 'Sydney']]);
});

test('Autofill: campos inválidos, plantilla inválida y trabajos de más se rechazan antes de llamar a Canva', function (array $body) {
    canvaConnect($this->user, $this->brand, CANVA_BT_SCOPES, ['brand_template', 'autofill']);
    canvaBrandTemplateFakes();

    $this->actingAs($this->user)->postJson(route('canva.autofills.store', $this->brand), $body)->assertStatus(422);

    Http::assertNothingSent();
    expect(CanvaJob::withoutUserScope()->count())->toBe(0);
})->with([
    'sin campos' => [['brand_template_id' => 'BT123abcdef', 'fields' => []]],
    'todos vacíos' => [['brand_template_id' => 'BT123abcdef', 'fields' => ['CITY' => '   ']]],
    'plantilla inválida' => [['brand_template_id' => 'no valido!', 'fields' => ['CITY' => 'x']]],
    'valor no texto' => [['brand_template_id' => 'BT123abcdef', 'fields' => ['CITY' => ['a']]]],
    'valor enorme' => [['brand_template_id' => 'BT123abcdef', 'fields' => ['CITY' => str_repeat('a', 2001)]]],
]);

test('Autofill: un fallo de Canva deja el trabajo en error con un mensaje claro y sin el cuerpo del proveedor', function () {
    $connection = canvaConnect($this->user, $this->brand, CANVA_BT_SCOPES, ['brand_template', 'autofill']);
    canvaBrandTemplateFakes([['job' => ['id' => 'af-1', 'status' => 'failed', 'error' => ['code' => 'autofill_error', 'message' => 'secreto-interno']]]]);

    $job = app(CanvaJobs::class)->startAutofill($this->user, $this->brand, $connection, 'BT123abcdef', ['CITY' => 'Sydney'], null);
    $present = app(CanvaJobs::class)->present(CanvaJob::withoutUserScope()->findOrFail($job->id));

    expect($present['status'])->toBe('failed')->and($present['error'])->toContain('plantilla')->not->toContain('secreto-interno');
});
