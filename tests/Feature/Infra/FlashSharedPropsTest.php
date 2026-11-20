<?php

use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\StudioDocument;
use Inertia\Testing\AssertableInertia as Assert;

it('shares only the public flash fields even on partial Inertia reloads', function () {
    $this->actingAs(User::factory()->create())
        ->withSession([
            'success' => 'Guardado.',
            'error' => 'La publicación no pudo enviarse.',
            'publishing_post_id' => 42,
            'new_key' => 'private-session-value',
        ])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->reloadOnly('auth', fn (Assert $partial) => $partial
                ->where('flash.success', 'Guardado.')
                ->where('flash.error', 'La publicación no pudo enviarse.')
                ->where('flash.publishing_post_id', 42)
                ->missing('flash.new_key')
                ->missing('newKey')
                ->etc()));
});

it('announces key creation and revocation and only shows the key once', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('api-keys.store'), [
        'name' => 'Publicaciones',
        'abilities' => ['posts:read'],
    ])->assertRedirect(route('api-keys.index'));

    $this->get(route('api-keys.index'))->assertInertia(fn (Assert $page) => $page
        ->component('ApiKeys/Index')
        ->where('flash.success', 'API key creada.')
        ->where('newKey', fn ($key) => is_string($key) && str_contains($key, '|'))
        ->missing('flash.new_key')
        ->etc());

    $this->get(route('api-keys.index'))->assertInertia(fn (Assert $page) => $page
        ->where('flash.success', null)
        ->where('newKey', null)
        ->etc());

    $this->delete(route('api-keys.destroy', $user->tokens()->sole()->id))
        ->assertRedirect(route('api-keys.index'));

    $this->get(route('api-keys.index'))->assertInertia(fn (Assert $page) => $page
        ->where('flash.success', 'API key revocada.')
        ->where('newKey', null)
        ->etc());
});

it('announces Studio creation after redirecting to the editor', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $response = $this->actingAs($user)->post(route('studio.designs.store', $brand), [
        'name' => 'Diseño para la campaña',
        'document' => StudioDocument::blank(),
    ]);

    $editor = route('studio.designs.edit', Design::query()->sole());
    $response->assertRedirect($editor);

    $this->get($editor)->assertInertia(fn (Assert $page) => $page
        ->component('Studio/Editor')
        ->where('flash.success', 'Diseño creado.')
        ->etc());
});

it('returns its creation notice as JSON without queueing a flash for another tab', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();

    $this->actingAs($user)->postJson(route('studio.designs.store', $brand), [
        'name' => 'Diseño desde la página de Studio',
        'document' => StudioDocument::blank(),
    ])->assertCreated()
        ->assertJsonPath('message', 'Diseño creado.')
        ->assertJsonPath('design.name', 'Diseño desde la página de Studio')
        ->assertSessionMissing('success');
});
