<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\PublishResult;
use App\Connectors\Google\YouTubePublisher;
use App\Connectors\Google\YouTubeQuota;
use App\Connectors\Meta\Facebook\FacebookPublisher;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Connectors\Network;
use App\Enums\FirstCommentStatus;
use App\Enums\PostStatus;
use App\Jobs\PostFirstComment;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\FirstCommentFailed;
use App\Services\PublishOutcomes;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeGraph;
use Tests\Support\FakeHttp;

// C-01b (CA-04..CA-07): primer comentario de extremo a extremo con los conectores REALES de Instagram, Facebook y YouTube y la red
// simulada (FakeGraph / FakeHttp). Nunca sale a internet.

const FC_YT_THREADS = '#googleapis\.com/youtube/v3/commentThreads#';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Cache::flush();
    Notification::fake();
    config(['social.meta.graph_version' => 'v23.0', 'publishing.comment_max_attempts' => 3, 'social.youtube.daily_quota' => 10000]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->ig = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => 'IGU1', 'username' => 'mimarca',
        'access_token' => 'tok-ig', 'scopes' => ['instagram_manage_comments'],
    ]);
    $this->fb = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Facebook, 'external_id' => 'PAGE1',
        'access_token' => 'tok-fb', 'scopes' => ['pages_manage_engagement', 'pages_read_user_content'],
    ]);
    $this->yt = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::YouTube, 'external_id' => 'UCmio', 'access_token' => 'tok-yt',
        'scopes' => ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl'],
    ]);
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)
        ->registerPublisher(Network::Instagram, new InstagramPublisher)
        ->registerPublisher(Network::Facebook, new FacebookPublisher)
        ->registerPublisher(Network::YouTube, new YouTubePublisher));
});

/** Destino en `procesando`/`programada` listo para que `published()` lo cierre, con su primer comentario. */
function flowTarget(SocialAccount $account, string $type, string $comment = 'Mas info en el enlace'): PostTarget
{
    $post = Post::factory()->create(['brand_id' => test()->brand->id, 'user_id' => test()->user->id, 'status' => PostStatus::Publishing]);

    return PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => test()->user->id, 'social_account_id' => $account->id, 'network' => $account->network,
        'content_type' => $type, 'status' => PostStatus::Publishing, 'attempts' => 1, 'started_at' => now(), 'first_comment' => $comment,
    ]);
}

function flowPublish(PostTarget $t, string $externalId): PostTarget
{
    return app(PublishOutcomes::class)->published($t, PublishResult::published($externalId, 'https://x.test/p'));
}

function flowRun(PostTarget $t): void
{
    app()->call([new PostFirstComment($t->id), 'handle']);
}

test('Instagram: al publicar comenta una vez y publicar otra vez el job no duplica aunque la lista de Meta aun no lo muestre', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^MEDIA1/comments$#', ['data' => []]) // «creado pero no listado»: la lista nunca lo enseña
        ->on('POST', '#^MEDIA1/comments$#', ['id' => 'C1']);
    $t = flowTarget($this->ig, 'instagram_image');

    flowPublish($t, 'MEDIA1'); // published() encola y, con la cola sincrona, comenta
    $t = $t->fresh();
    expect($t->status)->toBe(PostStatus::Published)->and($t->first_comment_status)->toBe(FirstCommentStatus::Published)
        ->and($t->first_comment_external_id)->toBe('C1');

    flowRun($t);
    flowRun($t);

    expect($graph->callsTo('POST', '#/comments$#'))->toHaveCount(1);
});

test('Facebook: el comentario propio ya existente en la red se adopta sin crear otro', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^PAGE1_9/comments$#', ['data' => [['id' => 'PAGE1_9_5', 'message' => 'Mas info en el enlace', 'from' => ['id' => 'PAGE1', 'name' => 'Mi pagina'], 'created_time' => '2026-11-01T11:59:00+0000']]])
        ->on('POST', '#/comments$#', ['id' => 'NO']);
    $t = flowTarget($this->fb, 'facebook_photo');

    flowPublish($t, 'PAGE1_9');

    expect($t->fresh()->first_comment_external_id)->toBe('PAGE1_9_5')->and($graph->callsTo('POST', '#/comments$#'))->toBe([]);
});

test('Facebook: un comentario ajeno con el mismo texto no cuenta como propio', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^PAGE1_9/comments$#', ['data' => [['id' => 'X1', 'message' => 'Mas info en el enlace', 'from' => ['id' => 'OTRO', 'name' => 'Otro'], 'created_time' => '2026-11-01T11:59:00+0000']]])
        ->on('POST', '#^PAGE1_9/comments$#', ['id' => 'PAGE1_9_6']);
    $t = flowTarget($this->fb, 'facebook_photo');

    flowPublish($t, 'PAGE1_9');

    expect($t->fresh()->first_comment_external_id)->toBe('PAGE1_9_6')->and($graph->callsTo('POST', '#/comments$#'))->toHaveCount(1);
});

test('YouTube: comenta tras publicar, una sola vez, y gasta 50 + 1 unidades de cuota', function () {
    $http = (new FakeHttp)
        ->on('GET', FC_YT_THREADS, ['status' => 200, 'json' => ['items' => []]])
        ->on('POST', FC_YT_THREADS, ['status' => 200, 'json' => ['id' => 'Ugx1', 'snippet' => ['topLevelComment' => ['id' => 'Ugx1']]]]);
    $t = flowTarget($this->yt, 'youtube_video');

    flowPublish($t, 'VID1');
    flowRun($t->fresh());

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Published)->and($t->first_comment_external_id)->toBe('Ugx1')
        ->and($http->callsTo('POST', FC_YT_THREADS))->toHaveCount(1)
        ->and((new YouTubeQuota)->used())->toBe(51);
});

test('error de red: vuelve a pendiente, el destino sigue publicado y el siguiente intento comenta', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^MEDIA1/comments$#', ['data' => []])
        ->on('POST', '#^MEDIA1/comments$#', FakeGraph::seq(new ConnectionException('cURL error 28: timeout'), ['id' => 'C9']));
    $t = flowTarget($this->ig, 'instagram_image');

    flowPublish($t, 'MEDIA1');
    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Pending)->and($t->status)->toBe(PostStatus::Published)->and($t->first_comment_attempts)->toBe(1);

    flowRun($t);

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published)->and($t->fresh()->first_comment_external_id)->toBe('C9')
        ->and($graph->callsTo('POST', '#/comments$#'))->toHaveCount(2);
});

test('error de red repetido: agota los intentos, queda fallido con aviso y el destino sigue publicado', function () {
    (new FakeGraph)
        ->on('GET', '#^MEDIA1/comments$#', ['data' => []])
        ->on('POST', '#^MEDIA1/comments$#', new ConnectionException('cURL error 28: timeout'));
    $t = flowTarget($this->ig, 'instagram_image');

    flowPublish($t, 'MEDIA1');
    flowRun($t->fresh());
    flowRun($t->fresh());

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Failed)->and($t->first_comment_attempts)->toBe(3)
        ->and($t->first_comment_error)->toContain('3 intentos')->and($t->status)->toBe(PostStatus::Published);
    Notification::assertSentTo($this->user, FirstCommentFailed::class);
});

test('reconexion de YouTube: sin el permiso queda fallido sin llamar a la API y, reconectado, Reintentar comenta', function () {
    $http = (new FakeHttp)
        ->on('GET', FC_YT_THREADS, ['status' => 200, 'json' => ['items' => []]])
        ->on('POST', FC_YT_THREADS, ['status' => 200, 'json' => ['id' => 'Ugx2']]);
    $this->yt->forceFill(['scopes' => ['https://www.googleapis.com/auth/youtube']])->save();
    $t = flowTarget($this->yt, 'youtube_video');

    flowPublish($t, 'VID1');
    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Failed)->and($t->first_comment_error)->toContain('Reconecta')
        ->and($http->calls)->toBe([])->and($t->status)->toBe(PostStatus::Published);

    // El usuario reconecta (el permiso llega) y reintenta desde la web.
    $this->yt->forceFill(['scopes' => ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl']])->save();
    $this->actingAs($this->user)->post("/posts/{$t->post_id}/targets/{$t->id}/first-comment/retry")->assertRedirect();

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published)->and($t->fresh()->first_comment_external_id)->toBe('Ugx2');
});

test('comentarios cerrados en Instagram: no_admitido sin aviso y sin reintentos', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^MEDIA1/comments$#', ['data' => []])
        ->on('POST', '#^MEDIA1/comments$#', FakeGraph::error(100, 'Comments are disabled for this media.'));
    $t = flowTarget($this->ig, 'instagram_image');

    flowPublish($t, 'MEDIA1');

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::NotAllowed)->and($graph->callsTo('POST', '#/comments$#'))->toHaveCount(1);
    Notification::assertNothingSent();
});

test('editar el comentario fallido y reintentar envia el texto nuevo', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^MEDIA1/comments$#', ['data' => []])
        ->on('POST', '#^MEDIA1/comments$#', FakeGraph::seq(FakeGraph::error(10, '(#10) Application does not have permission', null, 403), ['id' => 'C2']));
    $t = flowTarget($this->ig, 'instagram_image', 'Texto viejo');

    flowPublish($t, 'MEDIA1');
    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Failed);

    $this->actingAs($this->user)->patch("/posts/{$t->post_id}/targets/{$t->id}/first-comment", ['first_comment' => 'Texto nuevo'])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post("/posts/{$t->post_id}/targets/{$t->id}/first-comment/retry")->assertSessionHasNoErrors();

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published)
        ->and($graph->callsTo('POST', '#/comments$#')[1]['params']['message'])->toBe('Texto nuevo');
});
