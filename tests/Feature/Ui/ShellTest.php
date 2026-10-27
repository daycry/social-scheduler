<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('Portada: un invitado ve la landing con acceso a entrar y crear cuenta', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Welcome')
        ->where('canLogin', true)->where('canRegister', true)->where('auth.user', null));
});

it('Portada: recibe el catalogo de redes ofrecidas para pintarlas sin lista fija', function () {
    $this->get('/')->assertInertia(fn (Assert $p) => $p->component('Welcome')
        ->has('networks', 4)->where('networks.3.value', 'tiktok')->where('networks.3.color', '#111827'));
});

it('Portada: con sesion expone el usuario para mostrar «Ir al panel»', function () {
    $this->actingAs($this->user)->get('/')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Welcome')->where('auth.user.id', $this->user->id));
});

it('Shell: comparte marcas, marca actual, cuentas por red y avisos sin leer', function () {
    $a = Brand::factory()->for($this->user)->create(['name' => 'Alfa']);
    $b = Brand::factory()->for($this->user)->create(['name' => 'Beta']);
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $a->id, 'network' => Network::Instagram]);
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $b->id, 'network' => Network::TikTok]);

    $this->actingAs($this->user)->get(route('brands.index'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->has('shell.brands', 2)->where('shell.currentBrand.name', 'Alfa')
            ->has('shell.accounts.instagram', 1)->missing('shell.accounts.tiktok')
            ->has('shell.networks', 4)
            ->where('shell.networks.0', ['value' => 'instagram', 'label' => 'Instagram', 'short_label' => 'IG', 'color' => '#db2777', 'provider' => 'meta', 'auth_kind' => 'oauth'])
            ->where('unreadNotifications', 0)
            // nunca llegan los tokens
            ->missing('shell.accounts.instagram.0.access_token'));
});

it('Selector de marca: ?brand_id= cambia la marca actual y persiste en sesion', function () {
    $a = Brand::factory()->for($this->user)->create(['name' => 'Alfa']);
    $b = Brand::factory()->for($this->user)->create(['name' => 'Beta']);
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $b->id, 'network' => Network::YouTube]);

    $this->actingAs($this->user)->get(route('brands.index', ['brand_id' => $b->id]))
        ->assertInertia(fn (Assert $p) => $p->where('shell.currentBrand.id', $b->id)->has('shell.accounts.youtube', 1));

    // Sin query en la siguiente peticion: sigue Beta (sesion) y el dashboard la usa.
    $this->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p
        ->where('shell.currentBrand.id', $b->id)->where('brand.id', $b->id));
    $this->get(route('brands.connections.index', $a))->assertInertia(fn (Assert $p) => $p->where('shell.currentBrand.id', $a->id));
    $this->get(route('brands.index'))->assertInertia(fn (Assert $p) => $p->where('shell.currentBrand.id', $a->id));
});

it('Selector de marca: una marca ajena en la query se ignora en el shell', function () {
    $mine = Brand::factory()->for($this->user)->create(['name' => 'Mia']);
    $other = Brand::factory()->for(User::factory()->create())->create(['name' => 'Ajena']);

    $this->actingAs($this->user)->get(route('brands.index', ['brand_id' => $other->id]))
        ->assertInertia(fn (Assert $p) => $p->where('shell.currentBrand.id', $mine->id)->has('shell.brands', 1));
});

it('Shell: el numero de consultas no crece con marcas y cuentas (sin N+1)', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get(route('brands.index'))->assertOk();

        return count(DB::getQueryLog());
    };
    $first = Brand::factory()->for($this->user)->create(['name' => 'A']);
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $first->id, 'network' => Network::Instagram]);
    $before = $count();

    foreach (range(1, 5) as $i) {
        $br = Brand::factory()->for($this->user)->create(['name' => "Z$i"]);
        SocialAccount::factory()->for($this->user)->create(['brand_id' => $br->id, 'network' => Network::Facebook]);
    }
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $first->id, 'network' => Network::TikTok]);

    expect($count())->toBe($before);
});

it('Estado vacio: sin marcas el dashboard llega sin marca ni resumen (onboarding)', function () {
    $this->actingAs($this->user)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Dashboard/Index')->where('brand', null)->where('summary', null)
            ->where('shell.currentBrand', null)->has('shell.brands', 0));
});

it('Estado vacio: red sin cuenta conectada da el proveedor para el banner de conexion', function () {
    $brand = Brand::factory()->for($this->user)->create();

    $this->actingAs($this->user)->get(route('dashboard.network', ['network' => 'tiktok']))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Dashboard/Network')
            ->where('brand.id', $brand->id)->has('detail.accounts', 0)
            ->where('shell.networks.3.value', 'tiktok')->where('shell.networks.3.provider', 'tiktok'));
});

it('Estado vacio: calendario sin publicaciones llega con items vacios', function () {
    Brand::factory()->for($this->user)->create();

    $this->actingAs($this->user)->get(route('calendar.index'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Calendar/Index')->has('items', 0)->has('undated', 0)->has('shell.brands', 1));
});

it('Idioma: los mensajes de validacion y de auth salen en espanol', function () {
    $this->post('/register', [])->assertSessionHasErrors(['email' => 'El campo correo electrónico es obligatorio.']);
    expect(trans('auth.failed'))->toBe('Estas credenciales no coinciden con nuestros registros.');
});

it('Shell: comparte el contador de comentarios sin leer del Inbox (0 sin comentarios)', function () {
    Brand::factory()->for($this->user)->create();

    $this->actingAs($this->user)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('unreadInbox', 0));
});
