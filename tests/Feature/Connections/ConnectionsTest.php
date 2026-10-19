<?php

use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAccountService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Aipocalipsis']);
    $this->url = route('brands.connections.index', $this->brand, false);
});

test('Connections page lists each account with its status and never exposes tokens', function () {
    $ok = SocialAccount::factory()->for($this->brand)->create(['network' => Network::Instagram, 'name' => 'aipocalipsis_oficial', 'access_token' => 'SECRET-ACCESS-TOKEN']);
    $bad = SocialAccount::factory()->for($this->brand)->network(Network::YouTube)->requiresReconnection()->create(['name' => 'Jordi daycry']);

    $response = $this->actingAs($this->user)->get($this->url);

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Brands/Connections')
        ->where('brand.name', 'Aipocalipsis')
        ->has('accounts', 2)
        ->where('accounts.0.network', 'instagram')
        ->where('accounts.0.status', 'activa')
        ->where('accounts.1.network', 'youtube')
        ->where('accounts.1.status', 'requiere_reconexion')
        ->where('accounts.1.provider', 'google')
        ->has('providers', 4)
        ->where('providers.0.available', true)
        ->where('pending', null));

    expect($response->getContent())->not->toContain('SECRET-ACCESS-TOKEN')->not->toContain($ok->refresh_token);
});

test('Connections page shows an empty state for a brand without accounts', function () {
    $this->actingAs($this->user)->get($this->url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('accounts', 0));
});

test('Connections disconnect removes the tokens from the database and marks the account disconnected', function () {
    $account = SocialAccount::factory()->for($this->brand)->create();

    $this->actingAs($this->user)
        ->delete(route('brands.connections.destroy', [$this->brand, $account], false))
        ->assertRedirect($this->url);

    $raw = DB::table('social_accounts')->where('id', $account->id)->first();
    expect($raw->status)->toBe('desconectada')->and($raw->access_token)->toBeNull()->and($raw->refresh_token)->toBeNull();
});

test('Connections reconnect keeps the account row and returns it to active status', function () {
    $account = SocialAccount::factory()->for($this->brand)->requiresReconnection()->create(['external_id' => '555']);
    $candidate = new ConnectionCandidate(Network::Instagram, '555', 'Nuevo nombre', 'u', 'NEW-ACCESS', 'NEW-REFRESH');

    app(SocialAccountService::class)->connect($this->user, $this->brand, $candidate);

    $account->refresh();
    expect(SocialAccount::withoutUserScope()->count())->toBe(1)
        ->and($account->status)->toBe('activa')->and($account->last_error)->toBeNull()->and($account->access_token)->toBe('NEW-ACCESS');
});

test('Connections select ignores keys that were not offered and needs a pending connection', function () {
    $this->actingAs($this->user)
        ->post(route('brands.connections.select', $this->brand, false), ['keys' => ['facebook:1']])
        ->assertRedirect($this->url);

    expect(session('error'))->toContain('No hay una conexion pendiente')->and(SocialAccount::count())->toBe(0);
});

test('Connections a user cannot see, connect or disconnect on another user brand', function () {
    $intruder = User::factory()->create();
    $account = SocialAccount::factory()->for($this->brand)->create();

    $this->actingAs($intruder)->get($this->url)->assertNotFound();
    $this->actingAs($intruder)->delete(route('brands.connections.destroy', [$this->brand, $account], false))->assertNotFound();
    $this->actingAs($intruder)->post(route('brands.connections.select', $this->brand, false), ['keys' => ['facebook:1']])->assertNotFound();

    expect($account->fresh()->status)->toBe('activa')->and($account->fresh()->access_token)->not->toBeNull();
});

test('Connections an account of another brand of the same user is not disconnectable through this brand', function () {
    $other = Brand::factory()->for($this->user)->create();
    $account = SocialAccount::factory()->for($other)->create();

    $this->actingAs($this->user)
        ->delete(route('brands.connections.destroy', [$this->brand, $account], false))
        ->assertNotFound();

    expect($account->fresh()->isActive())->toBeTrue();
});

test('Connections routes require authentication', function () {
    $this->get($this->url)->assertRedirect('/login');
    $this->get('/connections/meta/callback?code=x&state=y')->assertRedirect('/login');
});

test('Connections accounts from other brands and users never appear on the page', function () {
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    SocialAccount::factory()->for($otherBrand)->create(['name' => 'AJENA']);
    SocialAccount::factory()->for(Brand::factory()->for($this->user)->create())->create(['name' => 'OTRA MARCA']);

    $response = $this->actingAs($this->user)->get($this->url);

    expect($response->getContent())->not->toContain('AJENA')->not->toContain('OTRA MARCA');
});
