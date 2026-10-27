<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpClient;

uses(McpClient::class);

// T-45: suggest_hashtags (paridad MCP de C-04a). Mismas reglas que la web: histórico propio, sin IA, marca ajena = inexistente.

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Panadería Sol']);
    $this->key = $this->mcpKey($this->user);
});

function mcpTagged(Brand $brand, string $text, int $interactions, Network $network = Network::Instagram): void
{
    $post = Post::factory()->create(['brand_id' => $brand->id, 'status' => PostStatus::Published, 'text' => $text]);
    $target = PostTarget::factory()->create(['post_id' => $post->id, 'network' => $network, 'status' => PostStatus::Published, 'published_at' => '2026-10-20 10:00:00']);
    PostMetricSnapshot::withoutUserScope()->create([
        'user_id' => $brand->user_id, 'post_target_id' => $target->id, 'social_account_id' => $target->social_account_id,
        'network' => $network->value, 'date' => '2026-10-31', 'metrics' => array_merge(Metric::blankPost(), [Metric::INTERACTIONS => $interactions]),
    ]);
}

test('suggest_hashtags devuelve los hashtags del histórico de la marca (por id o por nombre)', function () {
    mcpTagged($this->brand, 'a #pan #cafe', 100);
    mcpTagged($this->brand, 'b #pan', 60);
    mcpTagged($this->brand, 'c #cafe', 10);

    [$err, $d] = $this->callTool($this->key, 'suggest_hashtags', ['brand_id' => $this->brand->id]);
    expect($err)->toBeFalse()->and($d['available'])->toBeTrue()->and($d['brand_name'])->toBe('Panadería Sol')
        ->and(array_column($d['tags'], 'tag'))->toBe(['pan', 'cafe'])->and($d['tags'][0]['uses'])->toBe(2);

    [$err, $byName] = $this->callTool($this->key, 'suggest_hashtags', ['brand' => 'panadería sol', 'network' => 'instagram']);
    expect($err)->toBeFalse()->and($byName['tags'])->toHaveCount(2);
});

test('una marca ajena responde como inexistente y no filtra sus hashtags', function () {
    $other = Brand::factory()->for(User::factory()->create())->create(['name' => 'Marca secreta']);
    mcpTagged($other, '#secreto', 10);
    mcpTagged($other, '#secreto', 20);

    [$err, $msg] = $this->callTool($this->key, 'suggest_hashtags', ['brand_id' => $other->id]);
    expect($err)->toBeTrue()->and(json_encode($msg))->not->toContain('secret');

    [$err] = $this->callTool($this->key, 'suggest_hashtags', ['brand_id' => 999999]);
    expect($err)->toBeTrue();
});

test('exige la ability stats:read y una red válida', function () {
    [$err] = $this->callTool($this->mcpKey($this->user, ['posts:read']), 'suggest_hashtags', ['brand_id' => $this->brand->id]);
    expect($err)->toBeTrue();

    [$err] = $this->callTool($this->key, 'suggest_hashtags', ['brand_id' => $this->brand->id, 'network' => 'myspace']);
    expect($err)->toBeTrue();
});

test('sin histórico devuelve el estado vacío explícito y no llama a la IA', function () {
    [$err, $d] = $this->callTool($this->key, 'suggest_hashtags', ['brand_id' => $this->brand->id]);

    expect($err)->toBeFalse()->and($d['available'])->toBeFalse()->and($d['tags'])->toBe([])->and($d['reason'])->toContain('Aún no hay datos suficientes');
    Http::assertNothingSent();
});

test('tools/list ofrece 25 herramientas en una sola página, con suggest_hashtags y brand en su esquema', function () {
    $response = $this->rpc($this->key, 'tools/list')->assertOk();
    $tools = collect($response->json('result.tools'))->keyBy('name');

    expect($tools)->toHaveCount(25)->and($tools->has('suggest_hashtags'))->toBeTrue()
        ->and($response->json('result.nextCursor'))->toBeNull()
        ->and($tools['suggest_hashtags']['inputSchema']['properties'])->toHaveKeys(['brand', 'brand_id', 'network']);
});
