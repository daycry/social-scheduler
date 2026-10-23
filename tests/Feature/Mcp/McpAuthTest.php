<?php

use App\Mcp\Abilities;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
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

test('McpAuth: una sesion web (cookie real) NO autentica el MCP (solo Bearer)', function () {
    $user = User::factory()->create(['password' => 'secret-pass-123']);
    $login = $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass-123']);
    $cookies = collect($login->headers->getCookies())->mapWithKeys(fn ($c) => [$c->getName() => $c->getValue()])->all();
    expect($cookies)->toHaveKey(config('session.cookie'));

    // Control: la cookie SI autentica una ruta web...
    $this->call('GET', '/api-keys', [], $cookies, [], ['HTTP_ACCEPT' => 'text/html'])->assertOk();

    // ...pero no el MCP (sin forgetGuards: la peticion lleva solo la cookie, sin Bearer).
    $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []]);
    $this->call('POST', '/mcp', [], $cookies, [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], $body)->assertUnauthorized();
});

test('McpAuth: el limite por IP actua ANTES de la autenticacion (key falsa tambien recibe 429)', function () {
    config(['services.mcp.per_minute_ip' => 3]);

    foreach (range(1, 3) as $_) {
        $this->rpc('999|falsa', 'tools/list')->assertUnauthorized();
    }
    $this->rpc('999|falsa', 'tools/list')->assertStatus(429)->assertHeader('Retry-After');
});

test('McpAuth: los 401 quedan en el log mcp.auth_failed con la IP y sin el token', function () {
    Log::spy();

    $this->rpc('999|secreto-falso', 'tools/list')->assertUnauthorized();

    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => $msg === 'mcp.auth_failed' && ! empty($ctx['ip']) && ! str_contains(json_encode($ctx), 'secreto-falso'))->once();
});

test('McpAuth: Origin ajeno -> 403; sin Origin o con uno permitido -> pasa', function () {
    config(['app.url' => 'https://app.example.com', 'services.mcp.allowed_origins' => ['https://claude.ai']]);
    $key = $this->mcpKey(User::factory()->create());
    $post = fn (?string $origin) => $this->withHeaders($origin ? ['Origin' => $origin] : [])->rpc($key, 'tools/list');

    $post('https://evil.example')->assertForbidden();
    $post('https://app.example.com')->assertOk();
    $post('https://claude.ai')->assertOk();
    $post(null)->assertOk();
});

test('McpAuth: la fecha de caducidad elegida manda dentro del tope; sin ella, el default de 1 ano', function () {
    $user = User::factory()->create();
    config(['sanctum.expiration' => null]);

    $this->actingAs($user)->post(route('api-keys.store', absolute: false), ['name' => 'a', 'abilities' => ['brands:read'], 'expires_at' => now()->addDays(10)->toDateString()])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('api-keys.store', absolute: false), ['name' => 'b', 'abilities' => ['brands:read']])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('api-keys.store', absolute: false), ['name' => 'c', 'abilities' => ['brands:read'], 'expires_at' => now()->addDays(5000)->toDateString()])->assertSessionHasErrors('expires_at');

    $tokens = $user->tokens()->orderBy('id')->get();
    expect($tokens)->toHaveCount(2)
        ->and((int) round(now()->diffInDays($tokens[0]->expires_at, true)))->toBeIn([10, 11]) // fin del dia elegido
        ->and((int) round(now()->diffInDays($tokens[1]->expires_at, true)))->toBe(365);
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

test('McpAuth: los permisos predefinidos son validos, anidados y llegan a la pagina de API keys', function () {
    $presets = Abilities::presets();

    expect(array_keys($presets))->toBe(['read', 'publish', 'all']);
    foreach ($presets as $preset) {
        expect($preset['abilities'])->not->toBeEmpty()->and(array_diff($preset['abilities'], Abilities::keys()))->toBe([]);
    }
    expect($presets['read']['abilities'])->each->toEndWith(':read')
        ->and(array_diff($presets['read']['abilities'], $presets['publish']['abilities']))->toBe([])
        ->and($presets['publish']['abilities'])->toContain(Abilities::POSTS_WRITE)->not->toContain(Abilities::INBOX_WRITE)
        ->and($presets['all']['abilities'])->toBe(Abilities::keys());

    $this->actingAs(User::factory()->create())->get(route('api-keys.index', absolute: false))
        ->assertInertia(fn ($page) => $page->where('presets.publish.label', 'Publicar')
            ->where('presets.read.abilities', $presets['read']['abilities'])
            ->where('endpoint', url('/mcp')));
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

test('McpAuth: serverInfo anuncia el icono de la app (los clientes no muestran el del dominio de ngrok)', function () {
    $key = $this->mcpKey(User::factory()->create());

    $icons = $this->rpc($key, 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']])
        ->assertOk()->json('result.serverInfo.icons');

    expect(collect($icons)->pluck('src')->all())->toContain(url('/icon-512.png'), url('/favicon.svg'))
        ->and(collect($icons)->firstWhere('src', url('/icon-512.png'))['sizes'] ?? null)->toBe(['512x512']);
    foreach (['icon-512.png', 'icon-192.png', 'favicon.svg', 'favicon.ico'] as $file) {
        expect(filesize(public_path($file)))->toBeGreaterThan(0);
    }
});
