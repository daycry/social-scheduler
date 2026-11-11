<?php

use App\Models\Brand;
use App\Models\User;
use App\Support\CurrentBrand;
use Illuminate\Support\Facades\Bus;

test('Reports defaults to the explicitly selected brand instead of the first alphabetic brand', function () {
    $user = User::factory()->create();
    Brand::factory()->for($user)->create(['name' => 'A']);
    $selected = Brand::factory()->for($user)->create(['name' => 'Z']);
    $this->actingAs($user)->get(route('reports.index', ['brand_id' => $selected->id]))
        ->assertOk()->assertInertia(fn ($page) => $page->where('defaults.brand_id', $selected->id));
});

test('Reports defaults to the persisted brand and rejects an explicit foreign brand', function () {
    $user = User::factory()->create();
    Brand::factory()->for($user)->create(['name' => 'A']);
    $selected = Brand::factory()->for($user)->create(['name' => 'Z']);
    $this->actingAs($user)->withSession([CurrentBrand::SESSION_KEY => $selected->id])->get(route('reports.index'))
        ->assertOk()->assertInertia(fn ($page) => $page->where('defaults.brand_id', $selected->id));
    $foreign = Brand::factory()->create();
    $this->get(route('reports.index', ['brand_id' => $foreign->id]))->assertNotFound();
});

test('Reports keeps the submitted brand on the redirect after generation', function () {
    Bus::fake();
    $user = User::factory()->create();
    $original = Brand::factory()->for($user)->create(['name' => 'A']);
    $selected = Brand::factory()->for($user)->create(['name' => 'Z']);
    $this->actingAs($user)->withSession([CurrentBrand::SESSION_KEY => $original->id])
        ->post(route('reports.store'), ['brand_id' => $selected->id, 'from' => '2026-10-01', 'to' => '2026-10-31', 'formats' => ['csv']])
        ->assertRedirect(route('reports.index', ['brand_id' => $selected->id]));
});
