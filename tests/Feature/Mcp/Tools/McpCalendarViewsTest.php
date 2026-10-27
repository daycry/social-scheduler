<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\CalendarView;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpClient;

uses(McpClient::class);

// C-03a · CA-11 por MCP: list_calendar_views y get_calendar view=<id>.

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-10-25 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Alfa', 'timezone' => 'Europe/Madrid']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->image = MediaAsset::factory()->for($this->user)->create();
    app(PostService::class)->create($this->user, $this->brand, ['text' => 'IG', 'schedule' => true, 'scheduled_at' => '2026-11-03 10:00', 'media' => [$this->image->id], 'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_image']]]);
    app(PostService::class)->create($this->user, $this->brand, ['text' => 'FB', 'schedule' => true, 'scheduled_at' => '2026-11-03 11:00', 'media' => [], 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']]]);
    $this->key = $this->mcpKey($this->user);
});

test('list_calendar_views devuelve solo las vistas del usuario con sus filtros', function () {
    CalendarView::factory()->create(['user_id' => $this->user->id, 'name' => 'Pendientes IG', 'is_default' => true, 'filters' => ['brand_id' => $this->brand->id, 'network' => 'instagram']]);
    CalendarView::factory()->create(['name' => 'Ajena']);

    [$err, $res] = $this->callTool($this->key, 'list_calendar_views');

    expect($err)->toBeFalse()->and($res['views'])->toHaveCount(1)
        ->and($res['views'][0])->toMatchArray(['name' => 'Pendientes IG', 'is_default' => true, 'brand_name' => 'Alfa'])
        ->and($res['views'][0]['filters'])->toBe(['brand_id' => $this->brand->id, 'network' => 'instagram']);
});

test('get_calendar view=<id> devuelve lo mismo que los filtros equivalentes', function () {
    $v = CalendarView::factory()->create(['user_id' => $this->user->id, 'filters' => ['brand_id' => $this->brand->id, 'network' => 'instagram', 'view' => 'week']]);

    [, $viaView] = $this->callTool($this->key, 'get_calendar', ['view' => (string) $v->id, 'date' => '2026-11-03']);
    [, $viaFilters] = $this->callTool($this->key, 'get_calendar', ['view' => 'week', 'date' => '2026-11-03', 'brand_id' => $this->brand->id, 'network' => 'instagram']);

    expect($viaView['items'])->toHaveCount(1)->and($viaView['items'][0]['text'])->toBe('IG')->and($viaView)->toBe($viaFilters);
});

test('get_calendar sigue aceptando week, month y list y rechaza otros valores', function () {
    [$e1, $month] = $this->callTool($this->key, 'get_calendar', ['view' => 'month', 'date' => '2026-11-03']);
    [$e2] = $this->callTool($this->key, 'get_calendar', ['view' => 'year']);

    expect($e1)->toBeFalse()->and($month['view'])->toBe('month')->and($e2)->toBeTrue();
});

test('una vista de otro usuario responde como inexistente', function () {
    $foreign = CalendarView::factory()->create();

    [$err, $msg] = $this->callTool($this->key, 'get_calendar', ['view' => (string) $foreign->id]);
    [$errMissing, $msgMissing] = $this->callTool($this->key, 'get_calendar', ['view' => '999999']);

    expect($err)->toBeTrue()->and($errMissing)->toBeTrue()->and($msg)->toBe($msgMissing);
});

test('list_calendar_views exige posts:read', function () {
    $key = $this->mcpKey($this->user, ['brands:read'], 'sin-posts');

    [$err, $msg] = $this->callTool($key, 'list_calendar_views');

    expect($err)->toBeTrue()->and($msg)->toContain('posts:read');
});

test('tools/list ofrece 24 herramientas en una sola página', function () {
    $response = $this->rpc($this->key, 'tools/list');
    $names = collect($response->json('result.tools'))->pluck('name');

    expect($names)->toHaveCount(24)->toContain('list_calendar_views')->and($response->json('result.nextCursor'))->toBeNull();
});
