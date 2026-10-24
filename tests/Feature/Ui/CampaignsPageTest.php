<?php

use App\Models\Brand;
use App\Models\Campaign;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// Página «Campañas» (C-03, CA-13): lista de la marca actual con archivadas al final.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Alfa']);
});

it('renderiza Campaigns/Index con las campañas de la marca actual, las archivadas al final', function () {
    Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'name' => 'Zeta']);
    Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'name' => 'Antigua', 'archived_at' => now()]);
    Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'name' => 'Beta']);
    $otherBrand = Brand::factory()->for($this->user)->create(['name' => 'Omega']);
    Campaign::factory()->create(['brand_id' => $otherBrand->id, 'user_id' => $this->user->id, 'name' => 'DeOtraMarca']);
    Campaign::factory()->create(['name' => 'Ajena']);

    $this->actingAs($this->user)->get(route('campaigns.index', ['brand_id' => $this->brand->id]))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Campaigns/Index')
            ->where('brand.name', 'Alfa')
            ->has('campaigns', 3)
            ->where('campaigns.0.name', 'Beta')->where('campaigns.1.name', 'Zeta')
            ->where('campaigns.2.name', 'Antigua')->where('campaigns.2.archived', true)
            ->where('campaigns.0.posts_count', 0));
});

it('sin marca devuelve la lista vacía', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('campaigns.index'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Campaigns/Index')->where('brand', null)->has('campaigns', 0));
});

it('exige sesión', function () {
    $this->get(route('campaigns.index'))->assertRedirect(route('login'));
});
