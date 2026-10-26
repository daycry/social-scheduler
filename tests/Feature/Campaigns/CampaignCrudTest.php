<?php

use App\Models\Brand;
use App\Models\Campaign;
use App\Models\User;

// CRUD de campañas (C-03, CA-12/CA-13): propiedad por usuario, archivo y fechas coherentes.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

test('alta, edición, archivo y borrado persisten', function () {
    $this->actingAs($this->user)->post(route('campaigns.store'), [
        'brand_id' => $this->brand->id, 'name' => 'Lanzamiento', 'color' => '#0f766e', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30',
    ])->assertRedirect()->assertSessionHas('success');

    $c = Campaign::withoutUserScope()->where('user_id', $this->user->id)->firstOrFail();
    expect($c->name)->toBe('Lanzamiento')->and($c->brand_id)->toBe($this->brand->id)->and($c->color)->toBe('#0f766e');

    $this->actingAs($this->user)->put(route('campaigns.update', $c->id), ['name' => 'Black Friday', 'color' => '#b91c1c', 'starts_on' => null, 'ends_on' => null])->assertRedirect();
    expect($c->fresh()->name)->toBe('Black Friday')->and($c->fresh()->starts_on)->toBeNull();

    $this->actingAs($this->user)->put(route('campaigns.archive', $c->id), ['archived' => true])->assertRedirect();
    expect($c->fresh()->isArchived())->toBeTrue();
    $this->actingAs($this->user)->put(route('campaigns.archive', $c->id), ['archived' => false])->assertRedirect();
    expect($c->fresh()->isArchived())->toBeFalse();

    $this->actingAs($this->user)->delete(route('campaigns.destroy', $c->id))->assertRedirect();
    expect(Campaign::withoutUserScope()->find($c->id))->toBeNull();
});

test('un usuario ajeno recibe 404 en edición, archivo y borrado (UserScope)', function () {
    $c = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'name' => 'Mía']);
    $other = User::factory()->create();

    $this->actingAs($other)->put(route('campaigns.update', $c->id), ['name' => 'Robada', 'color' => '#000000'])->assertNotFound();
    $this->actingAs($other)->put(route('campaigns.archive', $c->id), ['archived' => true])->assertNotFound();
    $this->actingAs($other)->delete(route('campaigns.destroy', $c->id))->assertNotFound();

    expect($c->fresh()->name)->toBe('Mía')->and($c->fresh()->isArchived())->toBeFalse();
});

test('valida nombre, color, marca del usuario y fin >= inicio', function () {
    $foreign = Brand::factory()->for(User::factory()->create())->create();

    $this->actingAs($this->user)->post(route('campaigns.store'), ['brand_id' => $this->brand->id, 'name' => '', 'color' => 'rojo'])
        ->assertSessionHasErrors(['name', 'color']);
    $this->actingAs($this->user)->post(route('campaigns.store'), ['name' => 'X', 'color' => '#000000', 'brand_id' => $foreign->id])
        ->assertSessionHasErrors('brand_id');
    $this->actingAs($this->user)->post(route('campaigns.store'), ['name' => 'X', 'color' => '#000000', 'brand_id' => $this->brand->id, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-01'])
        ->assertSessionHasErrors('ends_on');

    expect(Campaign::withoutUserScope()->count())->toBe(0);
});

test('editar no cambia la marca de la campaña', function () {
    $other = Brand::factory()->for($this->user)->create();
    $c = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id]);

    $this->actingAs($this->user)->put(route('campaigns.update', $c->id), ['name' => 'Y', 'color' => '#000000', 'brand_id' => $other->id])->assertRedirect();

    expect($c->fresh()->brand_id)->toBe($this->brand->id);
});
