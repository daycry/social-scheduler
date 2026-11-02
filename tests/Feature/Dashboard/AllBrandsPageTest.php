<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
});

function allBrandsSnap(User $user, Brand $brand, Network $network, string $date, array $metrics): void
{
    $a = SocialAccount::query()->withoutGlobalScopes()->where('brand_id', $brand->id)->where('network', $network->value)->first()
        ?? SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => $network]);
    AccountSnapshot::withoutUserScope()->create([
        'user_id' => $user->id, 'brand_id' => $brand->id, 'social_account_id' => $a->id, 'network' => $network->value,
        'date' => $date, 'metrics' => array_merge(Metric::blankAccount(), $metrics),
    ]);
}

it('Todas las marcas: la pagina entrega totales, fila por marca y serie (CA-12)', function () {
    $a = Brand::factory()->for($this->user)->create(['name' => 'Alfa']);
    $b = Brand::factory()->for($this->user)->create(['name' => 'Beta']);
    allBrandsSnap($this->user, $a, Network::Instagram, '2026-10-30', [Metric::VIEWS => 10, Metric::FOLLOWERS => 100]);
    allBrandsSnap($this->user, $b, Network::Bluesky, '2026-10-30', [Metric::FOLLOWERS => 5]);

    $this->actingAs($this->user)->get(route('dashboard.all', ['from' => '2026-10-25', 'to' => '2026-10-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Dashboard/AllBrands')
            ->where('summary.cards.0.key', 'followers')->where('summary.cards.0.total', 105)
            ->where('summary.cards.1.total', 10)
            ->where('summary.cards.2.total', null)->where('summary.cards.2.available', false)
            ->has('summary.brands', 2)->where('summary.brands.1.values.views', null)
            ->has('summary.cards.1.series', 7)
            ->where('filters.from', '2026-10-25'));
});

it('Todas las marcas: sin marcas muestra la pantalla vacia y un periodo invalido da 422', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard.all'))
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('Dashboard/AllBrands')->where('summary', null));
    $this->actingAs($this->user)->get(route('dashboard.all', ['from' => '2026-10-10', 'to' => '2026-10-01']))->assertStatus(422);
    $this->actingAs($this->user)->get(route('dashboard.all', ['compare' => 'otro']))->assertSessionHasErrors('compare');
    $this->get(route('dashboard.all'))->assertOk();
});

it('Todas las marcas: exige sesion y la ruta no choca con las redes del dashboard', function () {
    auth()->logout();
    $this->get(route('dashboard.all'))->assertRedirect(route('login'));
    expect(route('dashboard.all', [], false))->toBe('/dashboard/todas-las-marcas');
});

it('Dashboard por marca no cambia: sigue entregando Dashboard/Index con su resumen (regresion)', function () {
    $a = Brand::factory()->for($this->user)->create(['name' => 'Alfa']);
    allBrandsSnap($this->user, $a, Network::Instagram, '2026-10-30', [Metric::VIEWS => 10]);

    $this->actingAs($this->user)->get(route('dashboard', ['brand_id' => $a->id, 'from' => '2026-10-25', 'to' => '2026-10-31']))
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('Dashboard/Index')
        ->where('brand.name', 'Alfa')->where('summary.cards.1.total', 10)->missing('summary.brands'));
});
