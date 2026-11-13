<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\McpClient;

uses(McpClient::class);

// T-17: el mismo contrato de media por destino (targets[].media) en la web, la API REST y el MCP.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'ana_ig']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => 'Pagina Ana']);
    $mk = fn (string $n) => MediaAsset::factory()->for($this->user)->create(['original_name' => "$n.jpg", 'width' => 400, 'height' => 400]);
    [$this->a, $this->b, $this->c] = [$mk('A'), $mk('B'), $mk('C')];
    $this->key = $this->mcpKey($this->user);
});

function tmcPayload(array $override = []): array
{
    $t = test();

    return array_replace_recursive([
        'text' => 'Hola', 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true, 'media' => [$t->a->id],
        'targets' => [
            ['account_id' => $t->ig->id, 'content_type' => 'instagram_carousel', 'media' => [['id' => $t->c->id, 'alt' => 'Tres'], ['id' => $t->a->id, 'alt' => 'Uno']]],
            ['account_id' => $t->fb->id, 'content_type' => 'facebook_photo'],
        ],
    ], $override);
}

/** Errores de validacion que la web deja en la sesion (objeto o array segun el driver). */
function tmcWebErrors(): array
{
    $bag = session('errors');

    return $bag === null ? [] : (is_object($bag) ? $bag->getBag('default')->getMessages() : $bag);
}

/** Cada peticion es independiente (como un cliente real): sin usuario ni guard cacheados de la anterior. */
function tmcFresh(): void
{
    app('auth')->forgetGuards();
}

/** Crea por cada canal; devuelve el id del post. */
function tmcCreate(string $channel, array $payload): array
{
    $t = test();
    tmcFresh();
    if ($channel === 'web') {
        $response = $t->actingAs($t->user)->post(route('brands.posts.store', $t->brand), $payload);

        return ['ok' => tmcWebErrors() === [], 'errors' => tmcWebErrors(), 'id' => Post::withoutUserScope()->latest('id')->value('id')];
    }
    if ($channel === 'api') {
        $r = $t->withToken($t->key)->postJson('/api/v1/brands/'.$t->brand->id.'/posts', $payload);

        return ['ok' => $r->status() === 201, 'errors' => $r->json('errors') ?? [], 'id' => $r->json('id')];
    }
    [$err, $data] = $t->callTool($t->key, 'create_post', ['brand_id' => $t->brand->id, 'media_ids' => $payload['media']] + collect($payload)->except('media')->all());

    return ['ok' => ! $err, 'errors' => $err ? ['mcp' => [$data]] : [], 'id' => $data['id'] ?? null];
}

function tmcUpdate(string $channel, int $id, array $payload): array
{
    $t = test();
    tmcFresh();
    if ($channel === 'web') {
        $t->actingAs($t->user)->put(route('posts.update', $id), $payload);

        return ['ok' => tmcWebErrors() === [], 'errors' => tmcWebErrors()];
    }
    if ($channel === 'api') {
        $r = $t->withToken($t->key)->putJson("/api/v1/posts/$id", $payload);

        return ['ok' => $r->status() === 200, 'errors' => $r->json('errors') ?? []];
    }
    [$err, $data] = $t->callTool($t->key, 'update_post', ['post_id' => $id, 'media_ids' => $payload['media']] + collect($payload)->except('media')->all());

    return ['ok' => ! $err, 'errors' => $err ? ['mcp' => [$data]] : []];
}

/** Lo que cada canal devuelve de la media por destino, normalizado: [account_id => media propia (null = hereda)]. */
function tmcRead(string $channel, int $id): array
{
    $t = test();
    tmcFresh();
    if ($channel === 'web') {
        $out = [];
        $t->actingAs($t->user)->get(route('posts.edit', $id))->assertInertia(function (Assert $page) use (&$out) {
            $props = $page->toArray()['props']['post'];
            foreach ($props['targets'] as $target) {
                $out[$target['account_id']] = $target['media'];
            }
            $out['common'] = array_column($props['media'], 'id');
        });

        return $out;
    }
    if ($channel === 'api') {
        $r = $t->withToken($t->key)->getJson("/api/v1/posts/$id")->assertOk()->json();
    } else {
        [, $r] = $t->callTool($t->key, 'get_post', ['post_id' => $id]);
    }
    $out = ['common' => $r['media_ids']];
    foreach ($r['targets'] as $target) {
        $out[$target['account_id']] = $target['media'];
        // La media efectiva es lo que se publica: la propia o, si hereda, la comun (sin alt ni portada).
        expect(array_column($target['effective_media'], 'id'))->toBe($target['media'] === null ? $r['media_ids'] : array_column($target['media'], 'id'));
    }

    return $out;
}

test('T-17 contrato: web, API y MCP guardan y devuelven la misma media por destino', function (string $channel) {
    $created = tmcCreate($channel, tmcPayload());
    expect($created['ok'])->toBeTrue(json_encode($created['errors']));

    $read = tmcRead($channel, $created['id']);
    expect($read['common'])->toBe([$this->a->id])
        ->and($read[$this->ig->id])->toBe([['id' => $this->c->id, 'alt' => 'Tres', 'cover_id' => null], ['id' => $this->a->id, 'alt' => 'Uno', 'cover_id' => null]])
        ->and($read[$this->fb->id])->toBeNull();
})->with(['web', 'api', 'mcp']);

test('T-17 contrato: editar vuelve a la media comun al omitirla', function (string $channel) {
    $id = tmcCreate($channel, tmcPayload())['id'];

    $update = tmcUpdate($channel, $id, tmcPayload(['targets' => [1 => ['media' => [['id' => $this->b->id]]]]]));
    expect($update['ok'])->toBeTrue(json_encode($update['errors']));
    $read = tmcRead($channel, $id);
    expect($read[$this->fb->id])->toBe([['id' => $this->b->id, 'alt' => null, 'cover_id' => null]]);

    // Sin `media` el destino hereda la comun (aqui una imagen: el destino pasa a ser una publicacion de imagen).
    $payload = tmcPayload();
    $payload['targets'][0] = ['account_id' => $this->ig->id, 'content_type' => 'instagram_image'];
    $back = tmcUpdate($channel, $id, $payload);
    expect($back['ok'])->toBeTrue(json_encode($back['errors']));
    expect(tmcRead($channel, $id)[$this->ig->id])->toBeNull();
})->with(['web', 'api', 'mcp']);

test('T-17 contrato: los tres canales rechazan con el mismo error en espanol que nombra el destino', function (string $channel) {
    $payload = tmcPayload(['targets' => [1 => ['media' => [['id' => $this->a->id], ['id' => $this->b->id]]]]]);

    $result = tmcCreate($channel, $payload);

    expect($result['ok'])->toBeFalse()
        ->and(json_encode($result['errors'], JSON_UNESCAPED_UNICODE))->toContain('Media propia de Pagina Ana (Facebook)')->toContain('admite como maximo 1 archivo');
    expect(Post::withoutUserScope()->count())->toBe(0);
})->with(['web', 'api', 'mcp']);

test('T-17 contrato: la media de otro usuario no se puede poner en un destino por ningun canal', function (string $channel) {
    $foreign = MediaAsset::factory()->for(User::factory()->create())->create(['width' => 400, 'height' => 400]);
    $payload = tmcPayload(['targets' => [1 => ['media' => [['id' => $foreign->id]]]]]);

    $result = tmcCreate($channel, $payload);

    expect($result['ok'])->toBeFalse()->and(json_encode($result['errors']))->toContain("el archivo {$foreign->id} no existe");
})->with(['web', 'api', 'mcp']);

test('T-17 API: exige la ability, es solo del usuario de la key y valida la forma', function () {
    $other = User::factory()->create();
    $readOnly = $this->mcpKey($this->user, ['posts:read']);
    $post = Post::withoutUserScope()->find(tmcCreate('api', tmcPayload())['id']);
    tmcFresh();

    tmcFresh();
    $this->withToken($readOnly)->postJson('/api/v1/brands/'.$this->brand->id.'/posts', tmcPayload())->assertForbidden();
    tmcFresh();
    $this->withToken($readOnly)->getJson("/api/v1/posts/{$post->id}")->assertOk()->assertJsonPath('targets.0.media.0.alt', 'Tres');
    tmcFresh();
    $this->withToken($this->mcpKey($other))->getJson("/api/v1/posts/{$post->id}")->assertNotFound();
    tmcFresh();
    $this->withToken($this->mcpKey($other))->putJson("/api/v1/posts/{$post->id}", tmcPayload())->assertNotFound();
    tmcFresh();
    $this->flushHeaders();
    $this->postJson('/api/v1/brands/'.$this->brand->id.'/posts', tmcPayload())->assertUnauthorized();
    tmcFresh();
    $this->withToken($this->key)->postJson('/api/v1/brands/'.$this->brand->id.'/posts', tmcPayload(['targets' => [0 => ['media' => [['id' => 'x']]]]]))->assertUnprocessable()->assertJsonValidationErrors(['targets.0.media.0.id']);
});
