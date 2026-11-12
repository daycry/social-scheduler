<?php

use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

// E-09: listado de colaboradores de una marca por la web (workflow.collaborators.index) y por la API (api.collaborators.index).

beforeEach(function () {
    // El 404 se compara byte a byte con el de una marca inexistente: sin modo debug, para que la traza (con números de
    // línea) no entre en la respuesta. En CI el .env sale de .env.example, que trae APP_DEBUG=true.
    config(['app.debug' => false]);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->stranger = User::factory()->create();
    $this->brand = Brand::factory()->for($this->owner)->create();
    $this->collab = BrandCollaborator::query()->create(['brand_id' => $this->brand->id, 'owner_id' => $this->owner->id, 'user_id' => $this->approver->id, 'role' => 'aprobador']);
});

function collabApi(User $user, array $abilities, int $brandId): TestResponse
{
    $key = $user->createToken('k', $abilities)->plainTextToken;
    app('auth')->forgetGuards();

    return test()->withToken($key)->getJson(route('api.collaborators.index', $brandId));
}

test('workflow.collaborators.index: el propietario lista los colaboradores de su marca', function () {
    $this->actingAs($this->owner)->getJson(route('workflow.collaborators.index', $this->brand->id))
        ->assertOk()->assertJsonCount(1, 'collaborators')->assertJsonPath('collaborators.0.id', $this->collab->id);
});

test('workflow.collaborators.index: ni el aprobador invitado ni un ajeno pueden listarlos (404 como una marca inexistente)', function () {
    $missing = $this->actingAs($this->stranger)->getJson(route('workflow.collaborators.index', $this->brand->id + 9999));

    $this->actingAs($this->stranger)->getJson(route('workflow.collaborators.index', $this->brand->id))->assertNotFound()->assertExactJson($missing->json());
    $this->actingAs($this->approver)->getJson(route('workflow.collaborators.index', $this->brand->id))->assertNotFound();
});

test('workflow.collaborators.index: sin sesion no hay acceso', function () {
    $this->getJson(route('workflow.collaborators.index', $this->brand->id))->assertUnauthorized();
});

test('workflow.collaborators.index: no mezcla marcas, cada marca lista solo los suyos', function () {
    $second = Brand::factory()->for($this->owner)->create();
    $guest = User::factory()->create();
    $other = BrandCollaborator::query()->create(['brand_id' => $second->id, 'owner_id' => $this->owner->id, 'user_id' => $guest->id, 'role' => 'aprobador']);

    $first = $this->actingAs($this->owner)->getJson(route('workflow.collaborators.index', $this->brand->id))->assertOk()->json('collaborators.*.id');
    $secondIds = $this->actingAs($this->owner)->getJson(route('workflow.collaborators.index', $second->id))->assertOk()->json('collaborators.*.id');

    expect($first)->toBe([$this->collab->id])->and($secondIds)->toBe([$other->id]);
});

test('api.collaborators.index: la key del propietario con posts:read lista los colaboradores', function () {
    collabApi($this->owner, ['posts:read'], $this->brand->id)->assertOk()->assertJsonCount(1, 'collaborators')->assertJsonPath('collaborators.0.id', $this->collab->id);
});

test('api.collaborators.index: una key sin posts:read se rechaza', function () {
    collabApi($this->owner, ['posts:write', 'posts:approve'], $this->brand->id)->assertForbidden();
});

test('api.collaborators.index: la key de otro usuario o la del aprobador reciben 404 y no ven los colaboradores', function () {
    $missing = collabApi($this->stranger, ['*'], $this->brand->id + 9999);

    collabApi($this->stranger, ['*'], $this->brand->id)->assertNotFound()->assertExactJson($missing->json());
    collabApi($this->approver, ['*'], $this->brand->id)->assertNotFound();
    app('auth')->forgetGuards();
    $this->withoutToken()->getJson(route('api.collaborators.index', $this->brand->id))->assertUnauthorized();
});
