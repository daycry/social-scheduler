<?php

use App\Connectors\Bluesky\BlueskyPublisher;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\FirstCommenter;
use App\Connectors\Exceptions\CommentSearchInconclusiveException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Connectors\Threads\ThreadsClient;
use App\Connectors\Threads\ThreadsComments;
use App\Connectors\Threads\ThreadsPublisher;
use App\Enums\ContentType;
use App\Enums\FirstCommentStatus;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Jobs\PostFirstComment;
use App\Models\InboxComment;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\FirstCommentService;
use App\Services\InboxService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\BlueskyFake;
use Tests\Support\ThreadsFake;

/**
 * T-24 · primer comentario en Threads (`reply_to_id` + `threads_publish`) y Bluesky (`app.bsky.feed.post` con `reply{root,parent}`),
 * reutilizando `ThreadsComments`/`BlueskyComments`. Contratos verificados en la documentacion oficial (ver tasks.md). Todo con Http::fake.
 */
const FC_BS_POST = 'at://did:plc:abcdefghijklmnopqrstuvwx/app.bsky.feed.post/3kpost000001';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Http::preventStrayRequests();
    config(['publishing.comment_max_attempts' => 3, 'social.bluesky.default_langs' => ['es']]);
});

test('Threads y Bluesky se resuelven como FirstCommenter y sus tipos admiten primer comentario', function () {
    ThreadsFake::configure();
    $registry = app(ConnectorRegistry::class);

    expect($registry->commenterFor(Network::Bluesky))->toBeInstanceOf(FirstCommenter::class)
        ->and($registry->commenterFor(Network::Threads))->toBeInstanceOf(FirstCommenter::class);

    foreach ([ContentType::BlueskyPost, ContentType::BlueskyVideo] as $type) {
        expect(config("media_specs.types.{$type->value}.first_comment.max"))->toBe(300);
    }
    foreach (ContentType::forNetwork(Network::Threads) as $type) {
        expect(config("media_specs.types.{$type->value}.first_comment.max"))->toBe(500);
    }
});

// ------------------------------------------------------------------ Threads

test('Threads comenta con un contenedor reply_to_id y lo publica', function () {
    $account = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_content_publish', 'threads_read_replies', 'threads_manage_replies']]);
    Http::fake([
        'graph.threads.com/*/'.ThreadsFake::USER_ID.'/threads' => Http::response(['id' => 'CONT1']),
        'graph.threads.com/*/'.ThreadsFake::USER_ID.'/threads_publish' => Http::response(['id' => 'REPLY1']),
    ]);

    $id = app(ThreadsPublisher::class)->comment($account, 'POST1', 'Mas info en el enlace');

    expect($id)->toBe('REPLY1');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/'.ThreadsFake::USER_ID.'/threads')
        && ($r['reply_to_id'] ?? null) === 'POST1' && ($r['text'] ?? null) === 'Mas info en el enlace' && ($r['media_type'] ?? null) === 'TEXT');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/threads_publish') && ($r['creation_id'] ?? null) === 'CONT1');
});

test('Threads sin el permiso de respuestas pide reconectar sin llamar a la red', function () {
    $account = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_content_publish']]);

    expect(fn () => app(ThreadsPublisher::class)->comment($account, 'POST1', 'Hola'))->toThrow(ReconnectRequiredException::class);
    Http::assertNothingSent();
});

test('Threads findOwnComment encuentra la respuesta propia por texto normalizado y no la ajena', function () {
    $account = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_read_replies', 'threads_manage_replies']]);
    Http::fake(['graph.threads.com/*/POST1/replies*' => Http::response(['data' => [
        ['id' => 'A', 'text' => 'Mas info en el enlace', 'username' => 'otra.persona', 'timestamp' => '2026-11-01T10:00:00+0000'],
        ['id' => 'B', 'text' => "Mas  info\nen el enlace ", 'username' => 'Ana.Threads', 'timestamp' => '2026-11-01T10:01:00+0000'],
    ]])]);

    expect(app(ThreadsPublisher::class)->findOwnComment($account, 'POST1', 'Mas info en el enlace'))->toBe('B')
        ->and(app(ThreadsPublisher::class)->findOwnComment($account, 'POST1', 'Otro texto'))->toBeNull();
});

test('Threads findOwnComment sin permiso de lectura es inconclusa (no se crea a ciegas) y con mas paginas que el tope tambien', function () {
    $blind = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_manage_replies']]);
    expect(fn () => app(ThreadsPublisher::class)->findOwnComment($blind, 'POST1', 'Hola'))->toThrow(CommentSearchInconclusiveException::class);
    Http::assertNothingSent();

    config(['social.first_comment.find_pages' => 2]);
    $account = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_read_replies', 'threads_manage_replies']]);
    Http::fake(['graph.threads.com/*/POST1/replies*' => Http::response(['data' => [['id' => 'X', 'text' => 'otro', 'username' => 'otra']],
        'paging' => ['cursors' => ['after' => 'CUR'], 'next' => 'https://graph.threads.com/next']])]);

    expect(fn () => app(ThreadsPublisher::class)->findOwnComment($account, 'POST1', 'Hola'))->toThrow(CommentSearchInconclusiveException::class);
    Http::assertSentCount(2);
});

// ------------------------------------------------------------------ Bluesky

test('Bluesky comenta con reply root y parent = la publicacion, con el cid leido del PDS y facets', function () {
    $account = BlueskyFake::account();
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.getRecord*' => Http::response(['uri' => FC_BS_POST, 'cid' => 'bafyPOST', 'value' => []]),
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:abcdefghijklmnopqrstuvwx/app.bsky.feed.post/3kcomment0001', 'cid' => 'bafyCOMMENT']),
    ]);

    $id = app(BlueskyPublisher::class)->comment($account, FC_BS_POST, 'Mas en https://example.com/x');

    expect($id)->toEndWith('/3kcomment0001');
    Http::assertSent(function ($r) {
        if (! str_ends_with($r->url(), 'com.atproto.repo.createRecord')) {
            return false;
        }
        $rec = $r['record'];

        return $r['collection'] === 'app.bsky.feed.post' && $r['repo'] === BlueskyFake::DID
            && $rec['reply'] === ['root' => ['uri' => FC_BS_POST, 'cid' => 'bafyPOST'], 'parent' => ['uri' => FC_BS_POST, 'cid' => 'bafyPOST']]
            && $rec['text'] === 'Mas en https://example.com/x' && ($rec['facets'][0]['features'][0]['uri'] ?? null) === 'https://example.com/x';
    });
});

test('Bluesky sin la publicacion en el PDS falla de forma permanente y no crea nada', function () {
    $account = BlueskyFake::account();
    Http::fake(['pds.example.com/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'RecordNotFound', 'message' => 'Could not locate record'], 400)]);

    expect(fn () => app(BlueskyPublisher::class)->comment($account, FC_BS_POST, 'Hola'))->toThrow(PermanentPublishException::class);
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), 'createRecord'));
});

test('Bluesky findOwnComment localiza la respuesta propia a ese post y descarta otros padres y textos', function () {
    $account = BlueskyFake::account();
    $mine = fn (string $parent, string $text, string $rkey) => ['uri' => 'at://'.BlueskyFake::DID.'/app.bsky.feed.post/'.$rkey, 'cid' => 'c'.$rkey, 'value' => ['text' => $text, 'reply' => ['parent' => ['uri' => $parent, 'cid' => 'x'], 'root' => ['uri' => $parent, 'cid' => 'x']]]];
    Http::fake(['pds.example.com/xrpc/com.atproto.repo.listRecords*' => Http::response(['records' => [
        $mine('at://did:plc:otro/app.bsky.feed.post/zzz', 'Mas info', '3k1'),
        $mine(FC_BS_POST, 'Otro texto', '3k2'),
        $mine(FC_BS_POST, "Mas  info\n", '3k3'),
    ]])]);

    expect(app(BlueskyPublisher::class)->findOwnComment($account, FC_BS_POST, 'Mas info'))->toEndWith('/3k3')
        ->and(app(BlueskyPublisher::class)->findOwnComment($account, FC_BS_POST, 'No existe'))->toBeNull();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'listRecords') && str_contains($r->url(), 'repo='.rawurlencode(BlueskyFake::DID)));
});

test('Bluesky findOwnComment es inconclusa si quedan paginas tras el tope', function () {
    config(['social.first_comment.find_pages' => 2]);
    $account = BlueskyFake::account();
    Http::fake(['pds.example.com/xrpc/com.atproto.repo.listRecords*' => Http::response(['records' => [['uri' => 'at://x/app.bsky.feed.post/1', 'value' => ['text' => 'otro']]], 'cursor' => 'NEXT'])]);

    expect(fn () => app(BlueskyPublisher::class)->findOwnComment($account, FC_BS_POST, 'Hola'))->toThrow(CommentSearchInconclusiveException::class);
    Http::assertSentCount(2);
});

// ------------------------------------------------------------------ job (idempotencia y aislamiento del fallo)

test('el job comenta en Threads y Bluesky, no duplica al repetirse y un fallo no degrada el post principal', function () {
    $threads = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_content_publish', 'threads_read_replies', 'threads_manage_replies']]);
    $bsky = BlueskyFake::account(user: $threads->user);
    $post = Post::factory()->create(['brand_id' => $threads->brand_id, 'user_id' => $threads->user_id, 'status' => PostStatus::Published]);
    $mk = fn ($account, string $ext, string $type) => PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $threads->user_id, 'social_account_id' => $account->id, 'status' => PostStatus::Published,
        'content_type' => $type, 'network' => $account->network, 'external_id' => $ext, 'first_comment' => 'Mas info',
        'first_comment_status' => FirstCommentStatus::Pending, 'first_comment_attempts' => 0,
    ]);
    $tt = $mk($threads, 'POST1', ContentType::ThreadsText->value);
    $bt = $mk($bsky, FC_BS_POST, ContentType::BlueskyPost->value);

    Http::fake([
        'graph.threads.com/*/POST1/replies*' => Http::response(['data' => []]),
        'graph.threads.com/*/threads' => Http::response(['id' => 'CONT1']),
        'graph.threads.com/*/threads_publish' => Http::response(['id' => 'REPLY1']),
        'pds.example.com/xrpc/com.atproto.repo.listRecords*' => Http::response(['records' => []]),
        'pds.example.com/xrpc/com.atproto.repo.getRecord*' => Http::response(['cid' => 'bafyPOST']),
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://'.BlueskyFake::DID.'/app.bsky.feed.post/3kc1', 'cid' => 'c']),
    ]);

    foreach ([$tt, $bt, $tt, $bt] as $t) {
        app()->call([new PostFirstComment($t->id), 'handle']);
    }

    expect($tt->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published)->and($tt->fresh()->first_comment_external_id)->toBe('REPLY1')
        ->and($bt->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published)->and($bt->fresh()->first_comment_external_id)->toEndWith('/3kc1');
    // Idempotencia: el segundo pase adopta el id guardado y no vuelve a publicar nada en ninguna red.
    expect(Http::recorded(fn ($r) => str_ends_with($r->url(), '/threads_publish')))->toHaveCount(1)
        ->and(Http::recorded(fn ($r) => str_ends_with($r->url(), 'createRecord')))->toHaveCount(1);
});

test('un fallo del comentario deja el post publicado y registra el motivo', function () {
    $account = BlueskyFake::account();
    $post = Post::factory()->create(['brand_id' => $account->brand_id, 'user_id' => $account->user_id, 'status' => PostStatus::Published]);
    $t = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $account->user_id, 'social_account_id' => $account->id, 'status' => PostStatus::Published,
        'content_type' => ContentType::BlueskyPost->value, 'network' => Network::Bluesky, 'external_id' => FC_BS_POST, 'first_comment' => 'Hola',
        'first_comment_status' => FirstCommentStatus::Pending, 'first_comment_attempts' => 0,
    ]);
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.listRecords*' => Http::response(['records' => []]),
        'pds.example.com/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'RecordNotFound'], 400),
    ]);

    app()->call([new PostFirstComment($t->id), 'handle']);

    $t = $t->fresh();
    expect($t->status)->toBe(PostStatus::Published)->and($t->first_comment_status)->toBe(FirstCommentStatus::Failed)
        ->and($t->first_comment_error)->not->toBeEmpty();
});

// ------------------------------------------------------------------ gaps de la revision de T-22 (mismo fichero)

test('un rechazo de permisos sobre UNA publicacion (codigo 200 con el objeto como causa) se salta sin retirar el scope ni pedir reconectar', function () {
    $account = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_read_replies', 'threads_manage_replies']]);
    Http::fake(['graph.threads.com/*' => Http::response(['error' => ['message' => '(#200) The post cannot be accessed: it was deleted or belongs to another user', 'code' => 200]], 403)]);

    expect(fn () => app(ThreadsComments::class)->fetch($account, '1800'))->toThrow(PermanentPublishException::class)
        ->and($account->fresh()->scopes)->toContain('threads_read_replies')->and($account->fresh()->status)->toBe('activa');
});

test('el inbox salta la publicacion sin permiso y sigue leyendo las demas', function () {
    $account = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_read_replies', 'threads_manage_replies']]);
    $post = Post::factory()->create(['brand_id' => $account->brand_id, 'user_id' => $account->user_id, 'status' => PostStatus::Published]);
    foreach (['BAD1' => ContentType::ThreadsText, 'OK1' => ContentType::ThreadsImage] as $ext => $ct) {
        PostTarget::factory()->create(['post_id' => $post->id, 'user_id' => $account->user_id, 'social_account_id' => $account->id, 'status' => PostStatus::Published, 'network' => Network::Threads, 'content_type' => $ct->value, 'external_id' => $ext, 'published_at' => now()]);
    }
    Http::fake([
        'graph.threads.com/*/BAD1/conversation*' => Http::response(['error' => ['message' => '(#200) The post cannot be accessed: it was deleted or belongs to another user', 'code' => 200]], 403),
        'graph.threads.com/*/OK1/conversation*' => Http::response(['data' => [['id' => 'R1', 'text' => 'hola', 'username' => 'otra', 'timestamp' => '2026-11-01T10:00:00+0000']]]),
    ]);

    app(InboxService::class)->sync($account);

    expect(InboxComment::withoutUserScope()->where('external_id', 'R1')->exists())->toBeTrue()
        ->and($account->fresh()->status)->toBe('activa');
});

test('el rechazo de permisos de la cuenta (codigo 10) sigue retirando el scope y pidiendo reconectar', function () {
    $account = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_read_replies', 'threads_manage_replies']]);
    Http::fake(['graph.threads.com/*' => Http::response(['error' => ['message' => '(#10) Application does not have permission for this action', 'code' => 10]], 403)]);

    expect(fn () => app(ThreadsComments::class)->fetch($account, '1800'))->toThrow(ReconnectRequiredException::class)
        ->and($account->fresh()->scopes)->not->toContain('threads_read_replies');
});

test('el job de primer comentario marca la cuenta para reconectar cuando Threads rechaza el TOKEN', function () {
    $account = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_read_replies', 'threads_manage_replies']]);
    $post = Post::factory()->create(['brand_id' => $account->brand_id, 'user_id' => $account->user_id, 'status' => PostStatus::Published]);
    $t = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $account->user_id, 'social_account_id' => $account->id, 'status' => PostStatus::Published,
        'content_type' => ContentType::ThreadsText->value, 'network' => Network::Threads, 'external_id' => 'POST1', 'first_comment' => 'Hola',
        'first_comment_status' => FirstCommentStatus::Pending, 'first_comment_attempts' => 0,
    ]);
    Http::fake(['graph.threads.com/*' => Http::response(['error' => ['message' => 'Error validating access token: Session has expired', 'code' => 190]], 401)]);

    app()->call([new PostFirstComment($t->id), 'handle']);

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Failed)
        ->and($account->fresh()->status)->not->toBe('activa');
});

// ------------------------------------------------------------------ revision intento 1 (T-24)

test('un permiso opcional rechazado por Threads falla solo el comentario: retira el scope y NO marca la cuenta', function () {
    $account = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_content_publish', 'threads_read_replies', 'threads_manage_replies']]);
    $post = Post::factory()->create(['brand_id' => $account->brand_id, 'user_id' => $account->user_id, 'status' => PostStatus::Published]);
    $t = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $account->user_id, 'social_account_id' => $account->id, 'status' => PostStatus::Published,
        'content_type' => ContentType::ThreadsText->value, 'network' => Network::Threads, 'external_id' => 'POST1', 'first_comment' => 'Hola',
        'first_comment_status' => FirstCommentStatus::Pending, 'first_comment_attempts' => 0,
    ]);
    Http::fake(['graph.threads.com/*' => Http::response(['error' => ['message' => '(#10) Application does not have permission for this action', 'code' => 10]], 403)]);

    app()->call([new PostFirstComment($t->id), 'handle']);

    $fresh = $account->fresh();
    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Failed)->and($fresh->status)->toBe('activa')
        ->and($fresh->scopes)->not->toContain('threads_read_replies')->toContain('threads_content_publish');
});

test('en el inbox un permiso opcional rechazado no deja la cuenta en reconexion', function () {
    $account = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_content_publish', 'threads_read_replies', 'threads_manage_replies']]);
    $c = InboxComment::factory()->create(['user_id' => $account->user_id, 'social_account_id' => $account->id, 'network' => $account->network]);
    Http::fake(['graph.threads.com/*' => Http::response(['error' => ['message' => '(#10) Application does not have permission for this action', 'code' => 10]], 403)]);

    expect(fn () => app(InboxService::class)->reply($account->user, $c, 'hola'))->toThrow(PostValidationException::class);

    expect($account->fresh()->status)->toBe('activa')->and($account->fresh()->scopes)->not->toContain('threads_manage_replies');
});

/** Mensajes reales de Meta (Graph / Threads) -> ¿del objeto (publicacion borrada o ajena) o de la cuenta? */
dataset('threads_permission_messages', [
    'cuenta: #10 permiso denegado' => [10, '(#10) Application does not have permission for this action', false],
    'cuenta: #200 sin autorizar la app' => [200, "(#200) The user hasn't authorized the application to perform this action", false],
    'cuenta: #200 permisos de administrador' => [200, 'User does not have sufficient administrative permission for this action on this page', false],
    'cuenta: #200 requiere permiso' => [200, '(#200) Requires threads_content_publish permission to manage the object', false],
    'cuenta: #299 permiso especifico' => [299, '(#299) Permissions error: the user must grant threads_manage_replies', false],
    'cuenta: #3 metodo de API' => [3, '(#3) Application does not have the capability to make this API call', false],
    'cuenta: #200 contenido sin mas' => [200, '(#200) This content is not available for this user', false],
    'cuenta: #200 post sin mas' => [200, '(#200) You do not have access to the post', false],
    'objeto: no existe' => [200, '(#200) The object does not exist', true],
    'objeto: borrado' => [200, 'The post was deleted and can no longer be accessed', true],
    'objeto: de otra persona' => [200, '(#200) The media belongs to another user and cannot be accessed', true],
    'objeto: no existe (100)' => [200, "Unsupported get request. Object with ID '123' does not exist, cannot be loaded due to missing permissions, or does not support this operation", true],
]);

test('la clasificacion de permisos de Threads solo da por «de objeto» las senales inequivocas', function (int $code, string $message, bool $object) {
    Http::fake(['graph.threads.com/*' => Http::response(['error' => ['message' => $message, 'code' => $code]], 403)]);

    try {
        app(ThreadsClient::class)->get('POST1/replies', [], 'tok', true);
        $thrown = null;
    } catch (PermanentPublishException $e) {
        $thrown = $e->errorCode;
    }

    expect($thrown)->toBe($object ? 'permisos_objeto' : 'permisos');
})->with('threads_permission_messages');

// ------------------------------------------------------------------ limites con el modo de conteo de cada red (revision, Minor)

test('el limite del primer comentario cuenta grafemas en Bluesky y la regla de Threads (emojis por bytes)', function () {
    // 150 emojis compuestos de familia = 150 grafemas (caben en 300) pero muchos mas caracteres y bytes.
    $family = '👨‍👩‍👧‍👦';
    expect(config('media_specs.types.bluesky_post.first_comment.count'))->toBe('graphemes')
        ->and(config('media_specs.types.threads_text.first_comment.count'))->toBe('threads');

    $service = app(FirstCommentService::class);
    $account = BlueskyFake::account();
    $post = Post::factory()->create(['brand_id' => $account->brand_id, 'user_id' => $account->user_id, 'status' => PostStatus::Published]);
    $mk = fn (string $text, ContentType $type, Network $net) => PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $account->user_id, 'social_account_id' => $account->id, 'status' => PostStatus::Published,
        'content_type' => $type->value, 'network' => $net, 'external_id' => 'x', 'first_comment' => 'a',
        'first_comment_status' => FirstCommentStatus::Failed, 'first_comment_attempts' => 3,
    ]);

    // Bluesky: 250 emojis simples = 250 grafemas (<= 300) aunque mb_strlen de familias seria muy superior.
    $ok = $mk('a', ContentType::BlueskyPost, Network::Bluesky);
    expect($service->update($ok, str_repeat($family, 100))->first_comment)->toBe(str_repeat($family, 100));
    expect(fn () => $service->update($ok, str_repeat('a', 301)))->toThrow(PostValidationException::class);

    // Threads: cada emoji cuenta sus bytes UTF-8; 100 emojis (4 bytes) = 400 <= 500 pero 130 = 520 > 500.
    $th = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $account->user_id, 'social_account_id' => $account->id, 'status' => PostStatus::Published,
        'content_type' => ContentType::ThreadsText->value, 'network' => Network::Threads, 'external_id' => 'y', 'first_comment' => 'a',
        'first_comment_status' => FirstCommentStatus::Failed, 'first_comment_attempts' => 3,
    ]);
    expect($service->update($th, str_repeat('😀', 100))->first_comment)->toBe(str_repeat('😀', 100));
    expect(fn () => $service->update($th, str_repeat('😀', 130)))->toThrow(PostValidationException::class);
});
