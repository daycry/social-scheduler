<?php

use App\Connectors\Network;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\McpClient;

uses(McpClient::class);

// T-28 · API v1: marcas, cuentas y publicaciones (listar, ver, borrar, cancelar, publicar ya) con el contrato de cursor comun.
// Cada endpoint: exito, otro usuario, otra marca, ability ausente y validacion.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->brand2 = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->foreignBrand = Brand::factory()->for($this->other)->create(['timezone' => 'UTC']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => 'Pagina Ana']);
    $this->foreignFb = SocialAccount::factory()->for($this->other)->create(['brand_id' => $this->foreignBrand->id, 'network' => Network::Facebook, 'name' => 'Ajena']);
    $this->key = $this->mcpKey($this->user);
    $this->readKey = $this->mcpKey($this->user, ['posts:read', 'brands:read']);
    $this->noneKey = $this->mcpKey($this->user, ['stats:read']);
    $this->otherKey = $this->mcpKey($this->other);
});

function v1(string $key, string $method, string $uri, array $body = [])
{
    app('auth')->forgetGuards();

    return test()->withToken($key)->json($method, '/api/v1/'.ltrim($uri, '/'), $body);
}

/** Crea un borrador por la propia API y devuelve su id. */
function v1Draft(string $text = 'Hola', ?int $brandId = null, ?int $accountId = null): int
{
    $t = test();

    return v1($t->key, 'POST', 'brands/'.($brandId ?? $t->brand->id).'/posts', ['text' => $text, 'schedule' => false, 'targets' => [['account_id' => $accountId ?? $t->fb->id, 'content_type' => 'facebook_text']]])
        ->assertCreated()->json('id');
}

test('marcas: lista y detalle solo de las propias, con la ability brands:read', function () {
    $list = v1($this->readKey, 'GET', 'brands')->assertOk()->json('brands');
    expect(array_column($list, 'id'))->toEqualCanonicalizing([$this->brand->id, $this->brand2->id])->and($list[0])->toHaveKeys(['id', 'name', 'description', 'timezone']);
    v1($this->readKey, 'GET', 'brands/'.$this->brand->id)->assertOk()->assertJsonPath('id', $this->brand->id);
    v1($this->readKey, 'GET', 'brands/'.$this->foreignBrand->id)->assertNotFound();
    v1($this->noneKey, 'GET', 'brands')->assertForbidden();
    v1($this->noneKey, 'GET', 'brands/'.$this->brand->id)->assertForbidden();
    app('auth')->forgetGuards();
    $this->flushHeaders()->getJson('/api/v1/brands')->assertUnauthorized();
});

test('cuentas: las de la marca con sus tipos de contenido, sin tokens; marca ajena 404; sin ability 403', function () {
    $res = v1($this->readKey, 'GET', 'brands/'.$this->brand->id.'/accounts')->assertOk();
    expect($res->json('accounts.0.id'))->toBe($this->fb->id)->and($res->json('accounts.0.content_types'))->toContain('facebook_text')
        ->and($res->getContent())->not->toContain('access_token');
    v1($this->readKey, 'GET', 'brands/'.$this->foreignBrand->id.'/accounts')->assertNotFound();
    v1($this->noneKey, 'GET', 'brands/'.$this->brand->id.'/accounts')->assertForbidden();
});

test('posts: el listado pagina por cursor (limit, has_more, next_cursor), filtra por estado y solo ve la marca y el usuario', function () {
    $ids = [v1Draft('a'), v1Draft('b'), v1Draft('c')];
    v1Draft('otra marca', $this->brand2->id, SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand2->id, 'network' => Network::Facebook])->id);

    $first = v1($this->readKey, 'GET', 'brands/'.$this->brand->id.'/posts?limit=2')->assertOk()->json();
    expect(array_column($first['posts'], 'id'))->toBe([$ids[2], $ids[1]])->and($first['has_more'])->toBeTrue()->and($first['next_cursor'])->toBeString();
    $second = v1($this->readKey, 'GET', 'brands/'.$this->brand->id.'/posts?limit=2&cursor='.urlencode($first['next_cursor']))->assertOk()->json();
    expect(array_column($second['posts'], 'id'))->toBe([$ids[0]])->and($second['has_more'])->toBeFalse()->and($second['next_cursor'])->toBeNull();

    v1($this->readKey, 'GET', 'brands/'.$this->brand->id.'/posts?status=programada')->assertOk()->assertJsonCount(0, 'posts');
    v1($this->readKey, 'GET', 'brands/'.$this->brand->id.'/posts?status=borrador')->assertOk()->assertJsonCount(3, 'posts');
});

test('posts: listado con otra marca 404, ability 403 y validacion 422 (status, limit, cursor)', function () {
    v1($this->readKey, 'GET', 'brands/'.$this->foreignBrand->id.'/posts')->assertNotFound();
    v1($this->noneKey, 'GET', 'brands/'.$this->brand->id.'/posts')->assertForbidden();
    v1($this->readKey, 'GET', 'brands/'.$this->brand->id.'/posts?status=zzz')->assertUnprocessable()->assertJsonValidationErrors('status');
    v1($this->readKey, 'GET', 'brands/'.$this->brand->id.'/posts?limit=101')->assertUnprocessable()->assertJsonValidationErrors('limit');
    v1($this->readKey, 'GET', 'brands/'.$this->brand->id.'/posts?cursor=no-es-un-cursor')->assertUnprocessable()->assertJsonValidationErrors('cursor');
});

test('posts: crear con la marca en la ruta; marca ajena 404; sin ability 403; validacion 422', function () {
    v1($this->key, 'POST', 'brands/'.$this->foreignBrand->id.'/posts', ['text' => 'x', 'schedule' => false, 'targets' => [['account_id' => $this->foreignFb->id, 'content_type' => 'facebook_text']]])->assertNotFound();
    v1($this->readKey, 'POST', 'brands/'.$this->brand->id.'/posts', ['text' => 'x'])->assertForbidden();
    v1($this->key, 'POST', 'brands/'.$this->brand->id.'/posts', ['text' => 'x', 'schedule' => false, 'targets' => 'no'])->assertUnprocessable();
    // Con la cuenta de otro usuario, el servicio responde validacion, nunca crea.
    v1($this->key, 'POST', 'brands/'.$this->brand->id.'/posts', ['text' => 'x', 'schedule' => false, 'targets' => [['account_id' => $this->foreignFb->id, 'content_type' => 'facebook_text']]])->assertUnprocessable();
    expect(Post::withoutUserScope()->count())->toBe(0);
    v1Draft();
    expect(Post::withoutUserScope()->count())->toBe(1);
});

test('posts: ver uno propio; el de otro usuario 404; sin ability 403', function () {
    $id = v1Draft();
    v1($this->readKey, 'GET', 'posts/'.$id)->assertOk()->assertJsonPath('id', $id)->assertJsonPath('status', 'borrador');
    v1($this->otherKey, 'GET', 'posts/'.$id)->assertNotFound();
    v1($this->noneKey, 'GET', 'posts/'.$id)->assertForbidden();
});

test('posts: borrar un borrador; el ajeno 404; sin ability 403; uno ya publicado 422', function () {
    $id = v1Draft();
    v1($this->readKey, 'DELETE', 'posts/'.$id)->assertForbidden();
    v1($this->otherKey, 'DELETE', 'posts/'.$id)->assertNotFound();
    expect(Post::withoutUserScope()->find($id))->not->toBeNull();
    v1($this->key, 'DELETE', 'posts/'.$id)->assertOk()->assertJsonPath('deleted', true);
    expect(Post::withoutUserScope()->find($id))->toBeNull();
    v1($this->key, 'DELETE', 'posts/'.$id)->assertNotFound();

    $published = v1Draft('publicado');
    Post::withoutUserScope()->find($published)->targets()->update(['status' => 'publicada']);
    v1($this->key, 'DELETE', 'posts/'.$published)->assertUnprocessable();
});

test('posts: cancelar la programacion devuelve a borrador; ajeno 404; sin ability 403', function () {
    $id = v1($this->key, 'POST', 'brands/'.$this->brand->id.'/posts', ['text' => 'p', 'schedule' => true, 'scheduled_at' => '2026-11-02 10:00',
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']]])->assertCreated()->assertJsonPath('status', 'programada')->json('id');

    v1($this->readKey, 'POST', 'posts/'.$id.'/cancel')->assertForbidden();
    v1($this->otherKey, 'POST', 'posts/'.$id.'/cancel')->assertNotFound();
    v1($this->key, 'POST', 'posts/'.$id.'/cancel')->assertOk()->assertJsonPath('status', 'borrador');
});

test('posts: publicar ya encola el destino; ajeno 404; sin ability 403; un post no valido da 422', function () {
    $id = v1Draft();
    v1($this->readKey, 'POST', 'posts/'.$id.'/publish')->assertForbidden();
    v1($this->otherKey, 'POST', 'posts/'.$id.'/publish')->assertNotFound();
    v1($this->key, 'POST', 'posts/'.$id.'/publish')->assertStatus(202)->assertJsonPath('status', 'programada');
    Queue::assertPushed(PublishPostTarget::class);

    // Uno ya publicado no se vuelve a publicar: error de dominio, no 500.
    $published = v1Draft('publicado');
    Post::withoutUserScope()->find($published)->targets()->update(['status' => 'publicada']);
    v1($this->key, 'POST', 'posts/'.$published.'/publish')->assertUnprocessable();
});

test('la marca ya no viaja en el cuerpo: POST /posts y POST /series sin marca en la ruta no existen', function () {
    v1($this->key, 'POST', 'posts', ['brand_id' => $this->brand->id, 'text' => 'x'])->assertNotFound();
    v1($this->key, 'POST', 'series', ['brand_id' => $this->brand->id])->assertStatus(405);
});
