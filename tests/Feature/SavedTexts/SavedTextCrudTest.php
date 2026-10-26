<?php

use App\Models\Brand;
use App\Models\SavedText;
use App\Models\User;

// CRUD de textos guardados (C-01a, CA-03): propiedad por usuario, 403 a ajenos.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

test('alta: crea un fragmento para todas las marcas y un grupo de hashtags de la marca', function () {
    $this->actingAs($this->user)->post(route('saved-texts.store'), ['kind' => 'fragmento', 'title' => 'Firma', 'body' => 'Un saludo'])
        ->assertRedirect()->assertSessionHas('success');
    $this->actingAs($this->user)->post(route('saved-texts.store'), ['kind' => 'hashtags', 'title' => 'Café', 'body' => '#cafe #barista', 'brand_id' => $this->brand->id])
        ->assertRedirect();

    $rows = SavedText::withoutUserScope()->where('user_id', $this->user->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->brand_id)->toBeNull()
        ->and($rows[1]->brand_id)->toBe($this->brand->id)
        ->and($rows[1]->kind)->toBe('hashtags');
});

test('edición y borrado del propietario persisten', function () {
    $text = SavedText::factory()->for($this->user)->create(['title' => 'Antes']);

    $this->actingAs($this->user)->put(route('saved-texts.update', $text->id), ['kind' => 'fragmento', 'title' => 'Después', 'body' => 'Nuevo', 'brand_id' => $this->brand->id])
        ->assertRedirect();
    expect($text->fresh()->title)->toBe('Después')->and($text->fresh()->brand_id)->toBe($this->brand->id);

    $this->actingAs($this->user)->delete(route('saved-texts.destroy', $text->id))->assertRedirect();
    expect(SavedText::withoutUserScope()->find($text->id))->toBeNull();
});

test('un usuario ajeno recibe 403 al editar o borrar y el texto no cambia', function () {
    $text = SavedText::factory()->for($this->user)->create(['title' => 'Mío']);
    $other = User::factory()->create();

    $this->actingAs($other)->put(route('saved-texts.update', $text->id), ['kind' => 'fragmento', 'title' => 'Robado', 'body' => 'x'])->assertForbidden();
    $this->actingAs($other)->delete(route('saved-texts.destroy', $text->id))->assertForbidden();

    expect($text->fresh()->title)->toBe('Mío');
});

test('valida tipo, título, cuerpo y que la marca sea del usuario', function () {
    $foreign = Brand::factory()->for(User::factory()->create())->create();

    $this->actingAs($this->user)->post(route('saved-texts.store'), ['kind' => 'otro', 'title' => '', 'body' => ''])
        ->assertSessionHasErrors(['kind', 'title', 'body']);
    $this->actingAs($this->user)->post(route('saved-texts.store'), ['kind' => 'fragmento', 'title' => 'T', 'body' => 'B', 'brand_id' => $foreign->id])
        ->assertSessionHasErrors('brand_id');

    expect(SavedText::withoutUserScope()->count())->toBe(0);
});

test('un invitado es redirigido al login', function () {
    $this->post(route('saved-texts.store'), ['kind' => 'fragmento', 'title' => 'T', 'body' => 'B'])->assertRedirect(route('login'));
});
