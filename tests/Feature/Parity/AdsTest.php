<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGraph;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Http::preventStrayRequests();
    Cache::flush();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->fb = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Facebook, 'access_token' => 'PAGE-TOKEN', 'refresh_token' => 'USER-TOKEN', 'scopes' => ['pages_show_list', 'ads_read'],
    ]);
    $this->graph = new FakeGraph;
});

function fakeAds(FakeGraph $g): void
{
    $g->on('GET', '#^me/adaccounts$#', ['data' => [['id' => 'act_111', 'name' => 'Cuenta A', 'currency' => 'EUR', 'account_status' => 1], ['id' => 'act_222', 'name' => 'Cuenta B', 'currency' => 'USD', 'account_status' => 1]]]);
    $g->on('GET', '#^act_\d+/campaigns$#', ['data' => [['id' => '9001', 'name' => 'Rebajas', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_TRAFFIC'], ['id' => '9002', 'name' => 'Sin datos', 'status' => 'PAUSED']]]);
    $g->on('GET', '#^act_\d+/insights$#', ['data' => [['spend' => '12.50', 'impressions' => '1000', 'clicks' => '40', 'ctr' => '4.0', 'cpc' => '0.31', 'campaign_id' => '9001']]]);
}

test('Ads: lista cuentas, campanas e insights con el token de USUARIO en Bearer', function () {
    fakeAds($this->graph);

    $this->actingAs($this->user)->get(route('ads.index'))->assertOk()->assertInertia(fn ($p) => $p->component('Ads/Index')
        ->where('overview.available', true)->where('overview.selected.id', 'act_111')->has('overview.ad_accounts', 2)
        ->where('overview.totals.spend', 12.5)->where('overview.totals.clicks', 40)->where('overview.totals.ctr', 4)->where('overview.totals.cpc', 0.31)
        ->where('overview.campaigns.0.name', 'Rebajas')->where('overview.campaigns.0.insights.impressions', 1000)
        ->where('overview.campaigns.1.insights', null));

    foreach ($this->graph->calls as $call) {
        expect($call['method'])->toBe('GET')->and($call['authorization'])->toBe('Bearer USER-TOKEN')->and($call['url'])->not->toContain('access_token');
    }
    $insight = $this->graph->callsTo('GET', '#insights$#')[0]['params'];
    expect($insight['fields'])->toContain('spend,impressions,clicks,ctr,cpc')
        ->and(json_decode($insight['time_range'], true))->toBe(['since' => '2026-10-03', 'until' => '2026-11-01']);
});

test('Ads: solo lectura (ninguna llamada es distinta de GET) y la cuenta publicitaria elegida debe venir de Meta', function () {
    fakeAds($this->graph);

    $this->actingAs($this->user)->get(route('ads.index', ['ad_account' => 'act_222', 'days' => 7]))->assertInertia(fn ($p) => $p->where('overview.selected.id', 'act_222')->where('overview.period', 7));
    // Un act_ que Meta no devolvio para el usuario cae en la primera cuenta, y un formato invalido se rechaza.
    $this->actingAs($this->user)->get(route('ads.index', ['ad_account' => 'act_999']))->assertInertia(fn ($p) => $p->where('overview.selected.id', 'act_111'));
    $this->actingAs($this->user)->get(route('ads.index', ['ad_account' => '../me']))->assertSessionHasErrors('ad_account');

    expect(collect($this->graph->calls)->pluck('method')->unique()->all())->toBe(['GET']);
    expect(collect($this->graph->calls)->pluck('path')->implode(' '))->not->toContain('act_999');
});

test('Ads: sin el permiso ads_read el estado es no disponible con la instruccion y sin llamadas a Meta', function () {
    $this->fb->forceFill(['scopes' => ['pages_show_list']])->save();

    $this->actingAs($this->user)->get(route('ads.index'))->assertOk()->assertInertia(fn ($p) => $p->where('overview.available', false)->where('overview.reason', 'sin_permiso')
        ->where('overview.message', fn ($m) => str_contains($m, 'ads_read') && str_contains($m, 'vuelve a conectar')));
    expect($this->graph->calls)->toBe([]);
});

test('Ads: Meta rechaza por permisos -> no disponible; credenciales invalidas -> reconectar; caida -> transitorio', function () {
    $this->graph->on('GET', '#^me/adaccounts$#', FakeGraph::seq(
        FakeGraph::error(200, '(#200) Requires ads_read permission', null, 403),
        FakeGraph::error(2, 'Service temporarily unavailable', null, 500),
        FakeGraph::error(190, 'Invalid OAuth access token', null, 401),
    ));

    $this->actingAs($this->user)->get(route('ads.index'))->assertInertia(fn ($p) => $p->where('overview.reason', 'sin_permiso')->where('overview.available', false));
    $this->actingAs($this->user)->get(route('ads.index'))->assertInertia(fn ($p) => $p->where('overview.reason', 'transitorio'));
    $this->actingAs($this->user)->get(route('ads.index'))->assertInertia(fn ($p) => $p->where('overview.reason', 'reconectar'));
    expect($this->fb->fresh()->status)->toBe(SocialAccount::STATUS_RECONNECT);
});

test('Ads: sin cuentas publicitarias se avisa', function () {
    $this->graph->on('GET', '#^me/adaccounts$#', ['data' => []]);
    $this->actingAs($this->user)->get(route('ads.index'))->assertInertia(fn ($p) => $p->where('overview.available', true)->where('overview.selected', null)->has('overview.ad_accounts', 0));
});

test('Ads: las respuestas de Graph se cachean unos minutos por usuario', function () {
    fakeAds($this->graph);
    $this->actingAs($this->user)->get(route('ads.index'));
    expect($this->graph->calls)->toHaveCount(4); // cuentas, campanas, total, por campana

    $this->actingAs($this->user)->get(route('ads.index'));
    expect($this->graph->calls)->toHaveCount(4);
});

test('Ads: aislamiento (la cuenta de otro usuario con ads_read no se usa ni se mezcla en cache)', function () {
    $other = User::factory()->create();
    $ob = Brand::factory()->for($other)->create();
    SocialAccount::factory()->for($other)->create(['brand_id' => $ob->id, 'network' => Network::Facebook, 'refresh_token' => 'OTHER-TOKEN', 'scopes' => ['ads_read']]);
    $this->fb->forceFill(['scopes' => []])->save();
    fakeAds($this->graph);

    $this->actingAs($this->user)->get(route('ads.index'))->assertInertia(fn ($p) => $p->where('overview.available', false));
    expect($this->graph->calls)->toBe([]);

    $this->actingAs($other)->get(route('ads.index'))->assertInertia(fn ($p) => $p->where('overview.available', true));
    expect(collect($this->graph->calls)->pluck('authorization')->unique()->all())->toBe(['Bearer OTHER-TOKEN']);
});

test('Ads: exige sesion', function () {
    $this->get(route('ads.index'))->assertRedirect(route('login'));
});
