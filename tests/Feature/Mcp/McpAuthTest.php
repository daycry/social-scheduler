<?php

use App\Models\AuditLog;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\McpClient;

uses(McpClient::class);

test('McpAuth: key valida inicializa y lista las herramientas (API-01)', function () {
    $key = $this->mcpKey(User::factory()->create());

    $this->rpc($key, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']])
        ->assertOk()->assertJsonPath('result.serverInfo.name', 'Social Scheduler');

    $names = collect($this->rpc($key, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->all();

    expect($names)->toContain('list_brands', 'list_accounts', 'create_post', 'update_post', 'reschedule_post', 'cancel_post', 'delete_post', 'get_post', 'get_calendar', 'list_media');
});

test('McpAuth: sin key o con key invalida responde 401 y no ejecuta herramientas (API-02)', function (?string $key) {
    $r = $this->rpc($key, 'tools/call', ['name' => 'list_brands', 'arguments' => []]);

    $r->assertUnauthorized();
    expect($r->getContent())->not->toContain('"brands"');
})->with([[null], ['123|noexiste'], ['basura']]);

test('McpAuth: una key revocada deja de funcionar al instante (E2E-12)', function () {
    $user = User::factory()->create();
    $key = $this->mcpKey($user);
    $this->rpc($key, 'tools/list')->assertOk();

    $this->actingAs($user)->delete(route('api-keys.destroy', $user->tokens()->first()->id, absolute: false))->assertRedirect();

    $this->rpc($key, 'tools/list')->assertUnauthorized();
});

test('McpAuth: una key caducada responde 401', function () {
    $user = User::factory()->create();
    $key = $user->createToken('vieja', ['*'], now()->subMinute())->plainTextToken;

    $this->rpc($key, 'tools/list')->assertUnauthorized();
});

test('McpAuth: una sesion web NO autentica el MCP (solo Bearer)', function () {
    $this->actingAs(User::factory()->create());

    $this->rpc(null, 'tools/list')->assertUnauthorized();
});

test('McpAuth: la key se guarda con hash y el secreto solo se muestra una vez', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('api-keys.store', absolute: false), ['name' => 'Claude', 'abilities' => ['brands:read']])
        ->assertRedirect(route('api-keys.index', absolute: false));
    $plain = session('new_key');
    $row = PersonalAccessToken::first();

    expect($plain)->toContain('|msk_')->and($row->name)->toBe('Claude')->and($row->abilities)->toBe(['brands:read'])
        ->and($row->token)->not->toBe($plain)->and($plain)->not->toContain($row->token)
        ->and($row->token)->toHaveLength(64) // sha256
        ->and($row->expires_at)->not->toBeNull();

    // Se ve en la pagina a la que redirige (flash) y despues ya no: la lista no vuelve a exponer el secreto.
    $this->actingAs($user)->get(route('api-keys.index', absolute: false))->assertInertia(fn ($page) => $page->where('newKey', $plain));
    $this->actingAs($user)->get(route('api-keys.index', absolute: false))
        ->assertInertia(fn ($page) => $page->component('ApiKeys/Index')->where('newKey', null)->has('keys', 1)->missing('keys.0.token'));
});

test('McpAuth: crear y revocar keys requiere sesion y valida los permisos', function () {
    $this->post(route('api-keys.store', absolute: false), ['name' => 'x', 'abilities' => ['*']])->assertRedirect('/login');

    $this->actingAs(User::factory()->create())
        ->post(route('api-keys.store', absolute: false), ['name' => 'x', 'abilities' => ['*']])->assertSessionHasErrors('abilities.0');
});

test('McpAuth: un usuario no puede revocar la key de otro (404)', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $this->mcpKey($a);

    $this->actingAs($b)->delete(route('api-keys.destroy', $a->tokens()->first()->id, absolute: false))->assertNotFound();

    expect($a->tokens()->count())->toBe(1);
});

test('McpAuth: crear y revocar quedan en el registro de uso sin el secreto', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('api-keys.store', absolute: false), ['name' => 'Claude', 'abilities' => ['posts:read']]);
    $plain = session('new_key');
    $this->actingAs($user)->delete(route('api-keys.destroy', $user->tokens()->first()->id, absolute: false));

    expect(AuditLog::pluck('action')->all())->toBe(['api_key.created', 'api_key.revoked'])
        ->and(json_encode(AuditLog::all()))->not->toContain($plain);
});

test('McpAuth: al borrar el usuario se borran sus keys', function () {
    $user = User::factory()->create();
    $this->mcpKey($user);
    $user->delete();

    expect(PersonalAccessToken::count())->toBe(0);
});

test('McpAuth: rate limit por key devuelve 429', function () {
    config(['services.mcp.per_minute' => 3]);
    $key = $this->mcpKey(User::factory()->create());

    foreach (range(1, 3) as $_) {
        $this->rpc($key, 'tools/list')->assertOk();
    }
    $this->rpc($key, 'tools/list')->assertStatus(429);

    // Otra key no comparte el limite.
    $this->rpc($this->mcpKey(User::factory()->create()), 'tools/list')->assertOk();
});
