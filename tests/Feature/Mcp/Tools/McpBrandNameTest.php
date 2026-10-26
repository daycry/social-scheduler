<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Proyecto A', 'timezone' => 'Europe/Madrid']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'IG A']);
    $this->key = $this->mcpKey($this->user);
});

test('McpBrandName: las herramientas aceptan el nombre de la marca en vez del id (sin distinguir mayusculas)', function () {
    [$err, $accounts] = $this->callTool($this->key, 'list_accounts', ['brand' => '  proyecto a ']);
    expect($err)->toBeFalse()
        ->and($accounts)->toMatchArray(['brand_id' => $this->brand->id, 'brand_name' => 'Proyecto A'])
        ->and($accounts['accounts'])->toHaveCount(1);

    [$err, $stats] = $this->callTool($this->key, 'get_stats', ['brand' => 'Proyecto A']);
    expect($err)->toBeFalse()->and($stats)->toMatchArray(['brand_id' => $this->brand->id, 'brand_name' => 'Proyecto A']);

    [$err, $calendar] = $this->callTool($this->key, 'get_calendar', ['brand' => 'Proyecto A']);
    expect($err)->toBeFalse();
});

test('McpBrandName: create_post por nombre de marca y la respuesta trae brand_name', function () {
    $image = MediaAsset::factory()->for($this->user)->create();

    [$err, $post] = $this->callTool($this->key, 'create_post', [
        'brand' => 'Proyecto A',
        'text' => 'Hola por nombre',
        'scheduled_at' => '2026-11-02 10:00',
        'schedule' => true,
        'media_ids' => [$image->id],
        'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_image']],
    ]);

    expect($err)->toBeFalse(is_string($post) ? $post : '')
        ->and($post)->toMatchArray(['brand_id' => $this->brand->id, 'brand_name' => 'Proyecto A'])
        ->and(Post::find($post['id'])->brand_id)->toBe($this->brand->id);

    [$err, $got] = $this->callTool($this->key, 'get_post', ['post_id' => $post['id']]);
    expect($err)->toBeFalse()->and($got['brand_name'])->toBe('Proyecto A');
});

test('McpBrandName: sin marca, con un nombre inexistente o con uno repetido se explica que hacer', function () {
    [$err, $msg] = $this->callTool($this->key, 'list_accounts');
    expect($err)->toBeTrue()->and($msg)->toContain('brand')->toContain('list_brands');

    [$err, $msg] = $this->callTool($this->key, 'list_accounts', ['brand' => 'No existe']);
    expect($err)->toBeTrue()->and($msg)->toContain('«No existe» no encontrada');

    $twin = Brand::factory()->for($this->user)->create(['name' => 'proyecto a']);
    [$err, $msg] = $this->callTool($this->key, 'list_accounts', ['brand' => 'Proyecto A']);
    expect($err)->toBeTrue()->and($msg)->toContain('varias marcas')->toContain((string) $twin->id);

    // Con el id sigue funcionando aunque el nombre este repetido.
    [$err, $accounts] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    expect($err)->toBeFalse()->and($accounts['brand_name'])->toBe('Proyecto A');
});

test('McpBrandName: el nombre de una marca de otro usuario no se resuelve', function () {
    $other = User::factory()->create();
    Brand::factory()->for($other)->create(['name' => 'Ajena']);

    [$err, $msg] = $this->callTool($this->key, 'list_accounts', ['brand' => 'Ajena']);
    expect($err)->toBeTrue()->and($msg)->toContain('no encontrada');
});

test('McpBrandName: los esquemas ofrecen brand y ya no exigen brand_id', function () {
    $tools = collect($this->rpc($this->key, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');

    foreach (['create_post', 'list_accounts', 'get_stats', 'get_best_times', 'generate_report'] as $name) {
        $schema = $tools[$name]['inputSchema'];
        expect($schema['properties'])->toHaveKeys(['brand', 'brand_id'])
            ->and($schema['required'] ?? [])->not->toContain('brand_id');
    }
});
