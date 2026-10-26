<?php

use App\Models\Brand;
use App\Models\SavedText;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// Página de ajustes «Textos guardados» (C-01a, CA-03).

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Alfa']);
});

it('renderiza SavedTexts/Index con los textos del usuario y sus marcas', function () {
    SavedText::factory()->for($this->user)->create(['title' => 'Firma', 'brand_id' => null]);
    SavedText::factory()->for($this->user)->create(['title' => 'Café', 'kind' => 'hashtags', 'brand_id' => $this->brand->id]);
    SavedText::factory()->for(User::factory()->create())->create(['title' => 'Ajeno']);

    $this->actingAs($this->user)->get(route('saved-texts.index'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('SavedTexts/Index')
            ->has('texts', 2)
            ->where('texts.0.title', 'Café')->where('texts.0.brand', 'Alfa')->where('texts.1.brand', null)
            ->has('brands', 1)->where('brands.0.name', 'Alfa')
            ->where('kinds.fragmento', 'Fragmento')->where('kinds.hashtags', 'Grupo de hashtags'));
});

it('exige sesión', function () {
    $this->get(route('saved-texts.index'))->assertRedirect(route('login'));
});
