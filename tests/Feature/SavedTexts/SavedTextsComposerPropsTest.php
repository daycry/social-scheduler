<?php

use App\Models\Brand;
use App\Models\SavedText;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// Compositor (C-01a, CA-01): la marca A ve «Todas las marcas» y las suyas, nunca las de B ni las de otro usuario.

it('el compositor de la marca A recibe los textos globales y los de A, y no los de B', function () {
    $user = User::factory()->create();
    $a = Brand::factory()->for($user)->create();
    $b = Brand::factory()->for($user)->create();
    SavedText::factory()->for($user)->create(['title' => 'Global', 'brand_id' => null]);
    SavedText::factory()->for($user)->create(['title' => 'De A', 'brand_id' => $a->id, 'kind' => 'hashtags']);
    SavedText::factory()->for($user)->create(['title' => 'De B', 'brand_id' => $b->id]);
    SavedText::factory()->for(User::factory()->create())->create(['title' => 'Ajeno', 'brand_id' => null]);

    $this->actingAs($user)->get(route('brands.posts.create', $a))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Posts/Compose')
            ->has('savedTexts', 2)
            ->where('savedTexts.0.title', 'De A')->where('savedTexts.0.kind', 'hashtags')
            ->where('savedTexts.1.title', 'Global'));
});
