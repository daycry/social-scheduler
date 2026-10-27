<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\InboxComment;
use App\Models\InboxCommentNote;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\InboxService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGraph;
use Tests\Support\McpClient;

uses(McpClient::class);

// C-04d · CA-17 de extremo a extremo: la nota sobrevive a la resincronización del inbox (upsert) y MCP la devuelve.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $brand = Brand::factory()->for($this->user)->create();
    $this->ig = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $brand->id, 'network' => Network::Instagram, 'external_id' => '17841400000000001', 'username' => 'mi_cuenta',
        'access_token' => 'TOKEN-IG', 'scopes' => ['instagram_manage_comments'],
    ]);
    $post = Post::factory()->create(['brand_id' => $brand->id, 'user_id' => $this->user->id, 'text' => 'Mi publicacion']);
    PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $this->ig->id, 'network' => Network::Instagram, 'status' => PostStatus::Published,
        'external_id' => '17900000000000001', 'published_at' => now()->subDay(),
    ]);
    $this->graph = new FakeGraph;
    $this->graph->on('GET', '#^17900000000000001/comments$#', ['data' => [
        ['id' => 'c1', 'text' => 'Que bonito!', 'username' => 'ana', 'timestamp' => '2026-10-31T10:00:00+0000'],
    ]]);
});

test('CA-17: la nota sobrevive a dos resincronizaciones, sin pérdida ni duplicados', function () {
    app(InboxService::class)->sync($this->ig);
    $comment = InboxComment::withoutUserScope()->where('external_id', 'c1')->firstOrFail();

    $this->actingAs($this->user)->post(route('inbox.notes.store', $comment->id), ['body' => 'Pasar a ventas'])->assertRedirect();

    // El upsert actualiza la fila existente (mismo external_id), no la sustituye.
    app(InboxService::class)->sync($this->ig);
    app(InboxService::class)->sync($this->ig);

    $after = InboxComment::withoutUserScope()->where('external_id', 'c1')->firstOrFail();
    expect(InboxComment::withoutUserScope()->count())->toBe(1)
        ->and($after->id)->toBe($comment->id)
        ->and(InboxCommentNote::withoutUserScope()->where('inbox_comment_id', $comment->id)->pluck('body')->all())->toBe(['Pasar a ventas']);
});

test('CA-17: list_comments devuelve la nota tras el resync y add_comment_note añade otra', function () {
    app(InboxService::class)->sync($this->ig);
    $comment = InboxComment::withoutUserScope()->where('external_id', 'c1')->firstOrFail();
    $key = $this->mcpKey($this->user);

    $this->actingAs($this->user)->post(route('inbox.notes.store', $comment->id), ['body' => 'Primera'])->assertRedirect();
    app(InboxService::class)->sync($this->ig);
    app(InboxService::class)->sync($this->ig);

    [, $list] = $this->callTool($key, 'list_comments');
    expect(collect($list['comments'][0]['notes'])->pluck('body')->all())->toBe(['Primera']);

    [$err] = $this->callTool($key, 'add_comment_note', ['comment_id' => $comment->id, 'body' => 'Segunda']);
    [, $list] = $this->callTool($key, 'list_comments');

    expect($err)->toBeFalse()->and(collect($list['comments'][0]['notes'])->pluck('body')->all())->toBe(['Primera', 'Segunda']);
});
