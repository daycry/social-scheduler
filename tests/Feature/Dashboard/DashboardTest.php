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
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Aipocalipsis']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->tt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok]);
});

function seedDays(SocialAccount $account, int $days, callable $metrics): void
{
    for ($i = 1; $i <= $days; $i++) {
        $date = Carbon::parse('2026-11-01')->subDays($i)->format('Y-m-d');
        AccountSnapshot::withoutUserScope()->create([
            'user_id' => $account->user_id, 'brand_id' => $account->brand_id, 'social_account_id' => $account->id,
            'network' => $account->network->value, 'date' => $date, 'metrics' => array_merge(Metric::blankAccount(), $metrics($i)),
        ]);
    }
}

it('Dashboard: con 30 dias de snapshots el resumen muestra seguidores, visualizaciones y alcance por red (CA-10)', function () {
    seedDays($this->ig, 30, fn ($i) => [Metric::FOLLOWERS => 1000 - $i, Metric::VIEWS => 10, Metric::REACH => 5]);
    seedDays($this->tt, 30, fn ($i) => []); // TikTok sin scopes: no disponible

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Dashboard/Index')
            ->where('brand.name', 'Aipocalipsis')
            ->where('summary.period.days', 30)
            ->has('summary.cards', 4)
            ->where('summary.cards.1.key', 'views')->where('summary.cards.1.total', 300)
            ->where('summary.cards.1.by_network.0.network', 'instagram')
            ->where('summary.cards.1.by_network.1.network', 'tiktok')
            ->where('summary.cards.1.by_network.1.value', null)->where('summary.cards.1.by_network.1.available', false)
            ->where('summary.cards.0.total', 999)
            ->has('summary.cards.1.series', 30));
});

it('Dashboard: sin marcas muestra la pantalla vacia sin romper', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('Dashboard/Index')->where('brand', null)->where('summary', null));
});

it('Dashboard: el periodo y la comparacion llegan por la query', function () {
    seedDays($this->ig, 30, fn ($i) => [Metric::VIEWS => $i <= 5 ? 20 : 10]);

    $this->actingAs($this->user)->get(route('dashboard', ['from' => '2026-10-27', 'to' => '2026-10-31', 'compare' => 'previous']))
        ->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('filters.compare', 'previous')->where('summary.comparison.from', '2026-10-22')
        ->where('summary.cards.1.total', 100)->where('summary.cards.1.previous', 50)->where('summary.cards.1.delta_pct', 100));
});

it('Dashboard: rechaza periodos invalidos', function () {
    $this->actingAs($this->user)->get(route('dashboard', ['from' => '2026-10-10', 'to' => '2026-10-01']))->assertStatus(422);
    $this->actingAs($this->user)->get(route('dashboard', ['from' => 'ayer']))->assertSessionHasErrors('from');
    $this->actingAs($this->user)->get(route('dashboard', ['compare' => 'otro']))->assertSessionHasErrors('compare');
});

it('Dashboard: la pagina por red muestra crecimiento y no disponible', function () {
    seedDays($this->ig, 10, fn ($i) => [Metric::FOLLOWERS => 500 - $i, Metric::VIEWS => 10]);

    $this->actingAs($this->user)->get(route('dashboard.network', ['network' => 'instagram']))
        ->assertOk()->assertInertia(fn (Assert $p) => $p->component('Dashboard/Network')
        ->where('detail.network.value', 'instagram')
        ->where('detail.growth.start', 490)->where('detail.growth.end', 499)->where('detail.growth.change', 9)
        ->where('detail.metrics', fn ($metrics) => collect($metrics)->firstWhere('key', 'reach')['available'] === false
            && collect($metrics)->firstWhere('key', 'views')['total'] === 100)
        ->has('networks', 2));
});

it('Dashboard: red desconocida o sin cuentas', function () {
    $this->actingAs($this->user)->get('/dashboard/linkedin')->assertNotFound();

    $this->actingAs($this->user)->get(route('dashboard.network', ['network' => 'youtube']))
        ->assertOk()->assertInertia(fn (Assert $p) => $p->has('detail.accounts', 0));
});

it('Dashboard: aislamiento por usuario, una marca ajena es 404 y sus datos no se filtran', function () {
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $otherAcc = SocialAccount::factory()->for($other)->create(['brand_id' => $otherBrand->id, 'network' => Network::Instagram]);
    seedDays($otherAcc, 5, fn ($i) => [Metric::VIEWS => 9999]);

    $this->actingAs($this->user)->get(route('dashboard', ['brand_id' => $otherBrand->id]))->assertNotFound();
    $this->actingAs($this->user)->get(route('dashboard.network', ['network' => 'instagram', 'brand_id' => $otherBrand->id]))->assertNotFound();

    $this->actingAs($this->user)->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->where('summary.cards.1.total', null));
});

it('Dashboard: los invitados van al login', function () {
    $this->get(route('dashboard.network', ['network' => 'instagram']))->assertRedirect(route('login', absolute: false));
});
