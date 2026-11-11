<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\McpClient;

uses(McpClient::class);

// T-06 (revision 2): un destino marcado `media_inexistente` por la migracion no se reintenta con la lista recortada, por ningun canal.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-09 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->key = $this->mcpKey($this->user);
    $this->broken = function (string $code = 'media_inexistente'): Post {
        $post = app(PostService::class)->create($this->user, $this->brand, ['text' => 'x', 'schedule' => true, 'scheduled_at' => '2026-11-12T10:00', 'media' => [], 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']]]);
        $post->targets()->update(['status' => 'fallida', 'error_code' => $code, 'error_message' => 'Falta el archivo 7 de la media de este destino: editalo y vuelve a elegir la media.']);
        $post->syncStatus();

        return $post->fresh();
    };
});

test('reintentar un destino con media inexistente se rechaza por web, API y MCP; otro fallo sigue reintentandose', function () {
    $web = ($this->broken)();
    $api = ($this->broken)();
    $mcp = ($this->broken)();

    $this->actingAs($this->user)->postJson(route('workflow.retry', $web->id))->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'media'));
    app('auth')->forgetGuards();
    $this->withToken($this->key)->postJson("/api/v1/posts/{$api->id}/retry")->assertStatus(422);
    [$err] = $this->callTool($this->key, 'retry_post', ['post_id' => $mcp->id]);
    expect($err)->toBeTrue();
    Queue::assertNothingPushed();
    expect($web->targets()->first()->status->value)->toBe('fallida');

    $other = ($this->broken)('reintentos_agotados');
    $this->actingAs($this->user)->postJson(route('workflow.retry', $other->id))->assertOk()->assertJsonPath('retried', 1);
});

test('can_retry es falso para un destino con media inexistente y explica el motivo', function () {
    ($this->broken)();
    $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'week', 'date' => '2026-11-12']))->assertInertia(fn ($page) => $page
        ->where('items.0.can_retry', false)->where('items.0.retry_blocked', fn ($m) => str_contains($m, 'media')));
});

test('editar la media del destino limpia el bloqueo', function () {
    $post = ($this->broken)();
    app(PostService::class)->update($this->user, $post, ['text' => 'x', 'schedule' => true, 'scheduled_at' => '2026-11-12T10:00', 'media' => [], 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']]]);
    expect($post->targets()->first()->error_code)->not->toBe('media_inexistente');
});
