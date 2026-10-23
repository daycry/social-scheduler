<?php

use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\Comments;
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
use Illuminate\Support\Facades\Cache;
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

function inboxPublishedTarget(SocialAccount $account, string $externalId, ?User $user = null): PostTarget
{
    $post = Post::factory()->create(['brand_id' => $account->brand_id, 'user_id' => $account->user_id, 'text' => 'Mi publicacion']);

    return PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $account->id, 'network' => $account->network, 'status' => PostStatus::Published,
        'external_id' => $externalId, 'external_url' => 'https://example.test/p/'.$externalId, 'published_at' => now()->subDay(),
    ]);
}

function inboxIgComments(): array
{
    return ['data' => [
        ['id' => 'c1', 'text' => 'Que bonito!', 'username' => 'ana', 'timestamp' => '2026-10-31T10:00:00+0000', 'hidden' => false, 'replies' => ['data' => [
            ['id' => 'c1r1', 'text' => 'Gracias!', 'username' => 'mi_cuenta', 'timestamp' => '2026-10-31T11:00:00+0000'],
        ]]],
        ['id' => 'c2', 'text' => 'Precio?', 'username' => 'luis', 'timestamp' => '2026-10-31T12:00:00+0000'],
    ]];
}

test('Inbox: sincroniza comentarios de IG con Bearer, campos y respuestas propias (nace leida) sin duplicar', function () {
    $t = inboxPublishedTarget($this->ig, '17900000000000001');
    $this->graph->on('GET', '#^17900000000000001/comments$#', inboxIgComments());

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
    inboxPublishedTarget($this->fb, '555_777');
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
    inboxPublishedTarget($this->ig, '17900000000000001');

    expect(app(InboxService::class)->sync($this->ig->fresh()))->toBe(0)->and($this->graph->calls)->toBe([]);

    $this->actingAs($this->user)->get(route('inbox.index'))->assertOk()
        ->assertInertia(fn ($p) => $p->component('Inbox/Index')->where('accounts', fn ($a) => $a->firstWhere('network', 'instagram')['can_read'] === false && $a->firstWhere('network', 'facebook')['can_read'] === true));
});

test('Inbox: solo publicaciones propias publicadas, recientes y con id externo', function () {
    inboxPublishedTarget($this->ig, '17900000000000001');
    $old = inboxPublishedTarget($this->ig, '17900000000000002');
    $old->forceFill(['published_at' => now()->subDays(60)])->save();
    $draft = inboxPublishedTarget($this->ig, '17900000000000003');
    $draft->forceFill(['status' => PostStatus::Scheduled])->save();
    $this->graph->on('GET', '#/comments$#', ['data' => []]);

    app(InboxService::class)->sync($this->ig);

    expect(collect($this->graph->calls)->pluck('path')->all())->toBe(['17900000000000001/comments']);
});

test('Inbox: bandeja con filtros de red y no leidos, y marcar leido', function () {
    inboxPublishedTarget($this->ig, '17900000000000001');
    inboxPublishedTarget($this->fb, '555_777');
    $this->graph->on('GET', '#^17900000000000001/comments$#', inboxIgComments());
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
    inboxPublishedTarget($this->ig, '17900000000000001');
    $this->graph->on('GET', '#/comments$#', inboxIgComments());
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
    inboxPublishedTarget($this->ig, '17900000000000001');
    $this->graph->on('GET', '#/comments$#', inboxIgComments());
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
    inboxPublishedTarget($this->ig, '17900000000000001');
    $this->graph->on('GET', '#/comments$#', new TransientPublishException('red', 'red'));

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->once()->with(120);
    $job = new SyncAccountComments($this->ig->id);
    $job->setJob($queueJob);
    $job->handle(app(InboxService::class), app(SocialAccountService::class));

    expect(InboxComment::withoutUserScope()->count())->toBe(0);
});

test('Inbox MCP: list_comments y reply_comment exigen su ability y respetan el aislamiento', function () {
    inboxPublishedTarget($this->ig, '17900000000000001');
    $this->graph->on('GET', '#/comments$#', inboxIgComments());
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

test('Inbox: B4 sigue paging.next en comentarios y en el hilo de respuestas, mas recientes primero y con tope', function () {
    inboxPublishedTarget($this->ig, '17900000000000001');
    $page1 = ['data' => [
        ['id' => 'p1', 'text' => 'uno', 'username' => 'ana', 'timestamp' => '2026-10-31T10:00:00+0000', 'replies' => ['data' => [
            ['id' => 'p1r1', 'text' => 'r1', 'username' => 'bob', 'timestamp' => '2026-10-31T10:30:00+0000'],
        ], 'paging' => ['cursors' => ['after' => 'RC1'], 'next' => 'https://graph.facebook.com/next-replies']]],
    ], 'paging' => ['cursors' => ['after' => 'PAGE2'], 'next' => 'https://graph.facebook.com/next']];
    $page2 = ['data' => [['id' => 'p2', 'text' => 'dos', 'username' => 'luis', 'timestamp' => '2026-10-31T12:00:00+0000']], 'paging' => ['cursors' => ['after' => 'PAGE3']]];
    $this->graph->on('GET', '#^17900000000000001/comments$#', FakeGraph::seq($page1, $page2));
    $this->graph->on('GET', '#^p1/replies$#', ['data' => [['id' => 'p1r2', 'text' => 'r2', 'username' => 'eva', 'timestamp' => '2026-10-31T10:45:00+0000']]]);

    $rows = app(Comments::class)->fetch($this->ig, '17900000000000001');

    expect(array_column($rows, 'external_id'))->toBe(['p2', 'p1r2', 'p1r1', 'p1'])
        ->and($this->graph->callsTo('GET', '#^17900000000000001/comments$#')[1]['params']['after'])->toBe('PAGE2')
        ->and($this->graph->callsTo('GET', '#^p1/replies$#')[0]['params']['after'])->toBe('RC1');

    config(['social.inbox.max_pages' => 1]);
    $this->graph->calls = [];
    $this->graph->on('GET', '#^17900000000000001/comments$#', $page1);
    expect($this->graph->callsTo('GET', '#comments$#'))->toBe([]);
    app(Comments::class)->fetch($this->ig, '17900000000000001');
    expect($this->graph->callsTo('GET', '#^17900000000000001/comments$#'))->toHaveCount(1); // tope respetado
});

test('Inbox: B5 el job respeta el presupuesto de tiempo y se reencola con el desplazamiento', function () {
    Queue::fake();
    inboxPublishedTarget($this->ig, '17900000000000001');
    inboxPublishedTarget($this->ig, '17900000000000002');
    $this->graph->on('GET', '#/comments$#', inboxIgComments());
    config(['social.inbox.budget_seconds' => -1]); // presupuesto agotado tras la primera publicacion

    $job = new SyncAccountComments($this->ig->id);
    $job->handle(app(InboxService::class), app(SocialAccountService::class));

    expect($this->graph->callsTo('GET', '#/comments$#'))->toHaveCount(1);
    Queue::assertPushed(SyncAccountComments::class, fn ($j) => $j->accountId === $this->ig->id && $j->offset === 1);

    config(['social.inbox.budget_seconds' => 60]);
    Queue::fake();
    (new SyncAccountComments($this->ig->id, 1))->handle(app(InboxService::class), app(SocialAccountService::class));
    Queue::assertNotPushed(SyncAccountComments::class);
});

test('Inbox: B6/B7 una respuesta sin id de Meta no guarda un own- duplicable y la carrera con la sync no da 500', function () {
    inboxPublishedTarget($this->ig, '17900000000000001');
    $this->graph->on('GET', '#/comments$#', inboxIgComments());
    app(InboxService::class)->sync($this->ig);
    $c2 = InboxComment::withoutUserScope()->where('external_id', 'c2')->first();

    $this->graph->on('POST', '#^c2/replies$#', FakeGraph::seq(['id' => ''], ['id' => 'c2r']));
    $this->actingAs($this->user)->post(route('inbox.reply', $c2), ['text' => 'Hola'])->assertSessionHasNoErrors();
    expect(InboxComment::withoutUserScope()->where('is_own', true)->where('external_id', 'like', 'own-%')->count())->toBe(0);

    // Carrera: la sync ya trajo la respuesta con su id real antes de que `reply()` la guarde -> se reutiliza, sin duplicar ni 500.
    $row = new InboxComment(['network' => Network::Instagram, 'external_id' => 'c2r', 'parent_external_id' => 'c2', 'text' => 'Hola', 'is_own' => true, 'hidden' => false]);
    $row->user_id = $this->user->id;
    $row->brand_id = $this->brand->id;
    $row->social_account_id = $this->ig->id;
    $row->save();
    $reply = app(InboxService::class)->reply($this->user, $c2->fresh(), 'Hola otra vez');
    expect($reply->external_id)->toBe('c2r')->and(InboxComment::withoutUserScope()->where('external_id', 'c2r')->count())->toBe(1);
});

test('Inbox: marcar todo leido valida la red (un array da 422)', function () {
    $this->actingAs($this->user)->postJson(route('inbox.read-all'), ['network' => ['instagram']])->assertStatus(422);
});

test('Inbox: N3 el presupuesto se comprueba entre paginas de comentarios y de respuestas y corta guardando lo leido', function () {
    inboxPublishedTarget($this->ig, '17900000000000001');
    $mk = fn (string $id, string $after) => ['data' => [
        ['id' => $id, 'text' => $id, 'username' => 'ana', 'timestamp' => '2026-10-31T10:00:00+0000', 'replies' => ['data' => [], 'paging' => ['cursors' => ['after' => 'RC'], 'next' => 'https://graph.facebook.com/nr']]],
    ], 'paging' => ['cursors' => ['after' => $after], 'next' => 'https://graph.facebook.com/n']];
    $this->graph->on('GET', '#^17900000000000001/comments$#', FakeGraph::seq($mk('q1', 'A2'), $mk('q2', 'A3'), $mk('q3', 'A4')));
    $this->graph->on('GET', '#/replies$#', ['data' => []]);

    // Sin hueco para otra llamada: solo la primera pagina de comentarios y ninguna de respuestas.
    $rows = app(Comments::class)->fetch($this->ig, '17900000000000001', microtime(true) - 1);

    expect(array_column($rows, 'external_id'))->toBe(['q1'])
        ->and($this->graph->callsTo('GET', '#^17900000000000001/comments$#'))->toHaveCount(1)
        ->and($this->graph->callsTo('GET', '#/replies$#'))->toBe([]);

    // Con tiempo de sobra (y max_pages = 5) sigue paginando.
    $this->graph->calls = [];
    $this->graph->on('GET', '#^17900000000000001/comments$#', FakeGraph::seq($mk('q1', 'A2'), $mk('q2', 'A3'), $mk('q3', 'A4')));
    app(Comments::class)->fetch($this->ig, '17900000000000001', microtime(true) + 600);
    expect(count($this->graph->callsTo('GET', '#^17900000000000001/comments$#')))->toBeGreaterThan(1);
});

test('Inbox: N4 el reencolado con offset tiene unicidad propia y dos pasadas de la misma cuenta no se solapan', function () {
    expect((new SyncAccountComments($this->ig->id, 0))->uniqueId())->not->toBe((new SyncAccountComments($this->ig->id, 25))->uniqueId());

    Queue::fake();
    inboxPublishedTarget($this->ig, '17900000000000001');
    $this->graph->on('GET', '#/comments$#', ['data' => []]);

    $lock = Cache::lock('sync-account-comments:'.$this->ig->id, 120);
    expect($lock->get())->toBeTrue();

    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('release')->once()->with(30);
    $job = new SyncAccountComments($this->ig->id, 25);
    $job->setJob($queued);
    $job->handle(app(InboxService::class), app(SocialAccountService::class));
    expect($this->graph->callsTo('GET', '#/comments$#'))->toBe([]); // no leyo: la otra pasada tiene el lock

    $lock->release();
    (new SyncAccountComments($this->ig->id, 25))->handle(app(InboxService::class), app(SocialAccountService::class));
    expect(Cache::lock('sync-account-comments:'.$this->ig->id, 5)->get())->toBeTrue(); // liberado al terminar
});
