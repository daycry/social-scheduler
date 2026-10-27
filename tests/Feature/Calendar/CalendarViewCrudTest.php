<?php

use App\Models\Brand;
use App\Models\CalendarView;
use App\Models\User;

// C-03a · CA-11: alta, renombrado, borrado y vista por defecto; solo del dueño; marca y red validadas.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

function viewPayload(array $over = []): array
{
    return array_merge(['name' => 'Pendientes IG', 'filters' => ['brand_id' => test()->brand->id, 'network' => 'instagram', 'status' => 'programada', 'view' => 'list']], $over);
}

it('alta: guarda el nombre y los filtros de un solo valor', function () {
    $this->actingAs($this->user)->post(route('calendar-views.store'), viewPayload())->assertRedirect()->assertSessionHas('success');

    $v = CalendarView::withoutUserScope()->first();
    expect($v->user_id)->toBe($this->user->id)->and($v->name)->toBe('Pendientes IG')->and($v->is_default)->toBeFalse()
        ->and($v->filters)->toBe(['brand_id' => $this->brand->id, 'network' => 'instagram', 'status' => 'programada', 'view' => 'list']);
});

it('alta: ignora las claves de filtro desconocidas y los valores vacíos', function () {
    $this->actingAs($this->user)->post(route('calendar-views.store'), viewPayload(['filters' => ['network' => 'tiktok', 'status' => '', 'q' => 'hack', 'brand_id' => null]]))->assertRedirect();

    expect(CalendarView::withoutUserScope()->first()->filters)->toBe(['network' => 'tiktok']);
});

it('renombra y cambia los filtros', function () {
    $v = CalendarView::factory()->create(['user_id' => $this->user->id, 'name' => 'Antes']);

    $this->actingAs($this->user)->put(route('calendar-views.update', $v->id), ['name' => 'Después'])->assertRedirect();
    expect($v->fresh()->name)->toBe('Después')->and($v->fresh()->filters)->toBe(['view' => 'week']);

    $this->actingAs($this->user)->put(route('calendar-views.update', $v->id), ['name' => 'Después', 'filters' => ['network' => 'facebook']])->assertRedirect();
    expect($v->fresh()->filters)->toBe(['network' => 'facebook']);
});

it('borra la vista del dueño', function () {
    $v = CalendarView::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->delete(route('calendar-views.destroy', $v->id))->assertRedirect()->assertSessionHas('success');

    expect(CalendarView::withoutUserScope()->count())->toBe(0);
});

it('la vista de otro usuario responde 404 al editar o borrar y no cambia', function () {
    $v = CalendarView::factory()->create(['user_id' => $this->user->id, 'name' => 'Mía']);
    $other = User::factory()->create();

    $this->actingAs($other)->put(route('calendar-views.update', $v->id), ['name' => 'Robada'])->assertNotFound();
    $this->actingAs($other)->delete(route('calendar-views.destroy', $v->id))->assertNotFound();

    expect($v->fresh()->name)->toBe('Mía');
});

it('rechaza una marca que no es del usuario', function () {
    $foreign = Brand::factory()->for(User::factory()->create())->create();

    $this->actingAs($this->user)->post(route('calendar-views.store'), viewPayload(['filters' => ['brand_id' => $foreign->id]]))
        ->assertSessionHasErrors('filters.brand_id');

    expect(CalendarView::withoutUserScope()->count())->toBe(0);
});

it('rechaza una red, un estado o una vista desconocidos', function () {
    $this->actingAs($this->user)->post(route('calendar-views.store'), viewPayload(['filters' => ['network' => 'myspace']]))->assertSessionHasErrors('filters.network');
    $this->actingAs($this->user)->post(route('calendar-views.store'), viewPayload(['filters' => ['status' => 'rara']]))->assertSessionHasErrors('filters.status');
    $this->actingAs($this->user)->post(route('calendar-views.store'), viewPayload(['filters' => ['view' => 'year']]))->assertSessionHasErrors('filters.view');
});

it('valida el nombre: obligatorio, hasta 120 caracteres y único por usuario', function () {
    CalendarView::factory()->create(['user_id' => $this->user->id, 'name' => 'Repetida']);
    CalendarView::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Ajena']);

    $this->actingAs($this->user)->post(route('calendar-views.store'), viewPayload(['name' => '']))->assertSessionHasErrors('name');
    $this->actingAs($this->user)->post(route('calendar-views.store'), viewPayload(['name' => str_repeat('a', 121)]))->assertSessionHasErrors('name');
    $this->actingAs($this->user)->post(route('calendar-views.store'), viewPayload(['name' => 'Repetida']))->assertSessionHasErrors('name');
    // El nombre de otro usuario no cuenta, y renombrar a su propio nombre no es un duplicado.
    $this->actingAs($this->user)->post(route('calendar-views.store'), viewPayload(['name' => 'Ajena']))->assertSessionDoesntHaveErrors();
    $own = CalendarView::withoutUserScope()->where('user_id', $this->user->id)->where('name', 'Repetida')->first();
    $this->actingAs($this->user)->put(route('calendar-views.update', $own->id), ['name' => 'Repetida'])->assertSessionDoesntHaveErrors();
});

it('solo una vista por defecto por usuario', function () {
    $other = User::factory()->create();
    $otherDefault = CalendarView::factory()->create(['user_id' => $other->id, 'is_default' => true]);

    $this->actingAs($this->user)->post(route('calendar-views.store'), viewPayload(['name' => 'Una', 'is_default' => true]))->assertRedirect();
    $this->actingAs($this->user)->post(route('calendar-views.store'), viewPayload(['name' => 'Dos', 'is_default' => true]))->assertRedirect();

    $mine = CalendarView::withoutUserScope()->where('user_id', $this->user->id)->orderBy('id')->get();
    expect($mine->pluck('is_default')->all())->toBe([false, true]);

    // Promover otra por actualización quita la anterior; la de otro usuario no se toca.
    $this->actingAs($this->user)->put(route('calendar-views.update', $mine[0]->id), ['is_default' => true])->assertRedirect();
    expect(CalendarView::withoutUserScope()->where('user_id', $this->user->id)->orderBy('id')->pluck('is_default')->all())->toBe([true, false])
        ->and($otherDefault->fresh()->is_default)->toBeTrue();

    // Y se puede quitar la de por defecto.
    $this->actingAs($this->user)->put(route('calendar-views.update', $mine[0]->id), ['is_default' => false])->assertRedirect();
    expect(CalendarView::withoutUserScope()->where('user_id', $this->user->id)->where('is_default', true)->count())->toBe(0);
});

it('exige sesión', function () {
    $this->post(route('calendar-views.store'), viewPayload())->assertRedirect(route('login'));
});

it('borrar la vista abierta vuelve al calendario sin ella (no a un 404)', function () {
    $v = CalendarView::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->from(route('calendar.index', ['saved_view' => $v->id]))->delete(route('calendar-views.destroy', $v->id))
        ->assertRedirect(route('calendar.index'));

    $this->actingAs($this->user)->get(route('calendar.index'))->assertOk();
});
