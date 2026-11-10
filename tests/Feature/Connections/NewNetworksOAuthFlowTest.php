<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\NewNetworksFake;

// T-18: flujo OAuth completo de LinkedIn y Pinterest por el controlador real (state por intento, un solo uso, cifrado en reposo).
// Los proveedores se simulan con Http::fake: esto NO acredita la autorizacion real de LinkedIn ni de Pinterest.

beforeEach(function () {
    Http::preventStrayRequests();
    NewNetworksFake::linkedin();
    NewNetworksFake::pinterest();
    app()->forgetInstance(ConnectorRegistry::class);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->other = Brand::factory()->for($this->user)->create();
    $this->back = route('brands.connections.index', $this->brand, false);
});

function nnStart(string $provider, Brand $brand): string
{
    test()->actingAs(test()->user)->post(route('brands.connections.connect', [$brand, $provider], false));

    return session("oauth.$provider.state");
}

test('LinkedIn: autoriza con state propio, un solo uso, conecta el perfil con token cifrado y marca', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'LI-SECRET-TOKEN', 'expires_in' => 5184000, 'scope' => 'openid,profile,w_member_social']),
        'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'abc123', 'name' => 'Ana Lopez']),
        'api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => []]),
    ]);
    $state = nnStart('linkedin', $this->brand);
    expect($state)->toHaveLength(40);

    $this->get('/connections/linkedin/callback?code=CODE&state=otro')->assertRedirect(route('brands.index', absolute: false));
    $this->get('/connections/linkedin/callback?code=CODE&state='.$state)->assertRedirect($this->back);
    $this->get('/connections/linkedin/callback?code=CODE&state='.$state)->assertRedirect(route('brands.index', absolute: false));

    $account = SocialAccount::withoutUserScope()->where('network', Network::LinkedIn)->sole();
    expect($account->brand_id)->toBe($this->brand->id)->and($account->external_id)->toBe('abc123')->and($account->access_token)->toBe('LI-SECRET-TOKEN')
        ->and($account->meta['author_urn'])->toBe('urn:li:person:abc123');
    expect(DB::table('social_accounts')->where('id', $account->id)->value('access_token'))->not->toContain('LI-SECRET-TOKEN');
});

test('Pinterest: dos intentos de dos marcas no se pisan y cada callback conecta en la suya', function () {
    Http::fake([
        'api.pinterest.com/v5/oauth/token' => Http::response(['access_token' => 'PI-TOKEN', 'refresh_token' => 'PI-REFRESH', 'expires_in' => 2592000]),
        'api.pinterest.com/v5/user_account' => Http::response(['username' => 'ana_pins']),
        'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => '5', 'name' => 'Recetas']]]),
    ]);
    $first = nnStart('pinterest', $this->brand);
    $second = nnStart('pinterest', $this->other);
    expect($first)->not->toBe($second);

    $this->get('/connections/pinterest/callback?code=C1&state='.$first)->assertRedirect($this->back);
    $this->get('/connections/pinterest/callback?code=C2&state='.$second)->assertRedirect(route('brands.connections.index', $this->other, false));

    $accounts = SocialAccount::withoutUserScope()->where('network', Network::Pinterest)->get();
    expect($accounts->pluck('brand_id')->sort()->values()->all())->toBe(collect([$this->brand->id, $this->other->id])->sort()->values()->all())
        ->and($accounts->first()->meta['boards'])->toBe([['id' => '5', 'name' => 'Recetas']]);
});

test('un rechazo del usuario en LinkedIn no conecta nada y consume el intento', function () {
    $state = nnStart('linkedin', $this->brand);

    $this->get('/connections/linkedin/callback?error=user_cancelled_authorize&state='.$state)->assertRedirect($this->back);
    $this->get('/connections/linkedin/callback?code=CODE&state='.$state)->assertRedirect(route('brands.index', absolute: false));

    expect(SocialAccount::withoutUserScope()->where('network', Network::LinkedIn)->count())->toBe(0);
});
