<?php

use App\Models\Brand;
use App\Models\User;
use App\Services\BrandService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->userA = User::factory()->create();
    $this->userB = User::factory()->create();
    $this->brandA = Brand::factory()->for($this->userA)->create(['name' => 'Proyecto A']);
});

// CA-02 / E2E-02: aislamiento por la web. Reutilizable para API y MCP.
test('user B cannot access brand of user A on any route', function (string $method, string $route, array $data) {
    $uri = route($route, $this->brandA->id, absolute: false);

    $response = $this->actingAs($this->userB)->{$method}($uri, $data);

    expect($response->getStatusCode())->toBeIn([403, 404]);
    expect($response->getContent())->not->toContain('Proyecto A');
})->with([
    'show' => ['get', 'brands.show', []],
    'edit' => ['get', 'brands.edit', []],
    'update' => ['put', 'brands.update', ['name' => 'Hack', 'timezone' => 'UTC']],
    'destroy' => ['delete', 'brands.destroy', []],
]);

test('brand list of user B is empty and leaks nothing', function () {
    $this->actingAs($this->userB)->get('/brands')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Brands/Index')->where('brands', []));
});

test('brand of A is untouched after B attempts', function () {
    $this->actingAs($this->userB)->delete(route('brands.destroy', $this->brandA->id, absolute: false));
    $this->actingAs($this->userB)->put(route('brands.update', $this->brandA->id, absolute: false), ['name' => 'Hack', 'timezone' => 'UTC']);

    expect(Brand::withoutUserScope()->find($this->brandA->id)->name)->toBe('Proyecto A');
});

test('global scope returns no rows without an authenticated user (fail safe)', function () {
    expect(Brand::count())->toBe(0);
});

test('creating a scoped model without user fails safe', function () {
    expect(fn () => Brand::create(['name' => 'x', 'timezone' => 'UTC']))->toThrow(LogicException::class);
});

test('global scope applies to the authenticated user only', function () {
    Brand::factory()->for($this->userB)->create();

    $this->actingAs($this->userA);
    expect(Brand::count())->toBe(1)->and(Brand::first()->name)->toBe('Proyecto A');
});

test('service requires explicit user and cannot return foreign brands', function () {
    $service = app(BrandService::class);

    expect($service->find($this->userB, $this->brandA->id))->toBeNull()
        ->and($service->list($this->userB))->toHaveCount(0)
        ->and($service->find($this->userA, $this->brandA->id))->not->toBeNull();
});

test('policy denial is logged', function () {
    Log::spy();

    $this->actingAs($this->userB);
    expect($this->userB->can('view', $this->brandA))->toBeFalse();

    Log::shouldHaveReceived('warning')->with('access.denied', Mockery::on(
        fn ($ctx) => $ctx['user_id'] === $this->userB->id && $ctx['resource_id'] === $this->brandA->id
    ))->once();
});

// Criterio «acceso denegado registrado»: el 404 del binding (UserScope) se loguea por la web real.
test('cross-user web access returns 404 and logs access.denied with user and brand', function (string $method, string $route, array $data) {
    Log::spy();

    $this->actingAs($this->userB)->{$method}(route($route, $this->brandA->id, absolute: false), $data)->assertNotFound();

    Log::shouldHaveReceived('warning')->with('access.denied', Mockery::on(
        fn ($ctx) => $ctx['user_id'] === $this->userB->id && $ctx['brand_id'] === (string) $this->brandA->id
    ))->once();
})->with([
    'show' => ['get', 'brands.show', []],
    'edit' => ['get', 'brands.edit', []],
    'update' => ['put', 'brands.update', ['name' => 'Hack', 'timezone' => 'UTC']],
    'destroy' => ['delete', 'brands.destroy', []],
]);

test('nonexistent brand returns the same 404 and logs nothing', function () {
    Log::spy();

    $this->actingAs($this->userB)->get('/brands/999999')->assertNotFound();

    Log::shouldNotHaveReceived('warning');
});

test('owner can run the full CRUD on brands', function () {
    $this->actingAs($this->userA);

    $this->post('/brands', ['name' => 'Nueva', 'description' => 'd', 'timezone' => 'Europe/Madrid'])
        ->assertRedirect('/brands');
    $brand = Brand::where('name', 'Nueva')->firstOrFail();
    expect($brand->user_id)->toBe($this->userA->id);

    $this->get("/brands/{$brand->id}")->assertOk();
    $this->put("/brands/{$brand->id}", ['name' => 'Editada', 'timezone' => 'UTC'])->assertRedirect('/brands');
    expect($brand->fresh()->name)->toBe('Editada');
    $this->delete("/brands/{$brand->id}")->assertRedirect('/brands');
    expect(Brand::find($brand->id))->toBeNull();
});

test('brand validation rejects bad input', function () {
    $this->actingAs($this->userA)->post('/brands', ['name' => '', 'timezone' => 'Mars/Base'])
        ->assertSessionHasErrors(['name', 'timezone']);
});

test('guests cannot access brands', function () {
    $this->get('/brands')->assertRedirect('/login');
});
