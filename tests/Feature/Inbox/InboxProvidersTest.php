<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\InboxComment;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\InboxService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\BlueskyFake;
use Tests\Support\McpClient;
use Tests\Support\ThreadsFake;

uses(McpClient::class);

/**
 * T-20 · Inbox ampliado: contrato por proveedor (Threads, Bluesky, YouTube) con `Http::fake` (nada de red real), cobertura
 * explicita y triaje (cerrar, etiquetas, asignacion, lotes, respuestas guardadas) con su aislamiento.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Http::preventStrayRequests();
});

function t20Target(SocialAccount $account, string $externalId): PostTarget
{
    $post = Post::factory()->create(['brand_id' => $account->brand_id, 'user_id' => $account->user_id, 'text' => 'Mi publicacion']);

    return PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $account->id, 'network' => $account->network, 'status' => PostStatus::Published,
        'external_id' => $externalId, 'external_url' => 'https://example.test/p/x', 'published_at' => now()->subDay(),
    ]);
}

function t20Youtube(array $over = []): SocialAccount
{
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();

    return SocialAccount::factory()->for($user)->create($over + [
        'brand_id' => $brand->id, 'network' => Network::YouTube, 'external_id' => 'UC-canal', 'name' => 'Mi canal', 'access_token' => 'YT-TOKEN',
        'scopes' => ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl'],
    ]);
}

// ---------------------------------------------------------------------------------------------------------- Threads

test('Threads: lee la conversacion con Bearer-free query, marca lo propio y reparenta; responde con contenedor + publicacion', function () {
    $account = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_content_publish', 'threads_read_replies', 'threads_manage_replies']]);
    t20Target($account, '1800');
    Http::fake([
        'graph.threads.com/v1.0/1800/conversation*' => Http::response(['data' => [
            ['id' => 't1', 'text' => 'Genial', 'username' => 'luis', 'timestamp' => '2026-10-31T10:00:00+0000', 'replied_to' => ['id' => '1800'], 'hide_status' => 'NOT_HUSHED'],
            ['id' => 't2', 'text' => 'Gracias!', 'username' => 'ana.threads', 'timestamp' => '2026-10-31T11:00:00+0000', 'replied_to' => ['id' => 't1']],
            ['id' => 't3', 'text' => 'spam', 'username' => 'bot', 'timestamp' => '2026-10-31T12:00:00+0000', 'replied_to' => ['id' => '1800'], 'hide_status' => 'HIDDEN'],
        ]]),
        'graph.threads.com/v1.0/'.ThreadsFake::USER_ID.'/threads' => Http::response(['id' => 'CONT-1']),
        'graph.threads.com/v1.0/'.ThreadsFake::USER_ID.'/threads_publish' => Http::response(['id' => 't9']),
    ]);

    $inbox = app(InboxService::class);
    expect($inbox->sync($account))->toBe(2);
    $rows = InboxComment::withoutUserScope()->orderBy('external_id')->get()->keyBy('external_id');
    expect($rows['t1']->parent_external_id)->toBeNull()->and($rows['t1']->network)->toBe(Network::Threads)
        ->and($rows['t2']->is_own)->toBeTrue()->and($rows['t2']->parent_external_id)->toBe('t1')
        ->and($rows['t3']->hidden)->toBeTrue();

    $reply = $inbox->reply($account->user, $rows['t1'], 'Mil gracias');
    expect($reply->external_id)->toBe('t9')->and($reply->is_own)->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/threads') && ($r['reply_to_id'] ?? null) === 't1' && ($r['text'] ?? null) === 'Mil gracias');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/threads_publish') && ($r['creation_id'] ?? null) === 'CONT-1');
});

test('Threads: sin threads_read_replies la cuenta no se sincroniza ni se llama a la red', function () {
    $account = ThreadsFake::account(); // scopes de publicar
    t20Target($account, '1800');

    expect(app(InboxService::class)->syncable($account))->toBeFalse()->and(app(InboxService::class)->sync($account))->toBe(0);
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------------------------------------- Bluesky

test('Bluesky: lee el hilo del AppView sin sesion y responde con root y parent en el PDS', function () {
    $account = BlueskyFake::account();
    $uri = 'at://'.BlueskyFake::DID.'/app.bsky.feed.post/3kpost';
    $c1 = 'at://did:plc:luis/app.bsky.feed.post/3kc1';
    t20Target($account, $uri);
    Http::fake([
        'public.api.bsky.app/xrpc/app.bsky.feed.getPostThread*' => Http::response(['thread' => ['post' => ['uri' => $uri], 'replies' => [
            ['post' => ['uri' => $c1, 'cid' => 'cid-c1', 'author' => ['did' => 'did:plc:luis', 'handle' => 'luis.test', 'displayName' => 'Luis'], 'record' => ['text' => 'Buen hilo', 'createdAt' => '2026-10-31T10:00:00.000Z']], 'replies' => [
                ['post' => ['uri' => 'at://'.BlueskyFake::DID.'/app.bsky.feed.post/3kown', 'author' => ['did' => BlueskyFake::DID, 'handle' => 'ana.example.com'], 'record' => ['text' => 'Gracias', 'createdAt' => '2026-10-31T11:00:00.000Z']], 'replies' => []],
            ]],
        ]]]),
        'public.api.bsky.app/xrpc/app.bsky.feed.getPosts*' => Http::response(['posts' => [['uri' => $c1, 'cid' => 'cid-c1', 'record' => ['reply' => ['root' => ['uri' => $uri, 'cid' => 'cid-root'], 'parent' => ['uri' => $uri, 'cid' => 'cid-root']]]]]]),
        BlueskyFake::PDS.'/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://'.BlueskyFake::DID.'/app.bsky.feed.post/3knew', 'cid' => 'cid-new']),
    ]);

    $inbox = app(InboxService::class);
    expect($inbox->sync($account))->toBe(1);
    $c = InboxComment::withoutUserScope()->where('external_id', $c1)->first();
    expect($c->author_name)->toBe('Luis (@luis.test)')->and($c->parent_external_id)->toBeNull()
        ->and(InboxComment::withoutUserScope()->where('is_own', true)->value('parent_external_id'))->toBe($c1);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'getPostThread') && ! $r->hasHeader('Authorization'));

    $inbox->reply($account->user, $c, 'Gracias por pasarte');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'createRecord')
        && $r['record']['reply']['root'] === ['uri' => $uri, 'cid' => 'cid-root'] && $r['record']['reply']['parent'] === ['uri' => $c1, 'cid' => 'cid-c1']
        && $r['collection'] === 'app.bsky.feed.post');
});

test('Bluesky: una URI manipulada no sale de la ruta XRPC (se rechaza antes de llamar)', function () {
    $account = BlueskyFake::account();
    $comment = InboxComment::factory()->create(['user_id' => $account->user_id, 'brand_id' => $account->brand_id, 'social_account_id' => $account->id, 'network' => Network::Bluesky, 'external_id' => 'at://x/../../evil']);

    expect(fn () => app(InboxService::class)->reply($account->user, $comment, 'hola'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------------------------------------- YouTube

test('YouTube: lee hilos y respuestas con el cupo, detecta el canal propio y responde con comments.insert', function () {
    $account = t20Youtube();
    t20Target($account, 'VID123');
    Http::fake([
        'www.googleapis.com/youtube/v3/commentThreads*' => Http::response(['items' => [
            ['snippet' => ['topLevelComment' => ['id' => 'yc1', 'snippet' => ['authorDisplayName' => 'Marta', 'textOriginal' => 'Me encanta', 'publishedAt' => '2026-10-31T10:00:00Z', 'authorChannelId' => ['value' => 'UC-otra']]]],
                'replies' => ['comments' => [['id' => 'yc1.r1', 'snippet' => ['authorDisplayName' => 'Mi canal', 'textOriginal' => 'Gracias', 'publishedAt' => '2026-10-31T11:00:00Z', 'authorChannelId' => ['value' => 'UC-canal']]]]]],
        ]]),
        'www.googleapis.com/youtube/v3/comments*' => Http::response(['id' => 'yc1.r2']),
    ]);

    $inbox = app(InboxService::class);
    expect($inbox->sync($account))->toBe(1);
    $top = InboxComment::withoutUserScope()->where('external_id', 'yc1')->first();
    expect($top->network)->toBe(Network::YouTube)->and(InboxComment::withoutUserScope()->where('external_id', 'yc1.r1')->first()->is_own)->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'commentThreads') && str_contains($r->url(), 'videoId=VID123') && $r->hasHeader('Authorization', 'Bearer YT-TOKEN'));

    $reply = $inbox->reply($account->user, $top, 'Gracias Marta');
    expect($reply->external_id)->toBe('yc1.r2');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/comments?part=snippet') && $r['snippet']['parentId'] === 'yc1' && $r['snippet']['textOriginal'] === 'Gracias Marta');
});

test('YouTube: sin youtube.force-ssl no se sincroniza ni se responde y ocultar no existe fuera de Meta', function () {
    $account = t20Youtube(['scopes' => ['https://www.googleapis.com/auth/youtube']]);
    t20Target($account, 'VID123');
    $comment = InboxComment::factory()->create(['user_id' => $account->user_id, 'brand_id' => $account->brand_id, 'social_account_id' => $account->id, 'network' => Network::YouTube, 'external_id' => 'yc9']);
    $inbox = app(InboxService::class);

    expect($inbox->sync($account))->toBe(0);
    expect(fn () => $inbox->reply($account->user, $comment, 'hola'))->toThrow(PostValidationException::class);
    expect(fn () => $inbox->setHidden($account->user, $comment, true))->toThrow(PostValidationException::class, 'Ocultar');
    Http::assertNothingSent();
});

test('Cobertura: TikTok queda fuera con su motivo y el resto declara lectura y respuesta', function () {
    $cov = collect(InboxService::coverage())->keyBy('network');

    expect($cov['tiktok']['read'])->toBeFalse()->and($cov['tiktok']['note'])->toContain('API pública')
        ->and($cov->only(['instagram', 'facebook', 'threads', 'bluesky', 'youtube'])->every(fn ($c) => $c['read'] && $c['reply']))->toBeTrue()
        ->and($cov['threads']['hide'])->toBeFalse()->and($cov['instagram']['note'])->toContain('mensajes directos');
    expect(collect(InboxService::NETWORKS)->map->value->all())->not->toContain('tiktok');
});

// ---------------------------------------------------------------------------------------------------------- triaje

test('Triaje: cerrar/reabrir, etiquetas normalizadas e idempotentes, filtros por estado, etiqueta y asignado', function () {
    $user = User::factory()->create();
    $a = InboxComment::factory()->create(['user_id' => $user->id, 'text' => 'uno']);
    $b = InboxComment::factory()->create(['user_id' => $user->id, 'brand_id' => $a->brand_id, 'social_account_id' => $a->social_account_id, 'text' => 'dos']);
    $inbox = app(InboxService::class);

    $inbox->setClosed($user, $a);
    $inbox->addLabel($user, $b, '  Ventas  Q4 ');
    $inbox->addLabel($user, $b, 'ventas q4');
    expect($b->labels()->pluck('label')->all())->toBe(['ventas q4']);

    expect($inbox->query($user, ['status' => 'open'])->pluck('text')->all())->toBe(['dos'])
        ->and($inbox->query($user, ['status' => 'closed'])->pluck('text')->all())->toBe(['uno'])
        ->and($inbox->query($user, ['label' => 'ventas q4'])->pluck('text')->all())->toBe(['dos']);

    $inbox->assign($user, $a, $user->id);
    expect($inbox->query($user, ['assigned' => $user->id])->pluck('text')->all())->toBe(['uno'])
        ->and($inbox->query($user, ['assigned' => 'none'])->pluck('text')->all())->toBe(['dos']);

    $inbox->setClosed($user, $a, false);
    $inbox->removeLabel($user, $b, 'Ventas Q4');
    expect($a->fresh()->closed_at)->toBeNull()->and($b->labels()->count())->toBe(0);
    expect(fn () => $inbox->addLabel($user, $b, str_repeat('x', 41)))->toThrow(PostValidationException::class);
    expect(fn () => $inbox->addLabel($user, $b, '<b>x</b>'))->toThrow(PostValidationException::class);
});

test('Triaje: asignar solo a uno mismo o a un colaborador de la marca; un extraño o ajeno se rechaza', function () {
    $user = User::factory()->create();
    $guest = User::factory()->create();
    $stranger = User::factory()->create();
    $c = InboxComment::factory()->create(['user_id' => $user->id]);
    BrandCollaborator::query()->create(['brand_id' => $c->brand_id, 'owner_id' => $user->id, 'user_id' => $guest->id, 'role' => 'aprobador']);
    $inbox = app(InboxService::class);

    $inbox->assign($user, $c, $guest->id);
    expect($c->fresh()->assigned_user_id)->toBe($guest->id);
    expect(fn () => $inbox->assign($user, $c, $stranger->id))->toThrow(PostValidationException::class);
    expect(fn () => $inbox->assign($stranger, $c, $stranger->id))->toThrow(PostValidationException::class);
});

test('Lote: leer y cerrar varios, ids ajenos o inexistentes cuentan como omitidos sin revelar nada y hay tope', function () {
    $user = User::factory()->create();
    $mine = InboxComment::factory()->count(3)->create(['user_id' => $user->id]);
    $theirs = InboxComment::factory()->create();
    $inbox = app(InboxService::class);
    $ids = [...$mine->pluck('id')->all(), $theirs->id, 999999];

    expect($inbox->batch($user, 'read', $ids))->toBe(['done' => 3, 'skipped' => 2])
        ->and(InboxComment::withoutUserScope()->whereIn('id', $mine->pluck('id'))->whereNull('read_at')->count())->toBe(0)
        ->and($theirs->fresh()->read_at)->toBeNull();

    expect($inbox->batch($user, 'close', $ids)['done'])->toBe(3)
        ->and($inbox->query($user, ['status' => 'closed'])->count())->toBe(3)
        ->and($theirs->fresh()->closed_at)->toBeNull();
    $inbox->batch($user, 'reopen', $mine->pluck('id')->all());
    expect($inbox->query($user, ['status' => 'open'])->count())->toBe(3);

    expect(fn () => $inbox->batch($user, 'delete', [1]))->toThrow(PostValidationException::class)
        ->and(fn () => $inbox->batch($user, 'read', []))->toThrow(PostValidationException::class)
        ->and(fn () => $inbox->batch($user, 'read', range(1, 101)))->toThrow(PostValidationException::class);
});

test('Respuestas guardadas: alta, listado propio, tope y borrado solo de las propias', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $inbox = app(InboxService::class);

    $r = $inbox->saveReply($user, 'Gracias', 'Gracias por escribirnos');
    $inbox->saveReply($other, 'Ajena', 'x');
    expect($inbox->savedReplies($user)->pluck('title')->all())->toBe(['Gracias']);

    $inbox->deleteReply($other, $r->id);
    expect($inbox->savedReplies($user))->toHaveCount(1);
    $inbox->deleteReply($user, $r->id);
    expect($inbox->savedReplies($user))->toHaveCount(0);

    expect(fn () => $inbox->saveReply($user, '', 'x'))->toThrow(PostValidationException::class);
    config(['social.inbox.saved_replies_max' => 1]);
    $inbox->saveReply($user, 'a', 'b');
    expect(fn () => $inbox->saveReply($user, 'c', 'd'))->toThrow(PostValidationException::class);
});

test('Web: filtros de red/estado/etiqueta/asignado, lote, cierre, etiqueta y guardadas; lo ajeno es 404', function () {
    $user = User::factory()->create();
    $c = InboxComment::factory()->create(['user_id' => $user->id, 'network' => Network::Threads, 'text' => 'hilo']);
    $x = InboxComment::factory()->create(['user_id' => $user->id, 'brand_id' => $c->brand_id, 'social_account_id' => $c->social_account_id, 'text' => 'otro']);
    $alien = InboxComment::factory()->create();
    $this->actingAs($user);

    $this->post(route('inbox.label', $c->id), ['label' => 'Urgente'])->assertRedirect();
    $this->post(route('inbox.close', $x->id))->assertRedirect();
    $this->post(route('inbox.saved-replies.store'), ['title' => 'Hola', 'body' => 'Hola, gracias'])->assertRedirect();

    $this->get(route('inbox.index', ['network' => 'threads', 'status' => 'open', 'label' => 'urgente', 'assigned' => 'none']))
        ->assertInertia(fn ($p) => $p->component('Inbox/Index')->has('comments.data', 1)->where('comments.data.0.labels', ['urgente'])
            ->where('saved_replies.0.title', 'Hola')->has('coverage', 6));
    $this->get(route('inbox.index', ['status' => 'closed']))->assertInertia(fn ($p) => $p->has('comments.data', 1)->where('comments.data.0.closed', true));
    $this->get(route('inbox.index', ['network' => 'tiktok']))->assertSessionHasErrors('network');

    $this->post(route('inbox.batch'), ['action' => 'read', 'ids' => [$c->id, $x->id, $alien->id]])->assertSessionHas('success', '2 comentarios actualizados, 1 omitidos.');
    $this->post(route('inbox.batch'), ['action' => 'nuke', 'ids' => [1]])->assertSessionHasErrors('action');
    $this->post(route('inbox.close', $alien->id))->assertNotFound();
    $this->post(route('inbox.label', $alien->id), ['label' => 'x'])->assertNotFound();
    $this->post(route('inbox.assign', $alien->id), ['assigned_user_id' => $user->id])->assertNotFound();
    expect($alien->fresh()->closed_at)->toBeNull();
});

test('MCP: list_comments filtra por red, estado, etiqueta y asignado; triage_comments exige inbox:write y respeta el aislamiento', function () {
    $user = User::factory()->create();
    $a = InboxComment::factory()->create(['user_id' => $user->id, 'network' => Network::Bluesky, 'text' => 'a']);
    $b = InboxComment::factory()->create(['user_id' => $user->id, 'brand_id' => $a->brand_id, 'social_account_id' => $a->social_account_id, 'text' => 'b']);
    $alien = InboxComment::factory()->create();
    $reader = $this->mcpKey($user, ['inbox:read']);
    $writer = $this->mcpKey($user, ['inbox:write']);

    [$err] = $this->callTool($reader, 'triage_comments', ['action' => 'close', 'comment_ids' => [$a->id]]);
    expect($err)->toBeTrue()->and($a->fresh()->closed_at)->toBeNull();

    [$err, $res] = $this->callTool($writer, 'triage_comments', ['action' => 'close', 'comment_ids' => [$a->id, $alien->id]]);
    expect($err)->toBeFalse()->and($res['done'])->toBe(1)->and($res['skipped'])->toBe(1)->and($alien->fresh()->closed_at)->toBeNull();
    [, $res] = $this->callTool($writer, 'triage_comments', ['action' => 'label', 'comment_id' => $b->id, 'label' => 'Ventas']);
    expect($res['labels'])->toBe(['ventas']);
    [, $res] = $this->callTool($writer, 'triage_comments', ['action' => 'assign', 'comment_id' => $b->id, 'assigned_user_id' => $user->id]);
    expect($res['assigned_user_id'])->toBe($user->id);
    [$err] = $this->callTool($writer, 'triage_comments', ['action' => 'label', 'comment_id' => $alien->id, 'label' => 'x']);
    expect($err)->toBeTrue();
    [$err, $msg] = $this->callTool($writer, 'triage_comments', ['action' => 'assign', 'comment_id' => $b->id, 'assigned_user_id' => User::factory()->create()->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('colaborador');

    $texts = fn (array $args) => collect($this->callTool($reader, 'list_comments', $args)[1]['comments'])->pluck('text')->all();
    expect($texts(['network' => 'bluesky']))->toBe(['a'])->and($texts(['status' => 'open']))->toBe(['b'])->and($texts(['status' => 'closed']))->toBe(['a'])
        ->and($texts(['label' => 'ventas']))->toBe(['b'])->and($texts(['assigned' => 'me']))->toBe(['b'])->and($texts(['assigned' => 'none']))->toBe(['a']);
    [, $res] = $this->callTool($reader, 'list_comments', ['label' => 'ventas']);
    expect($res['comments'][0]['labels'])->toBe(['ventas']);
});
