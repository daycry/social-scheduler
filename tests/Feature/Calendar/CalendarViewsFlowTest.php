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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\McpClient;

uses(McpClient::class);

// C-03a · CA-11 de extremo a extremo: guardar «Pendientes IG» y elegirla desde otro filtro aplica los tres filtros, en web y MCP.

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-10-25 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Alfa', 'timezone' => 'Europe/Madrid']);
    $this->other = Brand::factory()->for($this->user)->create(['name' => 'Beta', 'timezone' => 'Europe/Madrid']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->igOther = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->other->id, 'network' => Network::Instagram]);
    $image = MediaAsset::factory()->for($this->user)->create();
    $mk = fn ($brand, $account, $type, $text, $at) => app(PostService::class)->create($this->user, $brand, [
        'text' => $text, 'schedule' => true, 'scheduled_at' => $at, 'media' => $type === 'facebook_text' ? [] : [$image->id],
        'targets' => [['account_id' => $account->id, 'content_type' => $type]],
    ]);
    $mk($this->brand, $this->ig, 'instagram_image', 'IG alfa', '2026-11-03 10:00');
    $mk($this->brand, $this->fb, 'facebook_text', 'FB alfa', '2026-11-03 11:00');
    $mk($this->other, $this->igOther, 'instagram_image', 'IG beta', '2026-11-03 12:00');
    $this->key = $this->mcpKey($this->user);
});

test('CA-11: guardar «Pendientes IG» y elegirla desde otro filtro aplica marca, red y estado', function () {
    $this->actingAs($this->user)->post(route('calendar-views.store'), [
        'name' => 'Pendientes IG', 'filters' => ['brand_id' => $this->brand->id, 'network' => 'instagram', 'status' => 'programada', 'view' => 'week'],
    ])->assertRedirect();
    $view = CalendarView::withoutUserScope()->where('name', 'Pendientes IG')->firstOrFail();

    // Otro filtro distinto (Facebook de Beta)…
    $this->actingAs($this->user)->get(route('calendar.index', ['brand_id' => $this->other->id, 'network' => 'facebook', 'date' => '2026-11-03']))
        ->assertInertia(fn (Assert $p) => $p->has('items', 0)->where('filters.network', 'facebook'));

    // …y al elegir la vista se reaplican los tres filtros.
    $this->actingAs($this->user)->get(route('calendar.index', ['saved_view' => $view->id, 'date' => '2026-11-03']))
        ->assertInertia(fn (Assert $p) => $p->where('active_view', $view->id)
            ->where('filters.brand_id', $this->brand->id)->where('filters.network', 'instagram')->where('filters.status', 'programada')
            ->has('items', 1)->where('items.0.text', 'IG alfa'));
});

test('CA-11: get_calendar con view devuelve lo mismo que la web con esa vista', function () {
    $view = CalendarView::factory()->create(['user_id' => $this->user->id, 'name' => 'Pendientes IG', 'filters' => ['brand_id' => $this->brand->id, 'network' => 'instagram', 'status' => 'programada', 'view' => 'week']]);

    [$err, $mcp] = $this->callTool($this->key, 'get_calendar', ['view' => (string) $view->id, 'date' => '2026-11-03']);
    $web = $this->actingAs($this->user)->get(route('calendar.index', ['saved_view' => $view->id, 'date' => '2026-11-03']))->inertiaProps();

    expect($err)->toBeFalse()->and($mcp['items'])->toHaveCount(1)
        ->and(collect($mcp['items'])->pluck('id')->all())->toBe(collect($web['items'])->pluck('id')->all())
        ->and($mcp['from'])->toBe($web['from'])->and($mcp['view'])->toBe($web['view']);
});

test('el calendario sin vistas ni filtros sigue igual (regresión de CalendarTest)', function () {
    $this->actingAs($this->user)->get(route('calendar.index', ['date' => '2026-11-03']))
        ->assertInertia(fn (Assert $p) => $p->has('items', 3)->where('views', [])->where('active_view', null));
});
