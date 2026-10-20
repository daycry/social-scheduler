<?php

use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Jobs\SyncAccountComments;
use App\Jobs\SyncInboxComments;
use App\Models\Brand;
use App\Models\InboxComment;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\InboxService;
use App\Services\SocialAccountService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeGraph;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->ig = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => '17841400000000001', 'username' => 'mi_cuenta',
        'access_token' => 'TOKEN-IG', 'scopes' => ['instagram_manage_comments'],
    ]);
    $this->fb = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Facebook, 'external_id' => '555', 'name' => 'Mi Pagina',
        'access_token' => 'TOKEN-FB', 'scopes' => ['pages_read_user_content', 'pages_manage_engagement'],
    ]);
    $this->graph = new FakeGraph;
});

function publishedTarget(SocialAccount $account, string $externalId, ?User $user = null): PostTarget
{
    $post = Post::factory()->create(['brand_id' => $account->brand_id, 'user_id' => $account->user_id, 'text' => 'Mi publicacion']);

    return PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $account->id, 'network' => $account->network, 'status' => PostStatus::Published,
        'external_id' => $externalId, 'external_url' => 'https://example.test/p/'.$externalId, 'published_at' => now()->subDay(),
    ]);
}

function igComments(): array
{
    return ['data' => [
        ['id' => 'c1', 'text' => 'Que bonito!', 'username' => 'ana', 'timestamp' => '2026-10-31T10:00:00+0000', 'hidden' => false, 'replies' => ['data' => [
            ['id' => 'c1r1', 'text' => 'Gracias!', 'username' => 'mi_cuenta', 'timestamp' => '2026-10-31T11:00:00+0000'],
        ]]],
        ['id' => 'c2', 'text' => 'Precio?', 'username' => 'luis', 'timestamp' => '2026-10-31T12:00:00+0000'],
    ]];
}

test('Inbox: sincroniza comentarios de IG con Bearer, campos y respuestas propias (nace leida) sin duplicar', function () {
    $t = publishedTarget($this->ig, '17900000000000001');
    $this->graph->on('GET', '#^17900000000000001/comments$#', igComments());

    $new = app(InboxService::class)->sync($this->ig);
    $again = app(InboxService::class)->sync($this->ig);

    expect($new)->toBe(2)->and($again)->toBe(0)->and(InboxComment::withoutUserScope()->count())->toBe(3);
    $call = $this->graph->calls[0];
    expect($call['authorization'])->toBe('Bearer TOKEN-IG')->and($call['url'])->not->toContain('access_token')
        ->and($call['params']['fields'])->toContain('replies{');

    $own = InboxComment::withoutUserScope()->where('external_id', 'c1r1')->first();
    expect($own->is_own)->toBeTrue()->and($own->read_at)->not->toBeNull()->and($own->parent_external_id)->toBe('c1')
        ->and(InboxComment::withoutUserScope()->where('external_id', 'c2')->first()->read_at)->toBeNull()
        ->and(InboxComment::withoutUserScope()->where('external_id', 'c2')->first()->post_target_id)->toBe($t->id);
});

test('Inbox: sincroniza comentarios de Facebook (autor de la Pagina = propio)', function () {
    publishedTarget($this->fb, '555_777');
    $this->graph->on('GET', '#^555_777/comments$#', ['data' => [
        ['id' => 'f1', 'message' => 'Hola', 'from' => ['id' => '99', 'name' => 'Marta'], 'created_time' => '2026-10-31T10:00:00+0000', 'comments' => ['data' => [
            ['id' => 'f1r', 'message' => 'Hola Marta', 'from' => ['id' => '555', 'name' => 'Mi Pagina'], 'created_time' => '2026-10-31T10:30:00+0000'],
        ]]],
    ]]);

    expect(app(InboxService::class)->sync($this->fb))->toBe(1);
    expect($this->graph->calls[0]['authorization'])->toBe('Bearer TOKEN-FB')->and($this->graph->calls[0]['params']['filter'])->toBe('toplevel');
    expect(InboxComment::withoutUserScope()->where('external_id', 'f1r')->value('is_own'))->toBeTruthy();
});

test('Inbox: sin el permiso la cuenta no se sincroniza (no hay llamadas) y la pagina dice no disponible', function () {
    $this->ig->forceFill(['scopes' => ['instagram_basic']])->save();
    publishedTarget($this->ig, '17900000000000001');

    expect(app(InboxService::class)->sync($this->ig->fresh()))->toBe(0)->and($this->graph->calls)->toBe([]);

    $this->actingAs($this->user)->get(route('inbox.index'))->assertOk()
        ->assertInertia(fn ($p) => $p->component('Inbox/Index')->where('accounts', fn ($a) => $a->firstWhere('network', 'instagram')['can_read'] === false && $a->firstWhere('network', 'facebook')['can_read'] === true));
});

test('Inbox: solo publicaciones propias publicadas, recientes y con id externo', function () {
    publishedTarget($this->ig, '17900000000000001');
    $old = publishedTarget($this->ig, '17900000000000002');
    $old->forceFill(['published_at' => now()->subDays(60)])->save();
    $draft = publishedTarget($this->ig, '17900000000000003');
    $draft->forceFill(['status' => PostStatus::Scheduled])->save();
    $this->graph->on('GET', '#/comments$#', ['data' => []]);

    app(InboxService::class)->sync($this->ig);

    expect(collect($this->graph->calls)->pluck('path')->all())->toBe(['17900000000000001/comments']);
});

test('Inbox: bandeja con filtros de red y no leidos, y marcar leido', function () {
    publishedTarget($this->ig, '17900000000000001');
    publishedTarget($this->fb, '555_777');
    $this->graph->on('GET', '#^17900000000000001/comments$#', igComments());
    $this->graph->on('GET', '#^555_777/comments$#', ['data' => [['id' => 'f1', 'message' => 'Hola', 'from' => ['id' => '99', 'name' => 'Marta'], 'created_time' => '2026-10-31T13:00:00+0000']]]);
    app(InboxService::class)->sync($this->ig);
    app(InboxService::class)->sync($this->fb);

    $this->actingAs($this->user)->get(route('inbox.index'))->assertInertia(fn ($p) => $p->has('comments.data', 3)->where('unread_count', 3)
        ->where('comments.data.0.author', 'Marta')->where('comments.data.2.my_replies.0.text', 'Gracias!'));
    $this->actingAs($this->user)->get(route('inbox.index', ['network' => 'facebook']))->assertInertia(fn ($p) => $p->has('comments.data', 1));

    $c2 = InboxComment::withoutUserScope()->where('external_id', 'c2')->first();
    $this->actingAs($this->user)->post(route('inbox.read', $c2))->assertRedirect();
    $this->actingAs($this->user)->get(route('inbox.index', ['unread' => 1]))->assertInertia(fn ($p) => $p->has('comments.data', 2));

    $this->actingAs($this->user)->post(route('inbox.read-all'))->assertRedirect();
    expect(app(InboxService::class)->unreadCount($this->user))->toBe(0);
});

test('Inbox: responder publica en Graph (IG /replies, FB /comments), guarda la respuesta y marca leido', function () {
    publishedTarget($this->ig, '17900000000000001');
    $this->graph->on('GET', '#/comments$#', igComments());
    app(InboxService::class)->sync($this->ig);
    $c2 = InboxComment::withoutUserScope()->where('external_id', 'c2')->first();

    $this->graph->on('POST', '#^c2/replies$#', ['id' => 'c2r']);
    $this->actingAs($this->user)->post(route('inbox.reply', $c2), ['text' => 'Escribenos por privado'])->assertSessionHasNoErrors();

    $post = $this->graph->callsTo('POST', '#^c2/replies$#')[0];
    expect($post['params'])->toBe(['message' => 'Escribenos por privado'])->and($post['authorization'])->toBe('Bearer TOKEN-IG');
    $reply = InboxComment::withoutUserScope()->where('external_id', 'c2r')->first();
    expect($reply->is_own)->toBeTrue()->and($reply->parent_external_id)->toBe('c2')->and($c2->fresh()->read_at)->not->toBeNull();

    // Facebook: POST /{comment}/comments
    $fbComment = new InboxComment(['network' => Network::Facebook, 'external_id' => 'f1', 'text' => 'Hola', 'is_own' => false, 'hidden' => false]);
    $fbComment->user_id = $this->user->id;
    $fbComment->brand_id = $this->brand->id;
    $fbComment->social_account_id = $this->fb->id;
    $fbComment->save();
    $this->graph->on('POST', '#^f1/comments$#', ['id' => 'f1r']);
    app(InboxService::class)->reply($this->user, $fbComment, 'Hola!');
    expect($this->graph->callsTo('POST', '#^f1/comments$#'))->toHaveCount(1);
});

test('Inbox: ocultar y mostrar usan hide (IG) / is_hidden (FB) y reflejan el estado', function () {
    publishedTarget($this->ig, '17900000000000001');
    $this->graph->on('GET', '#/comments$#', igComments());
    app(InboxService::class)->sync($this->ig);
    $c2 = InboxComment::withoutUserScope()->where('external_id', 'c2')->first();
    $this->graph->on('POST', '#^c2$#', ['success' => true]);

    $this->actingAs($this->user)->post(route('inbox.hide', $c2), ['hidden' => true])->assertSessionHasNoErrors();
    expect($c2->fresh()->hidden)->toBeTrue()->and($this->graph->callsTo('POST', '#^c2$#')[0]['params'])->toBe(['hide' => 'true']);

    $this->actingAs($this->user)->post(route('inbox.hide', $c2), ['hidden' => false]);
    expect($c2->fresh()->hidden)->toBeFalse()->and($this->graph->callsTo('POST', '#^c2$#')[1]['params'])->toBe(['hide' => 'false']);
});

test('Inbox: sin permiso de moderacion responder falla con instruccion y sin llamar a la red', function () {
    $this->ig->forceFill(['scopes' => []])->save();
    $c = new InboxComment(['network' => Network::Instagram, 'external_id' => 'c9', 'text' => 'x', 'is_own' => false, 'hidden' => false]);
    $c->user_id = $this->user->id;
    $c->brand_id = $this->brand->id;
    $c->social_account_id = $this->ig->id;
    $c->save();

    $this->actingAs($this->user)->post(route('inbox.reply', $c), ['text' => 'hola'])->assertSessionHasErrors('account');
    expect($this->graph->calls)->toBe([])->and(InboxComment::withoutUserScope()->where('is_own', true)->count())->toBe(0);
});

test('Inbox: un error de Meta al responder se muestra legible y no guarda nada', function () {
    $c = new InboxComment(['network' => Network::Instagram, 'external_id' => 'c9', 'text' => 'x', 'is_own' => false, 'hidden' => false]);
    $c->user_id = $this->user->id;
    $c->brand_id = $this->brand->id;
    $c->social_account_id = $this->ig->id;
    $c->save();
    $this->graph->on('POST', '#^c9/replies$#', FakeGraph::error(190, 'Invalid OAuth access token', null, 401));

    $this->actingAs($this->user)->post(route('inbox.reply', $c), ['text' => 'hola'])->assertSessionHasErrors('account');
    expect($this->ig->fresh()->status)->toBe(SocialAccount::STATUS_RECONNECT)->and(InboxComment::withoutUserScope()->where('is_own', true)->count())->toBe(0);
});

test('Inbox: aislamiento (comentarios de otro usuario no se ven ni se tocan; el id ajeno es 404)', function () {
    $other = User::factory()->create();
    $ob = Brand::factory()->for($other)->create();
    $oa = SocialAccount::factory()->for($other)->create(['brand_id' => $ob->id, 'network' => Network::Instagram, 'scopes' => ['instagram_manage_comments']]);
    $theirs = new InboxComment(['network' => Network::Instagram, 'external_id' => 'x1', 'text' => 'ajeno', 'author_name' => 'zed', 'is_own' => false, 'hidden' => false]);
    $theirs->user_id = $other->id;
    $theirs->brand_id = $ob->id;
    $theirs->social_account_id = $oa->id;
    $theirs->save();

    $this->actingAs($this->user)->get(route('inbox.index'))->assertInertia(fn ($p) => $p->has('comments.data', 0)->where('unread_count', 0));
    $this->actingAs($this->user)->post(route('inbox.read', $theirs))->assertNotFound();
    $this->actingAs($this->user)->post(route('inbox.reply', $theirs), ['text' => 'x'])->assertNotFound();
    $this->actingAs($this->user)->post(route('inbox.hide', $theirs))->assertNotFound();
    expect($theirs->fresh()->read_at)->toBeNull()->and($this->graph->calls)->toBe([])
        ->and(fn () => app(InboxService::class)->markRead($this->user, $theirs))->toThrow(PostValidationException::class);
});

test('Inbox: los jobs despachan por cuenta activa de IG/FB en la cola poll y los transitorios reintentan', function () {
    Queue::fake();
    (new SyncInboxComments)->handle();
    Queue::assertPushed(SyncAccountComments::class, 2);
    Queue::assertPushedOn('poll', SyncAccountComments::class);

    $tt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok]);
    Queue::fake();
    (new SyncInboxComments($this->user->id))->handle();
    Queue::assertPushed(SyncAccountComments::class, 2);
    expect($tt->id)->not->toBeNull();
});

test('Inbox: el job sincroniza y un fallo transitorio de Meta no rompe (se libera para reintento)', function () {
    publishedTarget($this->ig, '17900000000000001');
    $this->graph->on('GET', '#/comments$#', new TransientPublishException('red', 'red'));

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->once()->with(120);
    $job = new SyncAccountComments($this->ig->id);
    $job->setJob($queueJob);
    $job->handle(app(InboxService::class), app(SocialAccountService::class));

    expect(InboxComment::withoutUserScope()->count())->toBe(0);
});

test('Inbox MCP: list_comments y reply_comment exigen su ability y respetan el aislamiento', function () {
    publishedTarget($this->ig, '17900000000000001');
    $this->graph->on('GET', '#/comments$#', igComments());
    app(InboxService::class)->sync($this->ig);
    $c2 = InboxComment::withoutUserScope()->where('external_id', 'c2')->first();

    $readOnly = $this->mcpKey($this->user, ['inbox:read']);
    [$err, $list] = $this->callTool($readOnly, 'list_comments', ['unread' => true]);
    expect($err)->toBeFalse()->and(collect($list['comments'])->pluck('author')->all())->toBe(['luis', 'ana']);

    [$err] = $this->callTool($readOnly, 'reply_comment', ['comment_id' => $c2->id, 'text' => 'hola']);
    expect($err)->toBeTrue()->and($this->graph->callsTo('POST', '#.*#'))->toBe([]);

    $writer = $this->mcpKey($this->user, ['inbox:write']);
    $this->graph->on('POST', '#^c2/replies$#', ['id' => 'c2r']);
    [$err, $res] = $this->callTool($writer, 'reply_comment', ['comment_id' => $c2->id, 'text' => 'hola']);
    expect($err)->toBeFalse()->and($res['replied'])->toBeTrue();

    $other = User::factory()->create();
    [$err, $msg] = $this->callTool($this->mcpKey($other), 'reply_comment', ['comment_id' => $c2->id, 'text' => 'intruso']);
    expect($err)->toBeTrue()->and($msg)->toContain('no encontrada');
    [, $none] = $this->callTool($this->mcpKey($other), 'list_comments');
    expect($none['comments'])->toBe([]);
});
