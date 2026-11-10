<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\McpClient;
use Tests\Support\NewNetworksFake;

uses(McpClient::class);

// T-18: las redes nuevas (LinkedIn, Pinterest) y las opciones avanzadas (ubicacion, etiquetas de usuario, visibilidad, tablero, enlace)
// con el MISMO contrato `targets[]` en la web, la API REST y el MCP.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    NewNetworksFake::linkedin();
    NewNetworksFake::pinterest();
    app()->forgetInstance(ConnectorRegistry::class);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $mk = fn (Network $n, string $name, array $meta = []) => SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => $n, 'name' => $name, 'meta' => $meta]);
    $this->ig = $mk(Network::Instagram, 'ana_ig');
    $this->li = $mk(Network::LinkedIn, 'Ana LinkedIn', ['kind' => 'profile', 'author_urn' => 'urn:li:person:abc']);
    $this->page = $mk(Network::LinkedIn, 'Acme SL', ['kind' => 'page', 'author_urn' => 'urn:li:organization:777']);
    $this->pin = $mk(Network::Pinterest, 'ana_pins', ['boards' => [['id' => '549755885175', 'name' => 'Recetas']]]);
    $this->img = MediaAsset::factory()->for($this->user)->create(['original_name' => 'a.jpg', 'width' => 400, 'height' => 400]);
    $this->key = $this->mcpKey($this->user);
});

function aocPayload(array $targets): array
{
    return ['text' => 'Hola mundo', 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true, 'media' => [test()->img->id], 'targets' => $targets];
}

function aocWebErrors(): array
{
    $bag = session('errors');

    return $bag === null ? [] : (is_object($bag) ? $bag->getBag('default')->getMessages() : $bag);
}

/** @return array{ok: bool, errors: array, id: ?int} */
function aocCreate(string $channel, array $payload): array
{
    $t = test();
    app('auth')->forgetGuards();
    if ($channel === 'web') {
        $t->actingAs($t->user)->post(route('brands.posts.store', $t->brand), $payload);

        return ['ok' => aocWebErrors() === [], 'errors' => aocWebErrors(), 'id' => Post::withoutUserScope()->latest('id')->value('id')];
    }
    if ($channel === 'api') {
        $r = $t->withToken($t->key)->postJson('/api/v1/posts', ['brand_id' => $t->brand->id] + $payload);

        return ['ok' => $r->status() === 201, 'errors' => $r->json('errors') ?? [], 'id' => $r->json('id')];
    }
    [$err, $data] = $t->callTool($t->key, 'create_post', ['brand_id' => $t->brand->id, 'media_ids' => $payload['media']] + collect($payload)->except('media')->all());

    return ['ok' => ! $err, 'errors' => $err ? ['mcp' => [$data]] : [], 'id' => $data['id'] ?? null];
}

function aocOptions(int $postId): array
{
    return PostTarget::withoutUserScope()->where('post_id', $postId)->get()->mapWithKeys(fn ($t) => [$t->social_account_id => $t->options ?? []])->all();
}

test('T-18 contrato: LinkedIn, Pinterest y las opciones de Instagram se guardan igual por web, API y MCP', function (string $channel) {
    $r = aocCreate($channel, aocPayload([
        ['account_id' => $this->li->id, 'content_type' => 'linkedin_image', 'options' => ['linkedin_visibility' => 'CONNECTIONS', 'linkedin_disable_reshare' => 1], 'first_comment' => 'Mas info'],
        ['account_id' => $this->page->id, 'content_type' => 'linkedin_image', 'media' => [['id' => $this->img->id, 'alt' => 'Logo de Acme']]],
        ['account_id' => $this->pin->id, 'content_type' => 'pinterest_pin', 'options' => ['pinterest_board_id' => '549755885175', 'pinterest_title' => 'Tarta', 'pinterest_link' => 'https://example.com/receta'], 'media' => [['id' => $this->img->id, 'alt' => 'Una tarta']]],
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'options' => ['location_id' => '213385402', 'user_tags' => [['username' => '@Amigo', 'x' => 0.25, 'y' => 0.75]]]],
    ]));

    expect($r['ok'])->toBeTrue(json_encode($r['errors']));
    $options = aocOptions($r['id']);
    expect($options[$this->li->id])->toBe(['linkedin_visibility' => 'CONNECTIONS', 'linkedin_disable_reshare' => true])
        ->and($options[$this->page->id])->toBe([])
        ->and($options[$this->pin->id])->toBe(['pinterest_board_id' => '549755885175', 'pinterest_title' => 'Tarta', 'pinterest_link' => 'https://example.com/receta'])
        ->and($options[$this->ig->id])->toBe(['location_id' => '213385402', 'user_tags' => [['username' => 'amigo', 'x' => 0.25, 'y' => 0.75]]]);
    expect(PostTarget::withoutUserScope()->where('social_account_id', $this->li->id)->value('first_comment'))->toBe('Mas info');
    expect(PostTarget::withoutUserScope()->where('social_account_id', $this->page->id)->value('media'))->toBe([['id' => $this->img->id, 'alt' => 'Logo de Acme', 'cover_id' => null]]);
})->with(['web', 'api', 'mcp']);

dataset('opcionesInvalidas', [
    'pinterest sin tablero' => [fn () => ['account_id' => test()->pin->id, 'content_type' => 'pinterest_pin'], 'Elige el tablero de Pinterest'],
    'pinterest tablero no numerico' => [fn () => ['account_id' => test()->pin->id, 'content_type' => 'pinterest_pin', 'options' => ['pinterest_board_id' => 'x; drop']], 'Elige el tablero de Pinterest'],
    'pinterest enlace http' => [fn () => ['account_id' => test()->pin->id, 'content_type' => 'pinterest_pin', 'options' => ['pinterest_board_id' => '1', 'pinterest_link' => 'http://example.com']], 'URL https valida'],
    'pinterest enlace javascript' => [fn () => ['account_id' => test()->pin->id, 'content_type' => 'pinterest_pin', 'options' => ['pinterest_board_id' => '1', 'pinterest_link' => 'javascript:alert(1)']], 'URL https valida'],
    'pinterest titulo largo' => [fn () => ['account_id' => test()->pin->id, 'content_type' => 'pinterest_pin', 'options' => ['pinterest_board_id' => '1', 'pinterest_title' => str_repeat('a', 101)]], 'hasta 100 caracteres'],
    'pagina con CONNECTIONS' => [fn () => ['account_id' => test()->page->id, 'content_type' => 'linkedin_image', 'options' => ['linkedin_visibility' => 'CONNECTIONS']], 'siempre en abierto'],
    'visibilidad desconocida' => [fn () => ['account_id' => test()->li->id, 'content_type' => 'linkedin_image', 'options' => ['linkedin_visibility' => 'SECRET']], 'PUBLIC o CONNECTIONS'],
    'opcion de LinkedIn en Instagram' => [fn () => ['account_id' => test()->ig->id, 'content_type' => 'instagram_image', 'options' => ['linkedin_visibility' => 'PUBLIC']], 'solo existe en LinkedIn'],
    'tablero en LinkedIn' => [fn () => ['account_id' => test()->li->id, 'content_type' => 'linkedin_image', 'options' => ['pinterest_board_id' => '1']], 'solo existe en Pinterest'],
    'ubicacion no numerica' => [fn () => ['account_id' => test()->ig->id, 'content_type' => 'instagram_image', 'options' => ['location_id' => 'Madrid']], 'id numerico de un lugar'],
    'ubicacion en LinkedIn' => [fn () => ['account_id' => test()->li->id, 'content_type' => 'linkedin_image', 'options' => ['location_id' => '123']], 'solo esta disponible para imagenes, Reels y carruseles de Instagram'],
    'etiqueta fuera de rango' => [fn () => ['account_id' => test()->ig->id, 'content_type' => 'instagram_image', 'options' => ['user_tags' => [['username' => 'amigo', 'x' => 1.5, 'y' => 0.2]]]], 'coordenadas x e y entre 0 y 1'],
    'etiqueta sin coordenadas' => [fn () => ['account_id' => test()->ig->id, 'content_type' => 'instagram_image', 'options' => ['user_tags' => [['username' => 'amigo']]]], 'coordenadas x e y entre 0 y 1'],
    'etiquetas repetidas' => [fn () => ['account_id' => test()->ig->id, 'content_type' => 'instagram_image', 'options' => ['user_tags' => [['username' => 'amigo', 'x' => 0.1, 'y' => 0.1], ['username' => 'AMIGO', 'x' => 0.2, 'y' => 0.2]]]], 'distinto'],
    'etiquetas fuera de una imagen' => [fn () => ['account_id' => test()->ig->id, 'content_type' => 'instagram_reel', 'options' => ['user_tags' => [['username' => 'amigo', 'x' => 0.1, 'y' => 0.1]]]], 'solo estan disponibles en las imagenes de Instagram'],
]);

test('T-18 contrato: una opcion no admitida se rechaza con el mismo error en los tres canales', function (string $channel, Closure $target, string $expected) {
    // Pinterest y Instagram necesitan media valida de imagen; el reel necesita video: aqui solo se valida el error de opciones.
    $payload = aocPayload([$target()]);
    if (($payload['targets'][0]['content_type'] ?? '') === 'instagram_reel') {
        $video = MediaAsset::factory()->for($this->user)->video()->create();
        $payload['media'] = [$video->id];
    }

    $r = aocCreate($channel, $payload);

    expect($r['ok'])->toBeFalse()->and(json_encode($r['errors'], JSON_UNESCAPED_UNICODE))->toContain($expected);
    expect(Post::withoutUserScope()->count())->toBe(0);
})->with(['web', 'api', 'mcp'])->with('opcionesInvalidas');

test('T-18 paridad: la web y el MCP anuncian las redes nuevas con sus tipos y capacidades', function () {
    app('auth')->forgetGuards();
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))->assertInertia(function (Assert $page) {
        $accounts = collect($page->toArray()['props']['accounts'])->keyBy('id');
        expect($accounts[$this->li->id]['linkedin']['kind'])->toBe('profile')->and($accounts[$this->li->id]['linkedin']['visibilities'])->toBe(['PUBLIC', 'CONNECTIONS'])
            ->and($accounts[$this->page->id]['linkedin']['visibilities'])->toBe(['PUBLIC'])
            ->and($accounts[$this->pin->id]['pinterest']['boards'])->toBe([['id' => '549755885175', 'name' => 'Recetas']])
            ->and($accounts[$this->pin->id]['pinterest']['board_required'])->toBeTrue()
            ->and($accounts[$this->ig->id]['instagram_presets']['location_id'])->toBeTrue()
            ->and($accounts[$this->ig->id]['instagram_presets']['user_tags']['max'])->toBe(20)
            ->and($accounts[$this->ig->id]['linkedin'])->toBeNull();
    });

    [, $data] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    $byId = collect($data['accounts'])->keyBy('id');
    expect($byId[$this->li->id]['content_types'])->toBe(['linkedin_text', 'linkedin_image', 'linkedin_carousel', 'linkedin_video'])
        ->and($byId[$this->li->id]['available'])->toBeTrue()
        ->and($byId[$this->page->id]['linkedin']['visibilities'])->toBe(['PUBLIC'])
        ->and($byId[$this->pin->id]['content_types'])->toBe(['pinterest_pin', 'pinterest_carousel', 'pinterest_video'])
        ->and($byId[$this->pin->id]['pinterest']['boards'][0]['name'])->toBe('Recetas')
        ->and($byId[$this->ig->id]['instagram_presets']['user_tags']['content_types'])->toBe(['instagram_image']);
});

test('T-18: sin las credenciales de la app, la red sale no configurada y create_post la rechaza', function () {
    config(['services.pinterest.client_id' => '']);

    [, $data] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    $pin = collect($data['accounts'])->firstWhere('id', $this->pin->id);
    expect($pin['available'])->toBeFalse()->and($pin['content_types'])->toBe([]);

    $r = aocCreate('api', aocPayload([['account_id' => $this->pin->id, 'content_type' => 'pinterest_pin', 'options' => ['pinterest_board_id' => '1']]]));
    expect($r['ok'])->toBeFalse();
});
