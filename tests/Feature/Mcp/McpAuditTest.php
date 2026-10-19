<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\User;
use App\Services\CalendarService;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->key = $this->mcpKey($this->user, ['brands:read', 'posts:read'], 'agente');
    $this->tokenId = $this->user->tokens()->first()->id;
});

test('McpAudit: cada llamada queda registrada con usuario, key, herramienta y resultado', function () {
    $this->callTool($this->key, 'list_brands');

    $row = AuditLog::firstOrFail();
    expect($row->only(['user_id', 'token_id', 'channel', 'action', 'outcome']))->toBe([
        'user_id' => $this->user->id, 'token_id' => $this->tokenId, 'channel' => 'mcp', 'action' => 'list_brands', 'outcome' => 'ok',
    ])->and($row->ip)->not->toBeNull();
});

test('McpAudit: los permisos que faltan y los recursos ajenos quedan como denegados', function () {
    $this->callTool($this->key, 'delete_post', ['post_id' => 1]); // sin posts:write
    $this->callTool($this->key, 'get_post', ['post_id' => 424242]); // inexistente

    expect(AuditLog::orderBy('id')->get()->map(fn ($r) => "{$r->action}:{$r->outcome}")->all())->toBe(['delete_post:denied', 'get_post:denied']);
});

test('McpAudit: los errores de validacion se registran como error', function () {
    $writer = $this->mcpKey($this->user, ['posts:write']);

    $this->callTool($writer, 'create_post', ['brand_id' => $this->brand->id, 'text' => 'x']);

    expect(AuditLog::firstOrFail()->only(['action', 'outcome']))->toBe(['action' => 'create_post', 'outcome' => 'error']);
});

test('McpAudit: el registro no guarda el secreto de la key ni el contenido de la publicacion', function () {
    $writer = $this->mcpKey($this->user, ['posts:write']);

    $this->callTool($writer, 'create_post', ['brand_id' => $this->brand->id, 'text' => 'CONTENIDO-PRIVADO', 'schedule' => false]);

    $dump = json_encode(AuditLog::all());
    expect($dump)->not->toContain($writer)->and($dump)->not->toContain(explode('|', $writer)[1])->and($dump)->not->toContain('CONTENIDO-PRIVADO');
});

test('McpAudit: una peticion sin key valida no deja filas ni ejecuta nada', function () {
    $this->rpc('1|falsa', 'tools/call', ['name' => 'list_brands', 'arguments' => []])->assertUnauthorized();

    expect(AuditLog::count())->toBe(0);
});

test('McpAudit: la fila sobrevive a la revocacion de la key y se conserva sin el usuario (nullOnDelete)', function () {
    $this->callTool($this->key, 'list_brands');
    $this->user->delete();

    expect(AuditLog::firstOrFail()->user_id)->toBeNull();
});

test('McpAudit: un fallo inesperado (Throwable) se audita como error y el cliente recibe un mensaje generico', function () {
    $this->mock(CalendarService::class)->shouldReceive('build')->andThrow(new RuntimeException('SQLSTATE secreto-interno'));

    [$isError, $msg] = $this->callTool($this->key, 'get_calendar');

    $row = AuditLog::firstOrFail();
    expect($isError)->toBeTrue()->and($msg)->not->toContain('SQLSTATE')->and($msg)->toContain('Error interno')
        ->and($row->only(['action', 'outcome']))->toBe(['action' => 'get_calendar', 'outcome' => 'error'])
        ->and($row->detail)->not->toContain('secreto-interno');
});
