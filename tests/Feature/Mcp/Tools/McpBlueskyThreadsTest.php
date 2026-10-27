<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpClient;
use Tests\Support\ThreadsFake;

uses(McpClient::class);

// Paridad MCP transversal (T-72): Bluesky y Threads en list_accounts y create_post, con el mismo MediaValidator que el compositor.

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 08:00:00');
    ThreadsFake::configure();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->bsky = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Bluesky, 'name' => 'ana.example.com']);
    $this->threads = ThreadsFake::account(['brand_id' => $this->brand->id], $this->user, $this->brand);
    $this->key = $this->mcpKey($this->user);
});

test('list_accounts ofrece las cuentas y los tipos de Bluesky y Threads', function () {
    [$err, $data] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    $by = collect($data['accounts'])->keyBy('network');

    expect($err)->toBeFalse()
        ->and($by['bluesky']['content_types'])->toBe(['bluesky_post', 'bluesky_video'])
        ->and($by['threads']['available'])->toBeTrue()
        ->and($by['threads']['content_types'])->toBe(['threads_text', 'threads_image', 'threads_video', 'threads_carousel']);
});

test('create_post valida Threads con el mismo mensaje que el compositor y acepta el texto dentro del limite', function () {
    $args = fn (string $text) => [
        'brand_id' => $this->brand->id, 'text' => $text, 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true,
        'targets' => [['account_id' => $this->threads->id, 'content_type' => 'threads_text']],
    ];

    // 250 banderas: 500 puntos de codigo pero 2.000 bytes; el conteo conservador de Threads las rechaza.
    [$err, $msg] = $this->callTool($this->key, 'create_post', $args(str_repeat("\u{1F1EA}\u{1F1F8}", 250)));
    expect($err)->toBeTrue()->and($msg)->toContain('admite como maximo 500');

    $web = $this->actingAs($this->user)->postJson(route('brands.posts.validate', $this->brand), [
        'text' => str_repeat("\u{1F1EA}\u{1F1F8}", 250), 'schedule' => false, 'media' => [],
        'targets' => [['account_id' => $this->threads->id, 'content_type' => 'threads_text']],
    ])->json('errors');
    expect(json_encode($web, JSON_UNESCAPED_UNICODE))->toContain('admite como maximo 500');

    [$err] = $this->callTool($this->key, 'create_post', $args(str_repeat('a', 500)));
    expect($err)->toBeFalse()->and(Post::withoutUserScope()->count())->toBe(1);
});

test('tools/list ofrece exactamente 25 herramientas y las instrucciones nombran las 6 redes', function () {
    $tools = $this->rpc($this->key, 'tools/list')->assertOk()->json('result.tools');
    expect($tools)->toHaveCount(25);

    $init = $this->rpc($this->key, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']])
        ->json('result.instructions');
    expect($init)->toContain('Instagram', 'Facebook', 'YouTube', 'TikTok', 'Bluesky', 'Threads')
        ->toContain('suggest_hashtags', 'list_calendar_views', 'add_comment_note');
});

test('get_stats y get_best_times aceptan Bluesky y Threads', function () {
    foreach (['bluesky', 'threads'] as $network) {
        [$err] = $this->callTool($this->key, 'get_stats', ['brand_id' => $this->brand->id, 'network' => $network]);
        expect($err)->toBeFalse($network);
        [$err] = $this->callTool($this->key, 'get_best_times', ['brand_id' => $this->brand->id, 'network' => $network]);
        expect($err)->toBeFalse($network);
    }
});
