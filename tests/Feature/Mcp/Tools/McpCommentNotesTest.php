<?php

use App\Models\AuditLog;
use App\Models\InboxComment;
use App\Models\InboxCommentNote;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpClient;

uses(McpClient::class);

// C-04d · CA-17 por MCP: list_comments devuelve las notas y add_comment_note añade una (inbox:write).

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->comment = InboxComment::factory()->create(['user_id' => $this->user->id, 'text' => 'Hola']);
    $this->key = $this->mcpKey($this->user);
});

test('list_comments incluye las notas de cada comentario', function () {
    InboxCommentNote::factory()->create(['user_id' => $this->user->id, 'inbox_comment_id' => $this->comment->id, 'body' => 'una']);
    InboxCommentNote::factory()->create(['user_id' => $this->user->id, 'inbox_comment_id' => $this->comment->id, 'body' => 'dos']);

    [$err, $res] = $this->callTool($this->key, 'list_comments');

    expect($err)->toBeFalse()
        ->and(collect($res['comments'][0]['notes'])->pluck('body')->all())->toBe(['una', 'dos'])
        ->and($res['comments'][0]['notes'][0])->toHaveKeys(['id', 'body', 'created_at']);
});

test('add_comment_note añade una nota y list_comments la devuelve', function () {
    [$err, $res] = $this->callTool($this->key, 'add_comment_note', ['comment_id' => $this->comment->id, 'body' => 'Revisar con diseño']);

    expect($err)->toBeFalse()->and($res['comment_id'])->toBe($this->comment->id)->and($res['body'])->toBe('Revisar con diseño');
    expect(InboxCommentNote::withoutUserScope()->where('inbox_comment_id', $this->comment->id)->value('body'))->toBe('Revisar con diseño');

    [, $list] = $this->callTool($this->key, 'list_comments');
    expect($list['comments'][0]['notes'][0]['body'])->toBe('Revisar con diseño');
});

test('add_comment_note exige inbox:write', function () {
    $read = $this->mcpKey($this->user, ['inbox:read'], 'solo-lectura');

    [$err, $msg] = $this->callTool($read, 'add_comment_note', ['comment_id' => $this->comment->id, 'body' => 'x']);

    expect($err)->toBeTrue()->and($msg)->toContain('inbox:write')
        ->and(InboxCommentNote::withoutUserScope()->count())->toBe(0);
});

test('un comentario ajeno responde como inexistente y no se crea nada', function () {
    $foreign = InboxComment::factory()->create();

    [$err, $msg] = $this->callTool($this->key, 'add_comment_note', ['comment_id' => $foreign->id, 'body' => 'x']);
    [$errMissing, $msgMissing] = $this->callTool($this->key, 'add_comment_note', ['comment_id' => 999999, 'body' => 'x']);

    expect($err)->toBeTrue()->and($errMissing)->toBeTrue()->and($msg)->toBe($msgMissing)
        ->and(InboxCommentNote::withoutUserScope()->count())->toBe(0);
});

test('valida el cuerpo: obligatorio y de hasta 2.000 caracteres', function () {
    [$e1] = $this->callTool($this->key, 'add_comment_note', ['comment_id' => $this->comment->id, 'body' => '  ']);
    [$e2] = $this->callTool($this->key, 'add_comment_note', ['comment_id' => $this->comment->id, 'body' => str_repeat('a', 2001)]);
    [$e3] = $this->callTool($this->key, 'add_comment_note', ['comment_id' => $this->comment->id, 'body' => str_repeat('a', 2000)]);

    expect($e1)->toBeTrue()->and($e2)->toBeTrue()->and($e3)->toBeFalse();
});

test('la auditoría registra la llamada pero nunca el cuerpo de la nota', function () {
    $this->callTool($this->key, 'add_comment_note', ['comment_id' => $this->comment->id, 'body' => 'CUERPO-SECRETO']);

    $rows = AuditLog::query()->where('action', 'add_comment_note')->get();
    expect($rows)->toHaveCount(1)->and($rows->toJson())->not->toContain('CUERPO-SECRETO');
});

test('tools/list ofrece 25 herramientas en una sola página', function () {
    $response = $this->rpc($this->key, 'tools/list');
    $names = collect($response->json('result.tools'))->pluck('name');

    expect($names)->toHaveCount(25)->toContain('add_comment_note', 'list_comments')->and($response->json('result.nextCursor'))->toBeNull();
});

test('add_comment_note rechaza un comentario propio (is_own)', function () {
    $own = InboxComment::factory()->create(['user_id' => $this->user->id, 'is_own' => true]);

    [$err] = $this->callTool($this->key, 'add_comment_note', ['comment_id' => $own->id, 'body' => 'x']);

    expect($err)->toBeTrue();
});
