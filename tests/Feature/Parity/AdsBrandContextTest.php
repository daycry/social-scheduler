<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    $this->user = User::factory()->create();
    $this->brandA = Brand::factory()->for($this->user)->create(['name' => 'A']);
    $this->brandB = Brand::factory()->for($this->user)->create(['name' => 'B']);
    $this->accountA = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brandA->id, 'network' => Network::Facebook, 'refresh_token' => 'TOKEN-A', 'scopes' => ['ads_read']]);
    $this->accountB = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brandB->id, 'network' => Network::Facebook, 'refresh_token' => 'TOKEN-B', 'scopes' => ['ads_read']]);
    Http::fake(function ($request) {
        $isA = ($request->header('Authorization')[0] ?? '') === 'Bearer TOKEN-A';
        $url = $request->url();
        if (str_contains($url, '/me/adaccounts')) {
            return Http::response(['data' => [['id' => $isA ? 'act_111' : 'act_222', 'name' => $isA ? 'Ads A' : 'Ads B', 'currency' => 'EUR']]]);
        }
        if (str_contains($url, '/campaigns')) {
            return Http::response(['data' => [['id' => $isA ? '1' : '2', 'name' => $isA ? 'Camp-A' : 'Camp-B']]]);
        }
        if (str_contains($url, '/insights')) {
            return Http::response(['data' => [['campaign_id' => $isA ? '1' : '2', 'spend' => $isA ? '111.5' : '222.5', 'impressions' => '10', 'clicks' => '1']]]);
        }

        return Http::response(['data' => []]);
    });
});

test('Ads uses the selected brand instead of the latest Meta account of the user', function () {
    $this->actingAs($this->user)->get(route('ads.index', ['brand_id' => $this->brandA->id]))
        ->assertOk()->assertInertia(fn ($page) => $page->where('overview.selected.id', 'act_111'));
    Http::assertSent(fn ($request) => ($request->header('Authorization')[0] ?? '') === 'Bearer TOKEN-A');
    Http::assertNotSent(fn ($request) => ($request->header('Authorization')[0] ?? '') === 'Bearer TOKEN-B');
});

test('Ads never borrows another brand credentials when the selected brand has no permission', function () {
    $this->accountA->update(['scopes' => []]);
    $this->actingAs($this->user)->get(route('ads.index', ['brand_id' => $this->brandA->id]))
        ->assertOk()->assertInertia(fn ($page) => $page->where('overview.available', false)->where('overview.reason', 'sin_permiso'));
    Http::assertNothingSent();
});

test('Ads refuses an explicit foreign brand instead of silently using a personal account', function () {
    $foreign = Brand::factory()->create();
    $this->actingAs($this->user)->get(route('ads.index', ['brand_id' => $foreign->id]))->assertNotFound();
    Http::assertNothingSent();
});

test('Ads caches separately for each selected brand and reuses only that brands responses', function () {
    $this->actingAs($this->user)->get(route('ads.index', ['brand_id' => $this->brandA->id]))
        ->assertInertia(fn ($page) => $page->where('overview.selected.id', 'act_111'));
    $this->get(route('ads.index', ['brand_id' => $this->brandB->id]))
        ->assertInertia(fn ($page) => $page->where('overview.selected.id', 'act_222'));
    Http::assertSentCount(8);
    $this->get(route('ads.index', ['brand_id' => $this->brandA->id]));
    Http::assertSentCount(8);
});

test('Ads refreshes cached access when the source credentials change', function () {
    $this->actingAs($this->user)->get(route('ads.index', ['brand_id' => $this->brandA->id]));
    Http::assertSentCount(4);
    $this->accountA->update(['refresh_token' => 'TOKEN-ROTATED']);
    $this->get(route('ads.index', ['brand_id' => $this->brandA->id]));
    Http::assertSent(fn ($request) => ($request->header('Authorization')[0] ?? '') === 'Bearer TOKEN-ROTATED');
});

test('switching brand never shows campaigns, totals or ad accounts of the other brand, even with a stale ad account filter', function () {
    $this->actingAs($this->user)->get(route('ads.index', ['brand_id' => $this->brandA->id]))
        ->assertInertia(fn ($page) => $page->where('overview.campaigns.0.name', 'Camp-A')->where('overview.totals.spend', 111.5));

    // La cuenta act_111 (de A) llega en la query de B: B solo puede usar las cuentas que Meta devolvio para B.
    $response = $this->get(route('ads.index', ['brand_id' => $this->brandB->id, 'ad_account' => 'act_111']));
    $response->assertInertia(fn ($page) => $page
        ->where('brand.id', $this->brandB->id)
        ->where('overview.selected.id', 'act_222')
        ->where('overview.ad_accounts.0.id', 'act_222')
        ->where('overview.campaigns.0.name', 'Camp-B')
        ->where('overview.campaigns.0.insights.spend', 222.5)->where('overview.totals.spend', 222.5));
    expect(json_encode($response->viewData('page')['props']['overview']))->not->toContain('Camp-A')->not->toContain('act_111')->not->toContain('Ads A');
});
