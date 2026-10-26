<?php

use App\Models\Brand;
use App\Models\SavedText;
use App\Models\User;

// Textos guardados (C-01a, CA-01/CA-03): por usuario, con marca opcional (nula = «Todas las marcas»).

test('un usuario no ve los textos guardados de otro', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    SavedText::factory()->for($a)->create(['title' => 'De A']);
    SavedText::factory()->for($b)->create(['title' => 'De B']);

    $this->actingAs($a);

    expect(SavedText::query()->pluck('title')->all())->toBe(['De A']);
});

test('la marca es opcional: nula significa todas las marcas', function () {
    $user = User::factory()->create();
    $text = SavedText::factory()->for($user)->create(['brand_id' => null, 'kind' => 'hashtags']);

    expect($text->fresh()->brand_id)->toBeNull()
        ->and($text->kind)->toBe('hashtags');
});

test('al borrar la marca se borran sus textos y no los de todas las marcas', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    SavedText::factory()->for($user)->create(['brand_id' => $brand->id]);
    $global = SavedText::factory()->for($user)->create(['brand_id' => null]);

    $brand->delete();

    expect(SavedText::withoutUserScope()->pluck('id')->all())->toBe([$global->id]);
});
