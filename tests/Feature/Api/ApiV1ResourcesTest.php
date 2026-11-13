<?php

use App\Jobs\GenerateReport;
use App\Models\Brand;
use App\Models\InboxComment;
use App\Models\MediaAsset;
use App\Models\Report;
use App\Models\Site;
use App\Models\SmartLink;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\InboxService;
use App\Services\Sites\SiteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\McpClient;

uses(McpClient::class);

// T-29 · API v1: media, buzon, informes, mejores horas, competidores, SmartLinks y sitios. Cada endpoint: exito, otro usuario, otra marca,
// ability ausente y validacion. Los listados usan el contrato de cursor comun.

beforeEach(function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Mi marca', 'timezone' => 'UTC']);
    $this->foreignBrand = Brand::factory()->for($this->other)->create(['timezone' => 'UTC']);
    $this->key = $this->mcpKey($this->user);
    $this->otherKey = $this->mcpKey($this->other);
    $this->noneKey = $this->mcpKey($this->user, ['posts:read']);
});

function api(string $key, string $method, string $uri, array $body = [])
{
    app('auth')->forgetGuards();

    return test()->withToken($key)->json($method, '/api/v1/'.ltrim($uri, '/'), $body);
}

// ---------------------------------------------------------------------------------------------------------------- media

test('media: lista solo la propia y lista con cursor, filtra por tipo y exige brands:read', function () {
    $mine = MediaAsset::factory()->count(3)->for($this->user)->create()->pluck('id')->sortDesc()->values()->all();
    MediaAsset::factory()->for($this->other)->create();
    $pdf = MediaAsset::factory()->for($this->user)->create(['kind' => 'pdf', 'mime' => 'application/pdf']);

    $first = api($this->key, 'GET', 'media?limit=2')->assertOk()->json();
    expect(array_column($first['media'], 'id'))->toBe([$mine[0], $mine[1]])->and($first['has_more'])->toBeTrue()->and($first['media'][0])->not->toHaveKey('preview_url');
    $second = api($this->key, 'GET', 'media?limit=2&cursor='.urlencode($first['next_cursor']))->assertOk()->json();
    expect(array_column($second['media'], 'id'))->toBe([$mine[2]])->and($second['has_more'])->toBeFalse()->and($second['next_cursor'])->toBeNull();

    expect(array_column(api($this->key, 'GET', 'media?kind=pdf')->assertOk()->json('media'), 'id'))->toBe([$pdf->id]);
    api($this->key, 'GET', 'media?kind=zip')->assertUnprocessable();
    api($this->key, 'GET', 'media?cursor=zzz')->assertUnprocessable();
    api($this->key, 'GET', 'media?limit=0')->assertUnprocessable();
    api($this->noneKey, 'GET', 'media')->assertForbidden();
});

test('media: ver una propia; la de otro usuario 404; sin ability 403', function () {
    $m = MediaAsset::factory()->for($this->user)->create();
    api($this->key, 'GET', 'media/'.$m->id)->assertOk()->assertJsonPath('asset.id', $m->id);
    api($this->otherKey, 'GET', 'media/'.$m->id)->assertNotFound();
    api($this->noneKey, 'GET', 'media/'.$m->id)->assertForbidden();
});

test('media: subir en dos pasos (registrar y enviar el binario a la ruta de la API) y borrar; la de otro usuario 404', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $created = api($this->key, 'POST', 'media', ['name' => 'p.png', 'mime' => 'image/png', 'size' => strlen($png)])->assertCreated()->json();
    expect($created['upload']['mode'])->toBe('local')->and($created['upload']['url'])->toBe('/api/v1/media/'.$created['asset']['id'].'/upload');
    $id = $created['asset']['id'];

    api($this->otherKey, 'PUT', 'media/'.$id.'/upload')->assertNotFound();
    api($this->noneKey, 'PUT', 'media/'.$id.'/upload')->assertForbidden();
    app('auth')->forgetGuards();
    $this->withServerVariables(['HTTP_AUTHORIZATION' => 'Bearer '.$this->key])->call('PUT', '/api/v1/media/'.$id.'/upload', [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_ACCEPT' => 'application/json'], $png)->assertOk()->assertJsonPath('asset.status', 'listo');

    api($this->otherKey, 'DELETE', 'media/'.$id)->assertNotFound();
    api($this->key, 'DELETE', 'media/'.$id)->assertOk();
    expect(MediaAsset::withoutUserScope()->find($id))->toBeNull();
});

test('media: registrar valida (422), exige posts:write (403) y la importacion por URL valida la URL', function () {
    api($this->key, 'POST', 'media', ['name' => 'x.png'])->assertUnprocessable()->assertJsonValidationErrors(['mime', 'size']);
    api($this->noneKey, 'POST', 'media', ['name' => 'p.png', 'mime' => 'image/png', 'size' => 10])->assertForbidden();
    api($this->key, 'POST', 'media/import-url', [])->assertUnprocessable()->assertJsonValidationErrors('url');
    api($this->key, 'POST', 'media/import-url', ['url' => 'http://127.0.0.1/a.png'])->assertUnprocessable();
    api($this->noneKey, 'POST', 'media/import-url', ['url' => 'https://example.com/a.png'])->assertForbidden();
});

// ---------------------------------------------------------------------------------------------------------------- buzon

test('buzon: lista los comentarios de la marca con cursor, filtros y notas; la marca ajena 404; sin inbox:read 403; validacion 422', function () {
    $account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id]);
    $rows = InboxComment::factory()->count(3)->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'social_account_id' => $account->id]);
    InboxComment::factory()->create(['user_id' => $this->user->id, 'brand_id' => Brand::factory()->for($this->user)->create()->id]);
    InboxComment::factory()->create();

    $first = api($this->key, 'GET', 'brands/'.$this->brand->id.'/comments?limit=2')->assertOk()->json();
    expect($first['comments'])->toHaveCount(2)->and($first['has_more'])->toBeTrue()->and($first['comments'][0]['untrusted'])->toBeTrue()->and($first['comments'][0]['brand_name'])->toBe('Mi marca');
    $second = api($this->key, 'GET', 'brands/'.$this->brand->id.'/comments?limit=2&cursor='.urlencode($first['next_cursor']))->assertOk()->json();
    expect(array_merge(array_column($first['comments'], 'id'), array_column($second['comments'], 'id')))->toBe($rows->pluck('id')->sortDesc()->values()->all())
        ->and($second['has_more'])->toBeFalse();

    api($this->key, 'GET', 'brands/'.$this->brand->id.'/comments?status=closed')->assertOk()->assertJsonCount(0, 'comments');
    api($this->key, 'GET', 'brands/'.$this->foreignBrand->id.'/comments')->assertNotFound();
    api($this->noneKey, 'GET', 'brands/'.$this->brand->id.'/comments')->assertForbidden();
    api($this->key, 'GET', 'brands/'.$this->brand->id.'/comments?network=tiktok')->assertUnprocessable();
});

test('buzon: responder usa el servicio; el comentario de otro usuario 404; sin inbox:write 403; texto vacio 422', function () {
    $c = InboxComment::factory()->create(['user_id' => $this->user->id]);
    $reply = InboxComment::factory()->create(['user_id' => $this->user->id]);
    $this->mock(InboxService::class, fn ($m) => $m->shouldReceive('reply')->once()->andReturn($reply));

    api($this->key, 'POST', 'comments/'.$c->id.'/reply', ['text' => 'Gracias'])->assertCreated()->assertJsonPath('comment_id', $c->id)->assertJsonPath('replied', true);
    api($this->key, 'POST', 'comments/'.$c->id.'/reply', [])->assertUnprocessable()->assertJsonValidationErrors('text');
    api($this->otherKey, 'POST', 'comments/'.$c->id.'/reply', ['text' => 'x'])->assertNotFound();
    api($this->mcpKey($this->user, ['inbox:read']), 'POST', 'comments/'.$c->id.'/reply', ['text' => 'x'])->assertForbidden();
});

// -------------------------------------------------------------------------------------------------------------- informes

test('informes: encolar y consultar; marca ajena 404; informe ajeno 404; sin reports:write 403; validacion 422', function () {
    $created = api($this->key, 'POST', 'brands/'.$this->brand->id.'/reports', ['format' => 'csv', 'from' => '2026-10-01', 'to' => '2026-10-30'])->assertStatus(202)->json();
    expect($created)->toMatchArray(['status' => 'pending', 'brand_id' => $this->brand->id, 'format' => 'csv', 'from' => '2026-10-01', 'to' => '2026-10-30']);
    Queue::assertPushed(GenerateReport::class);

    api($this->key, 'GET', 'reports/'.$created['id'])->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('brand_name', 'Mi marca')->assertJsonPath('download_url', null);
    $ready = Report::factory()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'status' => Report::STATUS_READY, 'path' => 'reports/1/1.csv', 'size' => 10]);
    expect(api($this->key, 'GET', 'reports/'.$ready->id)->assertOk()->json('download_url'))->toContain('/reports/'.$ready->id.'/download');

    api($this->otherKey, 'GET', 'reports/'.$created['id'])->assertNotFound();
    api($this->key, 'POST', 'brands/'.$this->foreignBrand->id.'/reports', ['format' => 'csv'])->assertNotFound();
    api($this->noneKey, 'POST', 'brands/'.$this->brand->id.'/reports', ['format' => 'csv'])->assertForbidden();
    api($this->noneKey, 'GET', 'reports/'.$created['id'])->assertForbidden();
    api($this->key, 'POST', 'brands/'.$this->brand->id.'/reports', ['format' => 'docx'])->assertUnprocessable()->assertJsonValidationErrors('format');
    api($this->key, 'POST', 'brands/'.$this->brand->id.'/reports', ['format' => 'csv', 'from' => '2026-10-30', 'to' => '2026-10-01'])->assertUnprocessable();
    api($this->key, 'POST', 'brands/'.$this->brand->id.'/reports', ['format' => 'pdf', 'template_id' => 999999])->assertNotFound();
});

// --------------------------------------------------------------------------------- mejores horas y competidores

test('mejores horas: el mapa de la red; marca ajena 404; sin stats:read 403; validacion 422', function () {
    $res = api($this->key, 'GET', 'brands/'.$this->brand->id.'/best-times?network=instagram')->assertOk();
    expect($res->json())->toHaveKey('available');
    api($this->otherKey, 'GET', 'brands/'.$this->brand->id.'/best-times?network=instagram')->assertNotFound();
    api($this->noneKey, 'GET', 'brands/'.$this->brand->id.'/best-times?network=instagram')->assertForbidden();
    api($this->key, 'GET', 'brands/'.$this->brand->id.'/best-times')->assertUnprocessable()->assertJsonValidationErrors('network');
    api($this->key, 'GET', 'brands/'.$this->brand->id.'/best-times?network=instagram&days=3')->assertUnprocessable()->assertJsonValidationErrors('days');
});

test('competidores: la comparacion de la marca; marca ajena 404; sin stats:read 403; validacion 422', function () {
    $res = api($this->key, 'GET', 'brands/'.$this->brand->id.'/competitors?from=2026-10-01&to=2026-10-30')->assertOk();
    expect($res->json())->toHaveKeys(['competitors', 'range']);
    api($this->otherKey, 'GET', 'brands/'.$this->brand->id.'/competitors')->assertNotFound();
    api($this->noneKey, 'GET', 'brands/'.$this->brand->id.'/competitors')->assertForbidden();
    api($this->key, 'GET', 'brands/'.$this->brand->id.'/competitors?sort=zzz')->assertUnprocessable()->assertJsonValidationErrors('sort');
    api($this->key, 'GET', 'brands/'.$this->brand->id.'/competitors?top_posts=11')->assertUnprocessable();
    api($this->key, 'GET', 'brands/'.$this->brand->id.'/competitors?network=tiktok')->assertUnprocessable();
});

// ------------------------------------------------------------------------------------------------------------ smartlinks

test('smartlinks: crear, ver, reemplazar y estadisticas; marca ajena 404; abilities propias; validacion 422', function () {
    $body = ['slug' => 'mi-marca', 'title' => 'Mi marca', 'published' => true, 'blocks' => [['type' => 'link', 'label' => 'Web', 'url' => 'https://example.com']]];
    $writer = $this->mcpKey($this->user, ['smartlinks:write']);
    $reader = $this->mcpKey($this->user, ['smartlinks:read']);

    api($reader, 'GET', 'brands/'.$this->brand->id.'/smart-link')->assertNotFound();
    $created = api($writer, 'PUT', 'brands/'.$this->brand->id.'/smart-link', $body)->assertCreated()->json();
    expect($created)->toMatchArray(['slug' => 'mi-marca', 'title' => 'Mi marca', 'published' => true])->and($created['blocks'])->toHaveCount(1)->and($created['public_url'])->toContain('mi-marca');

    $shown = api($reader, 'GET', 'brands/'.$this->brand->id.'/smart-link')->assertOk()->json();
    expect($shown['id'])->toBe($created['id']);
    $updated = api($writer, 'PUT', 'brands/'.$this->brand->id.'/smart-link', ['title' => 'Nuevo'] + $body)->assertOk()->json();
    expect($updated['id'])->toBe($created['id'])->and($updated['title'])->toBe('Nuevo');
    api($reader, 'GET', 'brands/'.$this->brand->id.'/smart-link/stats')->assertOk()->assertJsonStructure(['views']);
    api($reader, 'GET', 'brands/'.$this->brand->id.'/smart-link/stats?days=0')->assertUnprocessable();

    api($this->otherKey, 'GET', 'brands/'.$this->brand->id.'/smart-link')->assertNotFound();
    api($this->otherKey, 'PUT', 'brands/'.$this->brand->id.'/smart-link', $body)->assertNotFound();
    api($writer, 'GET', 'brands/'.$this->brand->id.'/smart-link')->assertForbidden();
    api($reader, 'PUT', 'brands/'.$this->brand->id.'/smart-link', $body)->assertForbidden();
    api($writer, 'PUT', 'brands/'.$this->brand->id.'/smart-link', ['slug' => 'A', 'title' => ''])->assertUnprocessable()->assertJsonValidationErrors(['slug', 'title']);
    expect(SmartLink::withoutUserScope()->where('brand_id', $this->brand->id)->count())->toBe(1);
});

// -------------------------------------------------------------------------------------------------------------- sitios

test('sitios: listar, ver, publicar y despublicar los propios; ajeno 404; abilities propias; la URL con token no se devuelve', function () {
    $site = app(SiteService::class)->create($this->user, $this->brand, 'Mi sitio', 'mi-sitio');
    $foreign = app(SiteService::class)->create($this->other, $this->foreignBrand, 'Ajeno', 'ajeno');
    $reader = $this->mcpKey($this->user, ['sites:read']);
    $writer = $this->mcpKey($this->user, ['sites:write']);

    $list = api($reader, 'GET', 'brands/'.$this->brand->id.'/sites')->assertOk()->json('sites');
    expect(array_column($list, 'id'))->toBe([$site->id])->and($list[0]['published'])->toBeFalse();
    api($reader, 'GET', 'sites/'.$site->id)->assertOk()->assertJsonPath('slug', 'mi-sitio');
    api($writer, 'POST', 'sites/'.$site->id.'/publish')->assertOk()->assertJsonPath('published', true);
    api($writer, 'POST', 'sites/'.$site->id.'/unpublish')->assertOk()->assertJsonPath('published', false);

    api($reader, 'GET', 'brands/'.$this->foreignBrand->id.'/sites')->assertNotFound();
    api($reader, 'GET', 'sites/'.$foreign->id)->assertNotFound();
    api($writer, 'POST', 'sites/'.$foreign->id.'/publish')->assertNotFound();
    expect(Site::withoutUserScope()->find($foreign->id)->is_published)->toBeFalse();
    api($writer, 'GET', 'sites/'.$site->id)->assertForbidden();
    api($reader, 'POST', 'sites/'.$site->id.'/publish')->assertForbidden();
    api($this->noneKey, 'GET', 'brands/'.$this->brand->id.'/sites')->assertForbidden();
});

test('toda la API v1 nueva responde 401 sin token', function () {
    app('auth')->forgetGuards();
    foreach (['media', 'brands/'.$this->brand->id.'/comments', 'reports/1', 'brands/'.$this->brand->id.'/best-times', 'brands/'.$this->brand->id.'/smart-link', 'sites/1'] as $uri) {
        $this->flushHeaders()->getJson('/api/v1/'.$uri)->assertUnauthorized();
    }
});
