<?php

use App\Models\Brand;
use App\Models\CalendarView;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// Calendario · vistas guardadas (C-03a, CA-11): props `views`, `active_view` y aplicación de `saved_view`.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Alfa']);
});

it('ofrece las vistas del usuario ordenadas por nombre y sin las ajenas', function () {
    CalendarView::factory()->create(['user_id' => $this->user->id, 'name' => 'Zeta']);
    CalendarView::factory()->create(['user_id' => $this->user->id, 'name' => 'Beta', 'filters' => ['network' => 'instagram']]);
    CalendarView::factory()->create(['name' => 'Ajena']);

    $this->actingAs($this->user)->get(route('calendar.index'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Calendar/Index')->has('views', 2)
            ->where('views.0.name', 'Beta')->where('views.0.filters.network', 'instagram')->where('views.1.name', 'Zeta')->where('active_view', null));
});

it('elegir una vista aplica marca, red, estado y vista, y conserva la fecha', function () {
    $v = CalendarView::factory()->create(['user_id' => $this->user->id, 'filters' => ['brand_id' => $this->brand->id, 'network' => 'instagram', 'status' => 'programada', 'view' => 'list']]);

    $this->actingAs($this->user)->get(route('calendar.index', ['saved_view' => $v->id, 'date' => '2026-11-15', 'network' => 'tiktok']))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('view', 'list')->where('date', '2026-11-15')->where('active_view', $v->id)
            ->where('filters.brand_id', $this->brand->id)->where('filters.network', 'instagram')->where('filters.status', 'programada'));
});

it('la vista por defecto se aplica al abrir el calendario sin filtros, pero no si hay alguno', function () {
    $v = CalendarView::factory()->create(['user_id' => $this->user->id, 'is_default' => true, 'filters' => ['network' => 'facebook', 'view' => 'month']]);

    $this->actingAs($this->user)->get(route('calendar.index'))
        ->assertInertia(fn (Assert $p) => $p->where('view', 'month')->where('filters.network', 'facebook')->where('active_view', $v->id));
    $this->actingAs($this->user)->get(route('calendar.index', ['network' => 'tiktok']))
        ->assertInertia(fn (Assert $p) => $p->where('filters.network', 'tiktok')->where('active_view', null));
    $this->actingAs($this->user)->get(route('calendar.index', ['date' => '2026-11-15']))
        ->assertInertia(fn (Assert $p) => $p->where('filters.network', null)->where('active_view', null));
});

it('la vista por defecto se aplica al entrar desde el menú (brand_id y view no son filtros explícitos)', function () {
    $other = Brand::factory()->for($this->user)->create(['name' => 'Beta']);
    $v = CalendarView::factory()->create(['user_id' => $this->user->id, 'is_default' => true, 'filters' => ['network' => 'facebook', 'view' => 'month']]);

    $this->actingAs($this->user)->get(route('calendar.index', ['brand_id' => $other->id]))
        ->assertInertia(fn (Assert $p) => $p->where('view', 'month')->where('filters.network', 'facebook')->where('filters.brand_id', (string) $other->id)->where('active_view', $v->id));
    // El enlace «Calendario» del menú: brand_id + view=week. La vista que pide el enlace manda; el resto lo pone la vista por defecto.
    $this->actingAs($this->user)->get(route('calendar.index', ['brand_id' => $other->id, 'view' => 'week']))
        ->assertInertia(fn (Assert $p) => $p->where('view', 'week')->where('filters.network', 'facebook')->where('filters.brand_id', (string) $other->id)->where('active_view', $v->id));
});

it('una vista cuya marca ya no existe se abre sin ese filtro en vez de fallar', function () {
    $brand = Brand::factory()->for($this->user)->create();
    $v = CalendarView::factory()->create(['user_id' => $this->user->id, 'filters' => ['brand_id' => $brand->id, 'network' => 'instagram']]);
    $brand->delete();

    $this->actingAs($this->user)->get(route('calendar.index', ['saved_view' => $v->id]))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('filters.brand_id', null)->where('filters.network', 'instagram'));
});

it('una vista de otro usuario responde 404', function () {
    $foreign = CalendarView::factory()->create();

    $this->actingAs($this->user)->get(route('calendar.index', ['saved_view' => $foreign->id]))->assertNotFound();
});
